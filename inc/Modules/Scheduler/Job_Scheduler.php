<?php
/**
 * Action Scheduler facade that manages the full job lifecycle.
 *
 * @package OneSearch\Modules\Scheduler
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Scheduler;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Rest\Search_Controller;
use OneSearch\Modules\Schema\Job_Repository;
use OneSearch\Modules\Schema\Job_Schema;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - Job_Scheduler
 *
 * Bridges the domain model (Abstract_Job subclasses) with Action Scheduler's
 * async execution engine and WordPress's dual storage layer
 * (transients + wp_onesearch_index_jobs custom table).
 *
 * Storage strategy:
 *   - Active jobs (pending/running): stored in WordPress transients with a TTL.
 *     Each persist_job() call resets the expiration.
 *   - Terminal jobs (completed/failed/cancelled): transient is deleted; the
 *     custom table row (wp_onesearch_index_jobs) becomes the source of truth.
 *   - All jobs are written to wp_onesearch_index_jobs on every persist_job()
 *     call. Active jobs are queryable via status; terminal rows are permanent.
 *   - Action ID, action args, and child counters are stored as dedicated
 *     columns in the custom table.
 *
 * Execution flow:
 *   1. schedule($job) → persist (transient + table) + enqueue async action
 *   2. AS worker fires 'onesearch_execute_job' → execute_job() called
 *   3. execute_job() loads job, marks RUNNING (transient + table), calls handle()
 *   4. On success: mark_completed/keep RUNNING + notify_parent
 *   5. On terminal: delete transient, table row is now source of truth
 *   6. On failure: retry if allowed, otherwise fail permanently + notify_parent
 */
final class Job_Scheduler {
	/**
	 * The WordPress action hook that Action Scheduler fires to execute a job.
	 */
	public const HOOK = 'onesearch_execute_job';

	/**
	 * Prefix for transient keys storing active job state.
	 *
	 * Main transient key: "onesearch_job_status_{jobId}" — holds the full
	 * job payload for pending/running jobs. Deleted when the job reaches a
	 * terminal state (the custom table row is then the source of truth).
	 * Also used as a base for the spinlock key: "onesearch_job_status_{jobId}_lock".
	 *
	 * Note: action_id, action_args, and child counters are stored as dedicated
	 * columns in wp_onesearch_index_jobs, not as separate wp_options/transient keys.
	 */
	public const OPTION_PREFIX = 'onesearch_job_status_';

	/**
	 * Expiration time in seconds for transient job state.
	 *
	 * Active (pending/running) jobs are stored as transients with this TTL.
	 * Each persist_job() call resets the expiration, so only truly abandoned
	 * jobs expire. 12 hours = 43200 seconds.
	 */
	public const TRANSIENT_EXPIRATION = 43200;

	/**
	 * Job statuses that represent terminal (finished) states.
	 *
	 * When a job transitions to any of these, its transient is deleted and
	 * the custom table row (wp_onesearch_index_jobs) becomes the source of truth.
	 */
	public const TERMINAL_STATUSES = [
		Abstract_Job::STATUS_COMPLETED,
		Abstract_Job::STATUS_FAILED,
		Abstract_Job::STATUS_CANCELLED,
	];

	/**
	 * Guard to prevent registering WordPress hooks more than once.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Optional progress callback for real-time job monitoring.
	 *
	 * Signature: function(string $jobId, int $progress, string $status): void
	 *
	 * @var callable|null
	 */
	private $progress_callback = null;

	/**
	 * Data access layer for the custom jobs table.
	 *
	 * @var \OneSearch\Modules\Schema\Job_Repository
	 */
	private Job_Repository $repository;

	/**
	 * Create the scheduler.
	 *
	 * Registers WordPress action hooks on first instantiation. The static
	 * $hooks_registered guard ensures hooks are only added once.
	 */
	public function __construct() {
		// Fallback for contexts where Main::load() hasn't run (e.g. CLI, tests); no-ops otherwise.
		Job_Schema::maybe_upgrade();
		$this->repository = new Job_Repository();
		$this->register_hooks();
	}

	/**
	 * Set a callback to be invoked on every job status/progress change.
	 *
	 * @param callable $callback Signature: function(string $jobId, int $progress, string $status): void
	 * @return $this
	 */
	public function set_progress_callback( callable $callback ): self {
		$this->progress_callback = $callback;
		return $this;
	}

	/**
	 * Register WordPress action hooks for job execution and AS lifecycle events.
	 *
	 * Protected by static $hooks_registered to prevent double-registration.
	 */
	private function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		add_action( self::HOOK, [ $this, 'execute_job' ], 10, 3 );

		add_action( 'action_scheduler_failed_execution', [ $this, 'on_action_failed' ], 10, 3 );
		add_action( 'action_scheduler_failed_schedule_new_action', [ $this, 'on_schedule_failed' ], 10, 3 );
		add_action( 'action_scheduler_begin_execute', [ $this, 'on_action_begin' ], 10, 1 );
		add_action( 'action_scheduler_after_execute', [ $this, 'on_action_after' ], 10, 2 );
	}

	/**
	 * Schedule a job for immediate async execution via Action Scheduler.
	 *
	 * @param \OneSearch\Modules\Jobs\Abstract_Job $job The job to schedule. Modified (status set to PENDING).
	 * @return int The Action Scheduler action ID.
	 *
	 * @throws \RuntimeException If Action Scheduler is unavailable or returns an invalid ID.
	 */
	public function schedule( Abstract_Job $job ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			throw new \RuntimeException( 'Action Scheduler dependency missing.' );
		}

		$job->set_status( Abstract_Job::STATUS_PENDING );
		$this->persist_job( $job );

		$args = [
			'job_class' => get_class( $job ),
			'job_id'    => $job->get_id(),
		];

		$action_id = as_enqueue_async_action(
			self::HOOK,
			$args,
			'onesearch_' . $job->get_group()
		);

		if ( ! $action_id ) {
			$job->fail( 'Failed to enqueue action: Action Scheduler returned 0' );
			$this->persist_job( $job );
			throw new \RuntimeException(
				sprintf( 'Failed to schedule job %s: Action Scheduler returned invalid action ID.', esc_html( $job->get_id() ) )
			);
		}

		$this->repository->set_action( $job->get_id(), $action_id, $args );

		return $action_id;
	}

	/**
	 * Schedule a recurring job that re-enqueues at a fixed interval.
	 *
	 * @param \OneSearch\Modules\Jobs\Abstract_Job $job              The job to schedule repeatedly.
	 * @param int                                  $interval_seconds Seconds between each execution.
	 * @return int The Action Scheduler action ID.
	 *
	 * @throws \RuntimeException If Action Scheduler rejects the recurring action.
	 */
	public function schedule_recurring( Abstract_Job $job, int $interval_seconds ): int {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			throw new \RuntimeException( 'Action Scheduler dependency missing.' );
		}

		$job->set_status( Abstract_Job::STATUS_PENDING );
		$this->persist_job( $job );

		$args = [
			'job_class' => get_class( $job ),
			'job_id'    => $job->get_id(),
		];

		$action_id = as_schedule_recurring_action(
			time() + $interval_seconds,
			$interval_seconds,
			self::HOOK,
			$args,
			'onesearch_' . $job->get_group()
		);

		if ( ! $action_id ) {
			$job->fail( 'Failed to schedule recurring action' );
			$this->persist_job( $job );
			throw new \RuntimeException(
				sprintf( 'Failed to schedule recurring job %s.', esc_html( $job->get_id() ) )
			);
		}

		$this->repository->set_action( $job->get_id(), $action_id, $args );

		return $action_id;
	}

	/**
	 * Cancel a job and all its children in bulk.
	 *
	 * @param string $job_id The ID of the job to cancel.
	 */
	public function cancel( string $job_id ): void {
		global $wpdb;

		$status    = $this->get_status( $job_id );
		$cancelled = false;
		$child_ids = [];

		if ( $status ) {
			$group      = 'onesearch_' . ( $status['group'] ?? 'default' );
			$action_rec = $this->repository->get_action( $job_id );

			if ( $action_rec && function_exists( 'as_unschedule_action' ) ) {
				as_unschedule_action( self::HOOK, $action_rec['args'], $group );
			}
		}

		$key      = self::OPTION_PREFIX . $job_id;
		$lock_key = $key . '_lock';

		if ( ! $this->acquire_lock( $lock_key ) ) {
			return;
		}

		try {
			$fresh = $this->get_status( $job_id );
			if ( $fresh ) {
				$status = $fresh;
			}

			if ( $status ) {
				$previous_status = $status['status'] ?? '';
				$child_ids       = $status['child_ids'] ?? [];

				$has_unsuccessful = false;
				if ( Abstract_Job::STATUS_COMPLETED === $previous_status && ! empty( $child_ids ) ) {
					foreach ( $child_ids as $child_id ) {
						$cs = $this->get_status( $child_id );
						if ( $cs && ! in_array( $cs['status'] ?? '', [ Abstract_Job::STATUS_COMPLETED ], true ) ) {
							$has_unsuccessful = true;
							break;
						}
					}
				}

				if ( ! $has_unsuccessful && Abstract_Job::STATUS_COMPLETED === $previous_status ) {
					$remote_sites = $status['data']['sites'] ?? [];
					$current_site = Utils::normalize_url( get_site_url() );
					foreach ( $remote_sites as $site_info ) {
						if ( Utils::normalize_url( $site_info['site_url'] ?? '' ) !== $current_site ) {
							$has_unsuccessful = true;
							break;
						}
					}
				}

				$can_cancel = ! in_array( $previous_status, self::TERMINAL_STATUSES, true )
					|| ( Abstract_Job::STATUS_COMPLETED === $previous_status && $has_unsuccessful );

				if ( $can_cancel ) {
					$status['status']      = Abstract_Job::STATUS_CANCELLED;
					$status['finished_at'] = time();
					$status['updated_at']  = time();

					// Always reconcile against the atomic DB counters: a snapshot
					// (whether from the transient or a stale read) can lag behind
					// what notify_parent() incremented directly, so the counters are
					// the source of truth and prevent the upsert from regressing them.
					$counters                     = $this->repository->get_counters( $job_id );
					$status['children_cancelled'] = $counters['cancelled'];
					$status['children_failed']    = max( (int) ( $status['children_failed'] ?? 0 ), $counters['failed'] );
					$status['children_completed'] = $counters['done'];

					$this->repository->upsert( $status );
					delete_transient( $key );

					if ( ! empty( $child_ids ) ) {
						Search_Controller::clear_reindex_state();
					}

					$cancelled = true;
				}
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->options, [ 'option_name' => $lock_key ] );
			wp_cache_delete( $lock_key, 'options' );
		}

		if ( $cancelled && ! empty( $child_ids ) ) {
			$this->cancel_children( $job_id, $child_ids );
		}
	}

	/**
	 * Cancel all pending/running child jobs of a parent in bulk.
	 *
	 * Reindex_Job children all share a single Action Scheduler group
	 * ("onesearch_reindex_{parent_id}"), so one as_unschedule_all_actions()
	 * call replaces N individual as_unschedule_action() calls. The DB update
	 * is also batched into a single UPDATE … WHERE id IN (…) statement.
	 * Completed or already-cancelled children are left untouched.
	 *
	 * @param string   $parent_id Job ID of the parent (used to derive the AS group).
	 * @param string[] $child_ids Job IDs of all children registered on the parent.
	 */
	private function cancel_children( string $parent_id, array $child_ids ): void {
		if ( empty( $child_ids ) ) {
			return;
		}

		// Children share one AS group: onesearch_reindex_{parent_id}.
		$child_as_group = 'onesearch_reindex_' . $parent_id;
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], $child_as_group );
		}

		$now = time();
		$this->repository->batch_cancel( $child_ids, $now );

		foreach ( $child_ids as $child_id ) {
			delete_transient( self::OPTION_PREFIX . $child_id );
		}
	}

	/**
	 * Retrieve the stored job state using the dual storage strategy.
	 *
	 * Checks transients first (where active pending/running jobs live),
	 * then falls back to the custom table (where terminal jobs are stored).
	 *
	 * @param string $job_id The job ID to look up.
	 * @return array<string, mixed>|null The job state, or null if not found.
	 */
	public function get_status( string $job_id ): ?array {
		$key = self::OPTION_PREFIX . $job_id;

		$data = get_transient( $key );
		if ( false !== $data && is_array( $data ) ) {
			return $data;
		}

		return $this->repository->get_by_id( $job_id );
	}

	/**
	 * Get all jobs in a given Action Scheduler group.
	 *
	 * @param string $group The group name (without "onesearch_" prefix).
	 * @return array<int, array<string, mixed>> Array of job state arrays.
	 */
	public function get_jobs_by_group( string $group ): array {
		$actions = as_get_scheduled_actions(
			[
				'group'    => 'onesearch_' . $group,
				'status'   => [ \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ],
				'per_page' => -1,
			]
		);

		$jobs = [];
		foreach ( $actions as $action ) {
			$args = $action->get_args();
			if ( isset( $args['job_id'] ) ) {
				$job_status = $this->get_status( $args['job_id'] );
				if ( $job_status ) {
					$jobs[] = $job_status;
				}
			}
		}

		return $jobs;
	}

	/**
	 * Schedule a retry for a failed job with exponential backoff.
	 *
	 * @param \OneSearch\Modules\Jobs\Abstract_Job $job The failed job to retry.
	 * @return int The Action Scheduler action ID for the retry.
	 *
	 * @throws \RuntimeException If Action Scheduler rejects the retry action.
	 */
	public function schedule_retry( Abstract_Job $job ): int {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			throw new \RuntimeException( 'Action Scheduler dependency missing.' );
		}

		$job->set_status( Abstract_Job::STATUS_PENDING );
		$this->persist_job( $job );

		$delay = $job->get_retry_delay_seconds() * (int) pow( 2, $job->get_retry_count() - 1 );

		$args = [
			'job_class' => get_class( $job ),
			'job_id'    => $job->get_id(),
			'retry'     => $job->get_retry_count(),
		];

		$action_id = as_schedule_single_action(
			time() + $delay,
			self::HOOK,
			$args,
			'onesearch_' . $job->get_group()
		);

		if ( ! $action_id ) {
			$job->fail( 'Failed to schedule retry action' );
			$this->persist_job( $job );
			throw new \RuntimeException(
				sprintf( 'Failed to schedule retry for job %s.', esc_html( $job->get_id() ) )
			);
		}

		$this->repository->set_action( $job->get_id(), $action_id, $args );

		return $action_id;
	}

	/**
	 * Execute a job when Action Scheduler fires the 'onesearch_execute_job' hook.
	 *
	 * @param string $job_class FQCN of the job class.
	 * @param string $job_id    Unique job identifier.
	 * @param int    $retry     Current retry attempt number (0 on first run).
	 *
	 * @throws \InvalidArgumentException If job_class or job_id is missing/invalid.
	 */
	public function execute_job( string $job_class, string $job_id, int $retry = 0 ): void {
		if ( ! $job_class || ! $job_id || ! class_exists( $job_class ) ) {
			throw new \InvalidArgumentException( 'Invalid job arguments: missing job_class or job_id.' );
		}

		if ( ! is_a( $job_class, Abstract_Job::class, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Job class "%s" must extend Abstract_Job.', esc_html( $job_class ) )
			);
		}

		$stored = $this->get_status( $job_id );
		if ( ! $stored ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[OneSearch] Job %s not found in storage — action will be skipped.',
					esc_html( $job_id )
				)
			);
			return;
		}

		/** @var \OneSearch\Modules\Jobs\Abstract_Job $job */
		$job = $job_class::from_array( $stored );

		if ( $job->get_status() === Abstract_Job::STATUS_CANCELLED ) {
			return;
		}

		$job->mark_running();
		$this->persist_job( $job );
		$this->notify_progress( $job );

		try {
			$job->handle();

			// Re-check DB status: cancel() may have set this job to cancelled
			// while handle() was running. If so, respect the cancellation and
			// don't overwrite it with completed/running status.
			$current = $this->repository->get_by_id( $job_id );
			if ( $current && Abstract_Job::STATUS_CANCELLED === ( $current['status'] ?? '' ) ) {
				$job->set_status( Abstract_Job::STATUS_CANCELLED );
				$this->persist_job( $job );
				return;
			}

			if ( $job->has_pending_children() ) {
				$job->set_status( Abstract_Job::STATUS_RUNNING );
			} else {
				$job->mark_completed();
			}
			$this->persist_job( $job );
			$this->notify_progress( $job );
			$this->notify_parent( $job );
		} catch ( \Throwable $e ) {
			$job->set_retry_count( $retry + 1 );

			// Re-check DB status: cancel() may have set this job to cancelled
			// while handle() was running. If so, respect the cancellation.
			$current = $this->repository->get_by_id( $job_id );
			if ( $current && Abstract_Job::STATUS_CANCELLED === ( $current['status'] ?? '' ) ) {
				$job->set_status( Abstract_Job::STATUS_CANCELLED );
				$this->persist_job( $job );
				return;
			}

			if ( $job->should_retry() ) {
				$job->fail( $e->getMessage() );
				$this->persist_job( $job );
				$this->schedule_retry( $job );
				return;
			}

			$job->fail( $e->getMessage() . ' (retries exhausted: ' . $job->get_retry_count() . '/' . $job->get_max_retries() . ')' );
			$this->persist_job( $job );
			$this->notify_progress( $job );
			$this->notify_parent( $job );

			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[OneSearch] Job %s failed permanently: %s',
					$job->get_id(),
					$job->get_error()
				)
			);
		}
	}

	/**
	 * Log when Action Scheduler itself fails to execute an action.
	 *
	 * @param int        $action_id The AS action ID that failed.
	 * @param \Throwable $exception The exception from Action Scheduler.
	 * @param string     $context   Execution context.
	 */
	public function on_action_failed( int $action_id, \Throwable $exception, string $context ): void {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'[OneSearch] Action %d failed in context "%s": %s',
				$action_id,
				$context,
				$exception->getMessage()
			)
		);
	}

	/**
	 * Log when Action Scheduler fails to schedule a new action.
	 *
	 * @param int        $action_id The AS action ID involved.
	 * @param \Throwable $exception The scheduling exception.
	 * @param string     $context   Scheduling context.
	 */
	public function on_schedule_failed( int $action_id, \Throwable $exception, string $context ): void {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'[OneSearch] Failed to schedule action %d in context "%s": %s',
				$action_id,
				$context,
				$exception->getMessage()
			)
		);
	}

	/**
	 * Called just before Action Scheduler begins processing an action.
	 *
	 * @param int $action_id The AS action ID about to execute.
	 */
	public function on_action_begin( int $action_id ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		// No-op. Available for future logging/monitoring hooks.
	}

	/**
	 * Called after Action Scheduler finishes executing an action.
	 *
	 * @param int                     $action_id The AS action ID that just executed.
	 * @param \ActionScheduler_Action $action    The action that was executed.
	 */
	public function on_action_after( int $action_id, $action ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		// No-op. Available for future logging/monitoring hooks.
	}

	/**
	 * Persist the full job state.
	 *
	 * Every call writes to the custom table (so active jobs are enumerable).
	 * Active jobs additionally get a transient for fast reads; terminal jobs
	 * have their transient deleted since the table row is now the source of truth.
	 *
	 * @param \OneSearch\Modules\Jobs\Abstract_Job $job The job whose state to persist.
	 */
	public function persist_job( Abstract_Job $job ): void {
		$key  = self::OPTION_PREFIX . $job->get_id();
		$data = $job->to_array();

		$this->repository->upsert( $data );

		if ( in_array( $job->get_status(), self::TERMINAL_STATUSES, true ) ) {
			delete_transient( $key );
		} else {
			set_transient( $key, $data, self::TRANSIENT_EXPIRATION );
		}
	}

	/**
	 * Notify a parent job that one of its children has completed.
	 *
	 * Uses three atomic SQL counters (children_done, children_failed,
	 * children_cancelled) to prevent race conditions when multiple children
	 * complete simultaneously. A spinlock with retries guards the parent data
	 * write to prevent concurrent overwrites while ensuring no notifications
	 * are silently dropped.
	 *
	 * Remote site status checks are performed OUTSIDE the lock to prevent
	 * blocking cancel() operations during slow API calls.
	 *
	 * @param \OneSearch\Modules\Jobs\Abstract_Job $job The child job that just completed or failed.
	 */
	private function notify_parent( Abstract_Job $job ): void {
		global $wpdb;

		$parent_id = $job->get_parent_id();
		if ( ! $parent_id ) {
			return;
		}

		$parent_data = $this->get_status( $parent_id );
		if ( ! $parent_data ) {
			return;
		}

		$job_status   = $job->get_status();
		$is_failed    = Abstract_Job::STATUS_FAILED === $job_status;
		$is_cancelled = Abstract_Job::STATUS_CANCELLED === $job_status;

		$this->repository->increment_counter( $parent_id, 'children_done' );

		if ( $is_failed ) {
			$this->repository->increment_counter( $parent_id, 'children_failed' );
		}

		if ( $is_cancelled ) {
			$this->repository->increment_counter( $parent_id, 'children_cancelled' );
			$this->repository->increment_counter( $parent_id, 'children_failed' );
		}

		$counters        = $this->repository->get_counters( $parent_id );
		$done            = $counters['done'];
		$total_failed    = $counters['failed'];
		$total_cancelled = $counters['cancelled'];
		$child_total     = count( $parent_data['child_ids'] ?? [] );

		$parent_key = self::OPTION_PREFIX . $parent_id;
		$lock_key   = $parent_key . '_lock';

		// Check if all local children are done (fast check, no lock needed yet).
		$all_local_done = $child_total > 0 && $done >= $child_total;

		// If all local children are done and this is a governing site with remote
		// sites, check remote statuses BEFORE acquiring the lock. This prevents
		// blocking cancel() during slow remote API calls.
		$remote_status = null;
		if ( $all_local_done && Settings::is_governing_site() ) {
			$remote_sites = $parent_data['data']['sites'] ?? [];
			if ( is_array( $remote_sites ) && count( $remote_sites ) > 1 ) {
				$remote_status = $this->check_remote_job_statuses( $remote_sites );
			}
		}

		// Acquire the lock for the parent status update (with expiry so a crashed
		// process cannot orphan the lock indefinitely).
		if ( ! $this->acquire_lock( $lock_key ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[OneSearch] notify_parent: Could not acquire lock for parent %s. Skipping notification.',
					esc_html( $parent_id )
				)
			);
			return;
		}

		try {
			$fresh = $this->get_status( $parent_id );
			if ( $fresh ) {
				$parent_data = $fresh;
			}

			if ( in_array( $parent_data['status'] ?? '', self::TERMINAL_STATUSES, true ) ) {
				return;
			}

			$parent_data['children_completed'] = $done;
			$parent_data['children_failed']    = $total_failed + $total_cancelled;
			$parent_data['progress']           = min( $done, $parent_data['progress_total'] ?? $done );
			$parent_data['updated_at']         = time();

			if ( $all_local_done ) {
				// Incorporate remote status if we checked it earlier.
				if ( null !== $remote_status ) {
					$total_failed                  += $remote_status['failed'];
					$total_cancelled               += $remote_status['cancelled'];
					$parent_data['children_failed'] = $total_failed + $total_cancelled;

					// If any remote site is still running, defer finalization.
					if ( $remote_status['running'] > 0 ) {
						$parent_data['data']['_needs_remote_finalize'] = true;
						$parent_data['status']                         = Abstract_Job::STATUS_RUNNING;
						$this->repository->upsert( $parent_data );
						set_transient( $parent_key, $parent_data, self::TRANSIENT_EXPIRATION );
						return;
					}
				}

				// Cancelled beats failed beats completed.
				if ( $total_cancelled > 0 ) {
					$parent_data['status'] = Abstract_Job::STATUS_CANCELLED;
				} elseif ( $total_failed > 0 ) {
					$parent_data['status'] = Abstract_Job::STATUS_FAILED;
					$parent_data['error']  = sprintf( '%d/%d child batches failed', $total_failed, $child_total );
				} else {
					$parent_data['status'] = Abstract_Job::STATUS_COMPLETED;
				}
				$parent_data['progress']    = $parent_data['progress_total'];
				$parent_data['finished_at'] = time();
				Search_Controller::clear_reindex_state();
			}

			$this->repository->upsert( $parent_data );

			if ( in_array( $parent_data['status'], self::TERMINAL_STATUSES, true ) ) {
				delete_transient( $parent_key );
			} else {
				set_transient( $parent_key, $parent_data, self::TRANSIENT_EXPIRATION );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->options, [ 'option_name' => $lock_key ] );
			wp_cache_delete( $lock_key, 'options' );
		}
	}

	/**
	 * Build a normalized-URL → API-key map from the configured shared sites.
	 *
	 * Read once per remote operation so per-site lookups don't repeatedly
	 * hit the option store and re-decrypt every key inside a loop.
	 *
	 * @return array<string, string> Map of normalized site URL → API key.
	 */
	private function get_shared_site_keys(): array {
		$keys = [];
		foreach ( Settings::get_shared_sites() as $url => $data ) {
			$keys[ Utils::normalize_url( $url ) ] = $data['api_key'] ?? '';
		}
		return $keys;
	}

	/**
	 * Fetch a remote child site's job payload over the REST API.
	 *
	 * @param string                $site_url  Normalized remote site URL.
	 * @param string                $job_id    Remote job ID.
	 * @param array<string, string> $site_keys Map of normalized URL → API key.
	 * @return array<string, mixed>|null The decoded `job` payload, or null on any failure
	 *                                    (missing key, transport error, non-200, malformed body).
	 */
	private function fetch_remote_job( string $site_url, string $job_id, array $site_keys ): ?array {
		$api_key = $site_keys[ $site_url ] ?? '';
		if ( empty( $api_key ) ) {
			return null;
		}

		$response = wp_safe_remote_get(
			sprintf(
				'%s/wp-json/%s/jobs/%s',
				untrailingslashit( $site_url ),
				'onesearch/v1',
				rawurlencode( $job_id )
			),
			[
				'headers' => [
					'Content-Type'      => 'application/json',
					'Origin'            => get_site_url(),
					'X-OneSearch-Token' => $api_key,
				],
				'timeout' => 5, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! isset( $data['job'] ) || ! is_array( $data['job'] ) ) {
			return null;
		}

		return $data['job'];
	}

	/**
	 * Check the status of remote child site jobs and count failures/cancellations.
	 *
	 * Called by notify_parent() when all local children complete to verify
	 * that child site jobs also succeeded before marking the parent complete.
	 *
	 * @param array<int,array{site_url:string,job_id:string}> $remote_sites Array of remote site jobs.
	 * @return array{failed:int,cancelled:int,running:int}
	 */
	public function check_remote_job_statuses( array $remote_sites ): array {
		$failed       = 0;
		$cancelled    = 0;
		$running      = 0;
		$current_site = Utils::normalize_url( get_site_url() );
		$site_keys    = $this->get_shared_site_keys();

		foreach ( $remote_sites as $site_info ) {
			$site_url = Utils::normalize_url( $site_info['site_url'] ?? '' );
			$job_id   = $site_info['job_id'] ?? '';

			if ( $site_url === $current_site || empty( $job_id ) ) {
				continue;
			}

			$job_data = $this->fetch_remote_job( $site_url, $job_id, $site_keys );

			// An unreachable/unauthorized site can't be confirmed successful, so
			// it counts as failed (matching the prior behaviour).
			if ( null === $job_data ) {
				++$failed;
				continue;
			}

			$remote_status = $job_data['status'] ?? '';

			if ( 'failed' === $remote_status ) {
				++$failed;
			} elseif ( 'cancelled' === $remote_status ) {
				++$cancelled;
			} elseif ( in_array( $remote_status, [ 'pending', 'running' ], true ) ) {
				++$running;
			}
		}

		return [
			'failed'    => $failed,
			'cancelled' => $cancelled,
			'running'   => $running,
		];
	}

	/**
	 * Fetch completed batch counts from remote child site jobs.
	 *
	 * Called by hydrate_history_job() to aggregate progress across all sites.
	 *
	 * @param array<int,array{site_url:string,job_id:string,batch_count?:int}> $remote_sites Array of remote site jobs.
	 * @return array{completed:int,terminal:int,total:int} Sum of completed batches, terminal batches, and total batches from remote sites.
	 */
	public function fetch_remote_job_progress( array $remote_sites ): array {
		$completed    = 0;
		$terminal     = 0;
		$total        = 0;
		$current_site = Utils::normalize_url( get_site_url() );
		$site_keys    = $this->get_shared_site_keys();

		foreach ( $remote_sites as $site_info ) {
			$site_url = Utils::normalize_url( $site_info['site_url'] ?? '' );
			$job_id   = $site_info['job_id'] ?? '';

			if ( $site_url === $current_site || empty( $job_id ) ) {
				continue;
			}

			$job_data = $this->fetch_remote_job( $site_url, $job_id, $site_keys );

			if ( null === $job_data ) {
				continue;
			}

			$job_completed = (int) ( $job_data['children_completed'] ?? $job_data['progress'] ?? 0 );
			$job_failed    = (int) ( $job_data['children_failed'] ?? 0 );
			$job_cancelled = (int) ( $job_data['children_cancelled'] ?? 0 );

			$completed += $job_completed;
			$terminal  += $job_completed + $job_failed + $job_cancelled;
			$total     += (int) ( $job_data['children_total'] ?? $job_data['progress_total'] ?? 0 );
		}

		return [
			'completed' => $completed,
			'terminal'  => $terminal,
			'total'     => $total,
		];
	}

	/**
	 * Acquire a spinlock stored in wp_options with a 60-second expiry lease.
	 *
	 * Uses INSERT IGNORE for the fast path (lock is free). If the row already
	 * exists, reads the stored expiry timestamp and overwrites the lock when it
	 * has passed — this recovers from processes that were killed before their
	 * finally block could delete the row. Returns true when the caller owns the
	 * lock, false if all attempts were exhausted against a live holder.
	 *
	 * @param string $lock_key The wp_options option_name to use as the lock key.
	 * @return bool True if the lock was acquired, false otherwise.
	 */
	private function acquire_lock( string $lock_key ): bool {
		global $wpdb;

		$max_tries   = 10;
		$lock_expiry = time() + 60;

		for ( $attempt = 0; $attempt < $max_tries; ++$attempt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$acquired = (bool) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')",
					$lock_key,
					$lock_expiry
				)
			);

			if ( $acquired ) {
				wp_cache_delete( $lock_key, 'options' );
				return true;
			}

			// Lock exists — check whether it belongs to a crashed process.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$stored = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					$lock_key
				)
			);

			if ( $stored < time() ) {
				// Expired: overwrite (last-writer-wins is safe — the previous holder is gone).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->options,
					[ 'option_value' => $lock_expiry ],
					[ 'option_name' => $lock_key ]
				);
				wp_cache_delete( $lock_key, 'options' );
				return true;
			}

			usleep( 100000 + $attempt * 50000 ); // 100ms base, +50ms per attempt.
		}

		return false;
	}

	/**
	 * Fire the registered progress callback, if any.
	 *
	 * @param \OneSearch\Modules\Jobs\Abstract_Job $job The job whose progress changed.
	 */
	private function notify_progress( Abstract_Job $job ): void {
		if ( $this->progress_callback ) {
			call_user_func( $this->progress_callback, $job->get_id(), $job->get_progress(), $job->get_status() );
		}
	}

	/**
	 * Get all active job IDs (pending or running) from the custom table.
	 *
	 * @return string[] Array of job IDs currently in pending or running state.
	 */
	public function get_active_job_ids(): array {
		return $this->repository->get_active_job_ids();
	}
}
