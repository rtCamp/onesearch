<?php
/**
 * Action Scheduler facade that manages the full job lifecycle.
 *
 * @package OneSearch\Modules\Scheduler
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Scheduler;

use OneSearch\Modules\Jobs\AbstractJob;
use OneSearch\Modules\Rest\Search_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - JobScheduler
 *
 * Bridges the domain model (AbstractJob subclasses) with Action Scheduler's
 * async execution engine and WordPress's dual storage layer
 * (transients + wp_options).
 *
 * Storage strategy:
 *   - Active jobs (pending/running): stored in WordPress transients with a TTL.
 *     Each persist_job() call resets the expiration.
 *   - Terminal jobs (completed/failed/cancelled): moved to wp_options for
 *     permanent storage. The transient is deleted and the active index updated.
 *   - An active jobs index (onesearch_active_jobs) tracks which job IDs are
 *     in transient storage for enumeration without DB pattern matching.
 *   - Auxiliary keys (_action_id, _action_args, _children_done) remain in
 *     wp_options regardless of job status.
 *
 * Execution flow:
 *   1. schedule($job) → persist (transient) + enqueue async action
 *   2. AS worker fires 'onesearch_execute_job' → execute_job() called
 *   3. execute_job() loads job, marks RUNNING (transient), calls handle()
 *   4. On success: markCompleted/keep RUNNING + notify_parent
 *   5. On terminal: move from transient → wp_options, update active index
 *   6. On failure: retry if allowed, otherwise fail permanently + notify_parent
 */
final class JobScheduler {
	/**
	 * The WordPress action hook that Action Scheduler fires to execute a job.
	 */
	public const HOOK = 'onesearch_execute_job';

	/**
	 * Prefix for wp_options keys storing job state.
	 *
	 * Full key format: "onesearch_job_status_{jobId}" for the main job state.
	 * Additional keys: "onesearch_job_status_{jobId}_action_id",
	 * "onesearch_job_status_{jobId}_action_args",
	 * "onesearch_job_status_{jobId}_children_done".
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
	 * Option key for the active job ID index.
	 *
	 * Stores an array of job IDs currently in pending or running state.
	 */
	public const ACTIVE_JOBS_OPTION = 'onesearch_active_jobs';

	/**
	 * Job statuses that represent terminal (finished) states.
	 *
	 * When a job transitions to any of these, its data is moved
	 * from transient to wp_options for permanent storage.
	 */
	public const TERMINAL_STATUSES = [
		AbstractJob::STATUS_COMPLETED,
		AbstractJob::STATUS_FAILED,
		AbstractJob::STATUS_CANCELLED,
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
	 * Create the scheduler.
	 *
	 * Registers WordPress action hooks on first instantiation. The static
	 * $hooks_registered guard ensures hooks are only added once.
	 */
	public function __construct() {
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
	 * @param \OneSearch\Modules\Jobs\AbstractJob $job               The job to schedule. Modified (status set to PENDING).
	 * @param bool                                $skip_active_index Skip adding to active index (caller batches manually).
	 * @return int The Action Scheduler action ID.
	 *
	 * @throws \RuntimeException If Action Scheduler is unavailable or returns an invalid ID.
	 */
	public function schedule( AbstractJob $job, bool $skip_active_index = false ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			throw new \RuntimeException( 'Action Scheduler dependency missing.' );
		}

		$job->set_status( AbstractJob::STATUS_PENDING );
		$this->persist_job( $job, $skip_active_index );

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

		update_option( self::OPTION_PREFIX . $job->get_id() . '_action_id', $action_id, false );
		update_option( self::OPTION_PREFIX . $job->get_id() . '_action_args', $args, false );

		return $action_id;
	}

	/**
	 * Schedule a recurring job that re-enqueues at a fixed interval.
	 *
	 * @param \OneSearch\Modules\Jobs\AbstractJob $job              The job to schedule repeatedly.
	 * @param int                                 $interval_seconds Seconds between each execution.
	 * @return int The Action Scheduler action ID.
	 *
	 * @throws \RuntimeException If Action Scheduler rejects the recurring action.
	 */
	public function schedule_recurring( AbstractJob $job, int $interval_seconds ): int {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			throw new \RuntimeException( 'Action Scheduler dependency missing.' );
		}

		$job->set_status( AbstractJob::STATUS_PENDING );
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

		update_option( self::OPTION_PREFIX . $job->get_id() . '_action_id', $action_id, false );
		update_option( self::OPTION_PREFIX . $job->get_id() . '_action_args', $args, false );

		return $action_id;
	}

	/**
	 * Cancel a job and all its children recursively.
	 *
	 * @param string $job_id The ID of the job to cancel.
	 */
	public function cancel( string $job_id ): void {
		global $wpdb;

		$status = $this->get_status( $job_id );

		if ( $status ) {
			$group     = 'onesearch_' . ( $status['group'] ?? 'default' );
			$action_id = get_option( self::OPTION_PREFIX . $job_id . '_action_id', 0 );

			if ( $action_id && function_exists( 'as_unschedule_action' ) ) {
				$args = get_option( self::OPTION_PREFIX . $job_id . '_action_args', [] );
				as_unschedule_action( self::HOOK, $args, $group );
			}
		}

		// Acquire per-job lock to prevent notify_parent() from overwriting
		// our cancellation with a completed/failed status.
		$key      = self::OPTION_PREFIX . $job_id;
		$lock_key = $key . '_lock';

		$max_tries = 10;
		for ( $attempt = 0; $attempt < $max_tries; ++$attempt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$acquired = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')",
					$lock_key
				)
			);
			if ( $acquired ) {
				wp_cache_delete( $lock_key, 'options' );
				break;
			}
			usleep( 100000 + $attempt * 50000 );
		}

		try {
			// Re-read status under lock.
			$fresh = $this->get_status( $job_id );
			if ( $fresh ) {
				$status = $fresh;
			}

			if ( $status ) {
				$previous_status = $status['status'] ?? '';
				$child_ids       = $status['child_ids'] ?? [];

				// Allow cancelling a completed parent if it has unsuccessful
				// children — the user explicitly cancelled.
				$has_unsuccessful = false;
				if ( AbstractJob::STATUS_COMPLETED === $previous_status && ! empty( $child_ids ) ) {
					foreach ( $child_ids as $child_id ) {
						$cs = $this->get_status( $child_id );
						if ( $cs && ! in_array( $cs['status'] ?? '', [ AbstractJob::STATUS_COMPLETED ], true ) ) {
							$has_unsuccessful = true;
							break;
						}
					}
				}

				// Also treat completed parent with remote sites as potentially
				// needing cancellation — remote status is uncertain.
				if ( ! $has_unsuccessful && AbstractJob::STATUS_COMPLETED === $previous_status ) {
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
					|| ( AbstractJob::STATUS_COMPLETED === $previous_status && $has_unsuccessful );

				if ( $can_cancel ) {
					$status['status']      = AbstractJob::STATUS_CANCELLED;
					$status['finished_at'] = time();
					$status['updated_at']  = time();

					update_option( $key, $status, false );
					delete_transient( $key );
					$this->remove_from_active_index( $job_id );

					if ( ! empty( $child_ids ) ) {
						Search_Controller::clear_reindex_state();
					}
				}
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->options, [ 'option_name' => $lock_key ] );
			wp_cache_delete( $lock_key, 'options' );
		}

		// Always recursively cancel children.
		if ( $status ) {
			$child_ids = $status['child_ids'] ?? [];
			foreach ( $child_ids as $child_id ) {
				$this->cancel( $child_id );
			}
		}
	}

	/**
	 * Retrieve the stored job state using the dual storage strategy.
	 *
	 * Checks transients first (where active pending/running jobs live),
	 * then falls back to wp_options (where terminal jobs are stored).
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

		$data = get_option( $key, null );
		if ( null !== $data ) {
			return $data;
		}

		return null;
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
				'per_page' => 0,
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
	 * @param \OneSearch\Modules\Jobs\AbstractJob $job The failed job to retry.
	 * @return int The Action Scheduler action ID for the retry.
	 *
	 * @throws \RuntimeException If Action Scheduler rejects the retry action.
	 */
	public function schedule_retry( AbstractJob $job ): int {
		$job->set_status( AbstractJob::STATUS_PENDING );
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

		update_option( self::OPTION_PREFIX . $job->get_id() . '_action_id', $action_id, false );
		update_option( self::OPTION_PREFIX . $job->get_id() . '_action_args', $args, false );

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

		/** @var \OneSearch\Modules\Jobs\AbstractJob $job */
		$job = $job_class::from_array( $stored );

		if ( $job->get_status() === AbstractJob::STATUS_CANCELLED ) {
			return;
		}

		$job->mark_running();
		$this->persist_job( $job );
		$this->notify_progress( $job );

		try {
			$job->handle();

			if ( $job->has_pending_children() ) {
				$job->set_status( AbstractJob::STATUS_RUNNING );
			} else {
				$job->mark_completed();
			}
			$this->persist_job( $job );
			$this->notify_progress( $job );
			$this->notify_parent( $job );
		} catch ( \Throwable $e ) {
			$job->set_retry_count( $retry + 1 );

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
	 * Persist the full job state using the dual storage strategy.
	 *
	 * @param \OneSearch\Modules\Jobs\AbstractJob $job               The job whose state to persist.
	 * @param bool                                $skip_active_index Skip updating the active jobs index.
	 */
	public function persist_job( AbstractJob $job, bool $skip_active_index = false ): void {
		$key  = self::OPTION_PREFIX . $job->get_id();
		$data = $job->to_array();

		if ( in_array( $job->get_status(), self::TERMINAL_STATUSES, true ) ) {
			update_option( $key, $data, false );
			delete_transient( $key );
			$this->remove_from_active_index( $job->get_id() );
		} else {
			set_transient( $key, $data, self::TRANSIENT_EXPIRATION );
			if ( ! $skip_active_index ) {
				$this->add_to_active_index( $job->get_id() );
			}
		}
	}

	/**
	 * Notify a parent job that one of its children has completed.
	 *
	 * Uses an atomic SQL counter for _children_done to prevent race conditions
	 * when multiple children complete simultaneously. A spinlock with retries
	 * guards the parent data write to prevent concurrent overwrites while
	 * ensuring no notifications are silently dropped.
	 *
	 * @param \OneSearch\Modules\Jobs\AbstractJob $job The child job that just completed or failed.
	 */
	private function notify_parent( AbstractJob $job ): void {
		global $wpdb;

		$parent_id = $job->get_parent_id();
		if ( ! $parent_id ) {
			return;
		}

		$parent_data = $this->get_status( $parent_id );
		if ( ! $parent_data ) {
			return;
		}

		$counter_key           = self::OPTION_PREFIX . $parent_id . '_children_done';
		$counter_failed_key    = self::OPTION_PREFIX . $parent_id . '_children_failed';
		$counter_cancelled_key = self::OPTION_PREFIX . $parent_id . '_children_cancelled';
		$job_status            = $job->get_status();
		$is_failed             = AbstractJob::STATUS_FAILED === $job_status;
		$is_cancelled          = AbstractJob::STATUS_CANCELLED === $job_status;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
				$counter_key
			)
		);

		if ( $is_failed || $is_cancelled ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
					$counter_failed_key
				)
			);
		}

		if ( $is_cancelled ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
					$counter_cancelled_key
				)
			);
		}

		wp_cache_delete( $counter_key, 'options' );
		$done            = (int) get_option( $counter_key, 0 );
		$total_failed    = (int) get_option( $counter_failed_key, 0 );
		$total_cancelled = (int) get_option( $counter_cancelled_key, 0 );
		$child_total     = count( $parent_data['child_ids'] ?? [] );

		$parent_key = self::OPTION_PREFIX . $parent_id;
		$lock_key   = $parent_key . '_lock';

		// Spinlock: use atomic INSERT IGNORE to avoid race conditions
		// that set_transient() cannot prevent.
		$max_tries = 10;
		for ( $attempt = 0; $attempt < $max_tries; ++$attempt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$acquired = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')",
					$lock_key
				)
			);
			if ( $acquired ) {
				wp_cache_delete( $lock_key, 'options' );
				break;
			}
			usleep( 100000 + $attempt * 50000 ); // 100ms base, +50ms per attempt.
		}

		// If lock was not acquired after all retries, proceed anyway —
		// a race here is preferable to permanently losing the notification.

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

			if ( $child_total > 0 && $done >= $child_total ) {
				// All local children finished. If governing site with remote
				// child sites, check their statuses before finalizing.
				$remote_sites = $parent_data['data']['sites'] ?? [];
				$has_remote   = is_array( $remote_sites ) && count( $remote_sites ) > 1;

				if ( $has_remote && Settings::is_governing_site() ) {
					$remote_status                  = $this->check_remote_job_statuses( $remote_sites );
					$total_failed                  += $remote_status['failed'];
					$total_cancelled               += $remote_status['cancelled'];
					$parent_data['children_failed'] = $total_failed + $total_cancelled;

					// If any remote site is still running, defer finalization.
					// The parent stays RUNNING so the frontend keeps polling.
					// hydrate_history_job() will re-check and finalize later.
					if ( $remote_status['running'] > 0 ) {
						$parent_data['data']['_needs_remote_finalize'] = true;
						$parent_data['status']                         = AbstractJob::STATUS_RUNNING;

						if ( in_array( $parent_data['status'], self::TERMINAL_STATUSES, true ) ) {
							update_option( $parent_key, $parent_data, false );
						} else {
							set_transient( $parent_key, $parent_data, self::TRANSIENT_EXPIRATION );
						}
						return;
					}
				}

				// Cancelled beats failed beats completed.
				if ( $total_cancelled > 0 ) {
					$parent_data['status'] = AbstractJob::STATUS_CANCELLED;
				} elseif ( $total_failed > 0 ) {
					$parent_data['status'] = AbstractJob::STATUS_FAILED;
					$parent_data['error']  = sprintf( '%d/%d child batches failed', $total_failed, $child_total );
				} else {
					$parent_data['status'] = AbstractJob::STATUS_COMPLETED;
				}
				$parent_data['progress']    = $parent_data['progress_total'];
				$parent_data['finished_at'] = time();
				delete_option( $counter_key );
				delete_option( $counter_failed_key );
				delete_option( $counter_cancelled_key );
				Search_Controller::clear_reindex_state();
			}

			if ( in_array( $parent_data['status'], self::TERMINAL_STATUSES, true ) ) {
				update_option( $parent_key, $parent_data, false );
				delete_transient( $parent_key );
				$this->remove_from_active_index( $parent_id );
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

		foreach ( $remote_sites as $site_info ) {
			$site_url = Utils::normalize_url( $site_info['site_url'] ?? '' );
			$job_id   = $site_info['job_id'] ?? '';

			if ( $site_url === $current_site || empty( $job_id ) ) {
				continue;
			}

			$shared_sites = Settings::get_shared_sites();
			$api_key      = '';

			foreach ( $shared_sites as $url => $data ) {
				if ( Utils::normalize_url( $url ) === $site_url ) {
					$api_key = $data['api_key'] ?? '';
					break;
				}
			}

			if ( empty( $api_key ) ) {
				++$failed;
				continue;
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
				++$failed;
				continue;
			}

			$body          = wp_remote_retrieve_body( $response );
			$data          = json_decode( $body, true );
			$remote_status = $data['job']['status'] ?? '';

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
	 * Fire the registered progress callback, if any.
	 *
	 * @param \OneSearch\Modules\Jobs\AbstractJob $job The job whose progress changed.
	 */
	private function notify_progress( AbstractJob $job ): void {
		if ( $this->progress_callback ) {
			call_user_func( $this->progress_callback, $job->get_id(), $job->get_progress(), $job->get_status() );
		}
	}

	/**
	 * Add a job ID to the active jobs index.
	 *
	 * @param string $job_id The job ID to add.
	 */
	public function add_to_active_index( string $job_id ): void {
		$active = get_option( self::ACTIVE_JOBS_OPTION, [] );
		if ( ! is_array( $active ) ) {
			$active = [];
		}
		if ( ! in_array( $job_id, $active, true ) ) {
			$active[] = $job_id;
			update_option( self::ACTIVE_JOBS_OPTION, $active, false );
		}
	}

	/**
	 * Add multiple job IDs to the active jobs index in a single write.
	 *
	 * More efficient than calling add_to_active_index() in a loop
	 * because it performs only one option read and one option write.
	 *
	 * @param string[] $job_ids The job IDs to add.
	 */
	public function add_many_to_active_index( array $job_ids ): void {
		if ( empty( $job_ids ) ) {
			return;
		}
		$active = get_option( self::ACTIVE_JOBS_OPTION, [] );
		if ( ! is_array( $active ) ) {
			$active = [];
		}
		$changed  = false;
		$existing = array_flip( $active );
		foreach ( $job_ids as $id ) {
			if ( ! isset( $existing[ $id ] ) ) {
				$active[]        = $id;
				$existing[ $id ] = true;
				$changed         = true;
			}
		}
		if ( $changed ) {
			update_option( self::ACTIVE_JOBS_OPTION, $active, false );
		}
	}

	/**
	 * Remove a job ID from the active jobs index.
	 *
	 * @param string $job_id The job ID to remove.
	 */
	private function remove_from_active_index( string $job_id ): void {
		$active = get_option( self::ACTIVE_JOBS_OPTION, [] );
		if ( ! is_array( $active ) ) {
			$active = [];
		}
		$active = array_values(
			array_filter(
				$active,
				static function ( $id ) use ( $job_id ) {
					return $id !== $job_id;
				}
			)
		);
		update_option( self::ACTIVE_JOBS_OPTION, $active, false );
	}

	/**
	 * Get all active job IDs from the active jobs index.
	 *
	 * @return string[] Array of job IDs currently in pending or running state.
	 */
	public function get_active_job_ids(): array {
		$active = get_option( self::ACTIVE_JOBS_OPTION, [] );
		return is_array( $active ) ? $active : [];
	}

	/**
	 * Get terminal (completed/failed/cancelled) job IDs from wp_options.
	 *
	 * Excludes auxiliary keys (_action_id, _children_done, etc.) and
	 * IDs still present in the active index.
	 *
	 * @param int $limit Maximum number of IDs to return.
	 * @return string[] Array of terminal job IDs.
	 */
	public function get_terminal_job_ids( int $limit = 100 ): array {
		global $wpdb;

		$prefix     = self::OPTION_PREFIX;
		$prefix_len = strlen( $prefix );
		$active     = get_option( self::ACTIVE_JOBS_OPTION, [] );
		$active     = is_array( $active ) ? array_flip( $active ) : [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d",
				$wpdb->esc_like( $prefix ) . '%',
				$limit * 3
			)
		);

		if ( ! is_array( $results ) ) {
			return [];
		}

		$job_ids = [];
		foreach ( $results as $option_name ) {
			$suffix = substr( $option_name, $prefix_len );

			if (
				str_ends_with( $suffix, '_action_id' )
				|| str_ends_with( $suffix, '_action_args' )
				|| str_ends_with( $suffix, '_children_done' )
				|| str_ends_with( $suffix, '_children_failed' )
				|| str_ends_with( $suffix, '_lock' )
			) {
				continue;
			}

			if ( isset( $active[ $suffix ] ) ) {
				continue;
			}

			$job_ids[] = $suffix;
			if ( count( $job_ids ) >= $limit ) {
				break;
			}
		}

		return $job_ids;
	}
}
