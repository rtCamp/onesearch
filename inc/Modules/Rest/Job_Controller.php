<?php
/**
 * REST API controller for job management.
 *
 * @package OneSearch\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Jobs\AbstractJob;
use OneSearch\Modules\Jobs\ReindexJob;
use OneSearch\Modules\Jobs\SyncJob;
use OneSearch\Modules\Scheduler\JobScheduler;
use OneSearch\Modules\Schema\JobRepository;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class - Job_Controller
 *
 * Provides REST endpoints for creating, monitoring, and cancelling
 * async batch sync jobs via Action Scheduler.
 */
class Job_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/jobs',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_jobs' ],
					'permission_callback' => [ $this, 'check_job_read_permissions' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/history',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_job_history' ],
					'permission_callback' => [ $this, 'check_job_read_permissions' ],
					'args'                => [
						'page'     => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						],
						'per_page' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 5,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/remote-status',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_remote_job_status' ],
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => [
						'site_url' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						],
						'job_id'   => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/remote-retry',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'retry_remote_job' ],
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => [
						'site_url' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						],
						'job_id'   => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/remote-cancel',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'cancel_remote_job' ],
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => [
						'site_url' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						],
						'job_id'   => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/reindex',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_reindex' ],
					'permission_callback' => [ $this, 'check_api_permissions' ],
					'args'                => [
						'post_types' => [
							'required'          => false,
							'type'              => 'array',
							'default'           => [],
							'sanitize_callback' => static function ( $value ) {
								return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : [];
							},
						],
						'batch_size' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 30,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[-a-zA-Z0-9_.]+)/children',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_job_children' ],
					'permission_callback' => [ $this, 'check_job_read_permissions' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[-a-zA-Z0-9_.]+)/retry',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'retry_job' ],
					'permission_callback' => [ $this, 'check_api_permissions' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[-a-zA-Z0-9_.]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_job' ],
					'permission_callback' => [ $this, 'check_job_read_permissions' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[-a-zA-Z0-9_.]+)',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'cancel_job' ],
					'permission_callback' => [ $this, 'check_api_permissions' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);
	}

	/**
	 * Get all active/recent jobs.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function get_jobs( $request ): WP_REST_Response {
		$scheduler = new JobScheduler();
		$group     = $request->get_param( 'group' );

		if ( $group ) {
			$jobs = $scheduler->get_jobs_by_group( sanitize_text_field( $group ) );
		} else {
			$repository = new JobRepository();
			$active_ids = $scheduler->get_active_job_ids();
			$terminal   = $repository->get_terminal_jobs( 1, 50, false );
			$jobs       = [];

			foreach ( $active_ids as $job_id ) {
				$status = $scheduler->get_status( $job_id );
				if ( $status ) {
					$jobs[] = $this->maybe_finalize_remote_job( $status, $scheduler );
				}
			}

			foreach ( $terminal as $row ) {
				$jobs[] = $row;
			}
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'jobs'    => $jobs,
			]
		);
	}

	/**
	 * Get a single job's status.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job( $request ) {
		$job_id    = $request->get_param( 'id' );
		$scheduler = new JobScheduler();
		$status    = $scheduler->get_status( $job_id );

		if ( ! $status ) {
			return new \WP_Error(
				'onesearch_job_not_found',
				__( 'Job not found.', 'onesearch' ),
				[ 'status' => 404 ]
			);
		}

		$status = $this->maybe_finalize_remote_job( $status, $scheduler );

		return new WP_REST_Response(
			[
				'success' => true,
				'job'     => $status,
			]
		);
	}

	/**
	 * Finalize a parent job that is waiting on remote site statuses.
	 *
	 * When a governing-site reindex has local children all done but remote
	 * child sites still in flight, notify_parent() stores the parent as
	 * RUNNING with _needs_remote_finalize. This method re-checks remote
	 * statuses and transitions the parent to its true terminal state so
	 * that the progress UI can dismiss.
	 *
	 * @param array<string, mixed>                      $job       Job status array.
	 * @param \OneSearch\Modules\Scheduler\JobScheduler $scheduler Scheduler instance.
	 * @return array<string, mixed> Possibly updated job status.
	 */
	private function maybe_finalize_remote_job( array $job, JobScheduler $scheduler ): array {
		if ( AbstractJob::STATUS_RUNNING !== ( $job['status'] ?? '' ) ) {
			return $job;
		}

		$needs_finalize = $job['data']['_needs_remote_finalize'] ?? false;
		if ( ! $needs_finalize || ! Settings::is_governing_site() ) {
			return $job;
		}

		$remote_sites = $job['data']['sites'] ?? [];
		if ( ! is_array( $remote_sites ) || count( $remote_sites ) <= 1 ) {
			return $job;
		}

		$r = $scheduler->check_remote_job_statuses( $remote_sites );
		if ( $r['running'] > 0 ) {
			return $job;
		}

		if ( $r['cancelled'] > 0 ) {
			$job['status'] = AbstractJob::STATUS_CANCELLED;
		} elseif ( $r['failed'] > 0 ) {
			$job['status'] = AbstractJob::STATUS_FAILED;
			$job['error']  = sprintf(
				/* translators: 1: failed remote sites */
				__( '%1$d remote site(s) failed', 'onesearch' ),
				$r['failed']
			);
		} else {
			$job['status'] = AbstractJob::STATUS_COMPLETED;
		}

		$job['children_failed'] = max(
			(int) ( $job['children_failed'] ?? 0 ),
			$r['failed'] + $r['cancelled']
		);
		$job['progress']        = $job['progress_total'] ?? 0;
		$job['finished_at']     = time();
		$job['updated_at']      = time();

		$repository = new JobRepository();
		$repository->upsert( $job );

		$parent_key = JobScheduler::OPTION_PREFIX . ( $job['id'] ?? '' );
		delete_transient( $parent_key );

		Search_Controller::clear_reindex_state();

		return $job;
	}

	/**
	 * Create and schedule a ReindexJob.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_reindex( $request ) {
		$post_types = $request->get_param( 'post_types' ) ?: [];
		$batch_size = $request->get_param( 'batch_size' ) ?: 100;

		// If no post_types specified, resolve from settings.
		if ( empty( $post_types ) ) {
			$post_types = $this->get_post_types_to_index();

			if ( empty( $post_types ) ) {
				return new \WP_Error(
					'onesearch_no_post_types',
					__( 'No post types configured for indexing.', 'onesearch' ),
					[ 'status' => 400 ]
				);
			}
		}

		$job = new ReindexJob();
		$job->set_data(
			[
				'post_types' => $post_types,
				'batch_size' => $batch_size,
			]
		);
		$job->set_max_retries( 2 );
		$job->set_retry_delay_seconds( 60 );

		$scheduler = new JobScheduler();

		try {
			$scheduler->schedule( $job );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_schedule_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Re-indexing scheduled successfully.', 'onesearch' ),
				'job_id'  => $job->get_id(),
			]
		);
	}

	/**
	 * Cancel a job and its children.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_job( $request ) {
		$job_id    = $request->get_param( 'id' );
		$scheduler = new JobScheduler();
		$status    = $scheduler->get_status( $job_id );

		if ( ! $status ) {
			return new \WP_Error(
				'onesearch_job_not_found',
				__( 'Job not found.', 'onesearch' ),
				[ 'status' => 404 ]
			);
		}

		$is_parent_job = ! empty( $status['child_ids'] ?? [] );
		if ( ! $is_parent_job && in_array( $status['status'] ?? '', [ 'completed', 'failed', 'cancelled' ], true ) ) {
			return new \WP_Error(
				'onesearch_job_terminal',
				__( 'Job is already in a terminal state.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		$scheduler->cancel( $job_id );

		// If this is a parent ReindexJob being cancelled, clear the
		// reindex state so the frontend knows it can start a new one.
		if ( ! empty( $status['child_ids'] ?? [] ) ) {
			Search_Controller::clear_reindex_state();
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Job cancelled.', 'onesearch' ),
			]
		);
	}

	/**
	 * Get child jobs of a parent job.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job_children( $request ) {
		$job_id    = $request->get_param( 'id' );
		$scheduler = new JobScheduler();
		$status    = $scheduler->get_status( $job_id );

		if ( ! $status ) {
			return new \WP_Error(
				'onesearch_job_not_found',
				__( 'Job not found.', 'onesearch' ),
				[ 'status' => 404 ]
			);
		}

		$child_ids = $status['child_ids'] ?? [];
		$children  = [];

		foreach ( $child_ids as $child_id ) {
			$child_status = $scheduler->get_status( $child_id );
			if ( $child_status ) {
				$children[] = $child_status;
			}
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'children' => $children,
			]
		);
	}

	/**
	 * Retry a failed SyncJob batch using the same job ID.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry_job( $request ) {
		global $wpdb;

		$job_id    = $request->get_param( 'id' );
		$scheduler = new JobScheduler();
		$status    = $scheduler->get_status( $job_id );

		if ( ! $status ) {
			return new \WP_Error(
				'onesearch_job_not_found',
				__( 'Job not found.', 'onesearch' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'failed' !== ( $status['status'] ?? '' ) ) {
			return new \WP_Error(
				'onesearch_retry_not_failed',
				__( 'Only failed jobs can be retried.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! empty( $status['child_ids'] ?? [] ) ) {
			return $this->retry_failed_child_jobs( $scheduler, $status );
		}

		// Unschedule any remaining retry actions for this job.
		$group      = 'onesearch_' . ( $status['group'] ?? 'default' );
		$repository = new JobRepository();
		$action_rec = $repository->get_action( $job_id );
		if ( $action_rec ) {
			as_unschedule_action( JobScheduler::HOOK, $action_rec['args'], $group );
		}

		// Reconstruct, reset, and reschedule the same job.
		$job = SyncJob::from_array( $status );
		$job->set_status( AbstractJob::STATUS_PENDING );
		$job->set_retry_count( 0 );
		$job->clear_error();

		try {
			$scheduler->schedule( $job );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_retry_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		// If this child had a parent, reset parent tracking.
		$parent_id = $status['parent_id'] ?? '';
		if ( $parent_id ) {
			$this->reset_parent_for_retry( $scheduler, $wpdb, $parent_id );
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Batch retry scheduled.', 'onesearch' ),
				'job_id'  => $job->get_id(),
			]
		);
	}

	/**
	 * Retry only failed child batches for a parent reindex job.
	 *
	 * @param \OneSearch\Modules\Scheduler\JobScheduler $scheduler   The job scheduler instance.
	 * @param array<string, mixed>                      $parent_data Stored parent job data.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function retry_failed_child_jobs( JobScheduler $scheduler, array $parent_data ) {
		$parent_id = (string) ( $parent_data['id'] ?? '' );
		$child_ids = $parent_data['child_ids'] ?? [];

		if ( '' === $parent_id || ! is_array( $child_ids ) ) {
			return new \WP_Error(
				'onesearch_retry_invalid_parent',
				__( 'Invalid parent job.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		$failed_children     = [];
		$completed_children  = 0;
		$terminal_failed_ids = [];

		foreach ( $child_ids as $child_id ) {
			if ( ! is_string( $child_id ) || '' === $child_id ) {
				continue;
			}

			$child_status = $scheduler->get_status( $child_id );
			if ( ! $child_status ) {
				continue;
			}

			if ( AbstractJob::STATUS_FAILED === ( $child_status['status'] ?? '' ) ) {
				$failed_children[]     = $child_status;
				$terminal_failed_ids[] = $child_id;
				continue;
			}

			if ( in_array( $child_status['status'] ?? '', JobScheduler::TERMINAL_STATUSES, true ) ) {
				++$completed_children;
			}
		}

		if ( empty( $failed_children ) ) {
			return new \WP_Error(
				'onesearch_retry_no_failed_children',
				__( 'No failed batches are available to retry.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		$this->prepare_parent_for_child_retries( $scheduler, $parent_data, $completed_children );

		$retried    = 0;
		$repository = new JobRepository();
		foreach ( $failed_children as $child_status ) {
			$child_id = (string) ( $child_status['id'] ?? '' );
			if ( '' === $child_id ) {
				continue;
			}

			$group      = 'onesearch_' . ( $child_status['group'] ?? 'default' );
			$action_rec = $repository->get_action( $child_id );
			if ( $action_rec ) {
				as_unschedule_action( JobScheduler::HOOK, $action_rec['args'], $group );
			}

			$child = SyncJob::from_array( $child_status );
			$child->set_status( AbstractJob::STATUS_PENDING );
			$child->set_retry_count( 0 );
			$child->clear_error();

			try {
				$scheduler->schedule( $child );
				++$retried;
			} catch ( \Throwable $e ) {
				return new \WP_Error(
					'onesearch_retry_failed',
					$e->getMessage(),
					[ 'status' => 500 ]
				);
			}
		}

		return new WP_REST_Response(
			[
				'success'   => true,
				'message'   => __( 'Failed batches retry scheduled.', 'onesearch' ),
				'job_id'    => $parent_id,
				'retried'   => $retried,
				'child_ids' => $terminal_failed_ids,
			]
		);
	}

	/**
	 * Reset parent counters before retrying failed child jobs.
	 *
	 * @param \OneSearch\Modules\Scheduler\JobScheduler $scheduler          The job scheduler instance.
	 * @param array<string, mixed>                      $parent_data        Stored parent job data.
	 * @param int                                       $completed_children Completed child count to preserve.
	 */
	private function prepare_parent_for_child_retries( JobScheduler $scheduler, array $parent_data, int $completed_children ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		$parent_id = (string) ( $parent_data['id'] ?? '' );
		if ( '' === $parent_id ) {
			return;
		}

		$repository = new JobRepository();
		$repository->reset_counters( $parent_id, $completed_children, 0, 0 );

		$parent_data['status']             = AbstractJob::STATUS_RUNNING;
		$parent_data['children_completed'] = $completed_children;
		$parent_data['children_failed']    = 0;
		$parent_data['children_cancelled'] = 0;
		$parent_data['progress']           = min( $completed_children, (int) ( $parent_data['progress_total'] ?? $completed_children ) );
		$parent_data['error']              = null;
		$parent_data['finished_at']        = null;
		$parent_data['updated_at']         = time();

		$repository->upsert( $parent_data );
		$parent_key = JobScheduler::OPTION_PREFIX . $parent_id;
		set_transient( $parent_key, $parent_data, JobScheduler::TRANSIENT_EXPIRATION );
	}

	/**
	 * Decrement the parent's children_done counter and set back to RUNNING if terminal.
	 *
	 * @param \OneSearch\Modules\Scheduler\JobScheduler $scheduler The job scheduler instance.
	 * @param \wpdb                                     $wpdb      WordPress database abstraction.
	 * @param string                                    $parent_id The parent job ID.
	 */
	private function reset_parent_for_retry( JobScheduler $scheduler, $wpdb, string $parent_id ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		$parent_key  = JobScheduler::OPTION_PREFIX . $parent_id;
		$parent_data = $scheduler->get_status( $parent_id );
		if ( ! $parent_data ) {
			return;
		}

		$repository = new JobRepository();

		$new_done   = $repository->decrement_counter( $parent_id, 'children_done' );
		$new_failed = $repository->decrement_counter( $parent_id, 'children_failed' );

		$parent_data['children_completed'] = $new_done;
		$parent_data['children_failed']    = $new_failed;

		// If parent was terminal, reset to RUNNING.
		if ( in_array( $parent_data['status'] ?? '', JobScheduler::TERMINAL_STATUSES, true ) ) {
			$parent_data['status']     = AbstractJob::STATUS_RUNNING;
			$parent_data['updated_at'] = time();
		}

		$repository->upsert( $parent_data );

		if ( in_array( $parent_data['status'], JobScheduler::TERMINAL_STATUSES, true ) ) {
			delete_transient( $parent_key );
		} else {
			set_transient( $parent_key, $parent_data, JobScheduler::TRANSIENT_EXPIRATION );
		}
	}

	/**
	 * Get job history with pagination.
	 *
	 * Returns top-level terminal jobs (completed, failed, cancelled)
	 * with pagination support.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function get_job_history( $request ): WP_REST_Response {
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?? 5 ) ) );

		$repository = new JobRepository();
		$total      = $repository->count_terminal_jobs( true );
		$rows       = $repository->get_terminal_jobs( $page, $per_page, true );

		$scheduler = new JobScheduler();
		$jobs      = [];
		foreach ( $rows as $data ) {
			$jobs[] = $this->hydrate_history_job( $data, $scheduler );
		}

		return new WP_REST_Response(
			[
				'success'     => true,
				'jobs'        => $jobs,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);
	}

	/**
	 * Hydrate a history row from its children so parent rows reflect child failures.
	 *
	 * @param array<string, mixed>                      $job       Stored parent job data.
	 * @param \OneSearch\Modules\Scheduler\JobScheduler $scheduler Job scheduler instance.
	 * @return array<string, mixed>
	 */
	private function hydrate_history_job( array $job, JobScheduler $scheduler ): array {
		$child_ids    = $job['child_ids'] ?? [];
		$remote_sites = $job['data']['sites'] ?? [];
		$has_remote   = Settings::is_governing_site()
			&& is_array( $remote_sites )
			&& count( $remote_sites ) > 1;

		if ( ( ! is_array( $child_ids ) || empty( $child_ids ) ) && ! $has_remote ) {
			return $job;
		}

		$children_completed = 0;
		$children_terminal  = 0;
		$local_failed       = 0;
		$local_cancelled    = 0;

		foreach ( $child_ids as $child_id ) {
			if ( ! is_string( $child_id ) || '' === $child_id ) {
				continue;
			}

			$child = $scheduler->get_status( $child_id );
			if ( ! $child ) {
				continue;
			}

			$status = $child['status'] ?? '';

			// Count only successfully completed children for progress display.
			if ( AbstractJob::STATUS_COMPLETED === $status ) {
				++$children_completed;
			}

			// Count all terminal children for status determination.
			if ( in_array( $status, JobScheduler::TERMINAL_STATUSES, true ) ) {
				++$children_terminal;
			}

			if ( AbstractJob::STATUS_FAILED === $status ) {
				++$local_failed;
			} elseif ( AbstractJob::STATUS_CANCELLED === $status ) {
				++$local_cancelled;
			}
		}

		$children_total = count( $child_ids );

		$job['children_total']     = $children_total;
		$job['children_completed'] = $children_completed;
		$job['children_failed']    = max(
			(int) ( $job['children_failed'] ?? 0 ),
			$local_failed + $local_cancelled
		);

		// Aggregate remote job progress for governing sites.
		// Use batch_count from stored sites array for totals (reliable, no API call),
		// and fetch completed counts from remote APIs.
		if ( Settings::is_governing_site() ) {
			$remote_sites = $job['data']['sites'] ?? [];
			if ( is_array( $remote_sites ) && count( $remote_sites ) > 1 ) {
				$current_site = \OneSearch\Utils::normalize_url( get_site_url() );

				// Calculate remote totals from stored batch_count (always accurate).
				$remote_total = 0;
				foreach ( $remote_sites as $site_info ) {
					$site_url = \OneSearch\Utils::normalize_url( $site_info['site_url'] ?? '' );
					if ( $site_url === $current_site ) {
						continue; // Skip local site, already counted above.
					}
					$remote_total += (int) ( $site_info['batch_count'] ?? 0 );
				}

				// Fetch remote completed counts via API.
				$remote_progress     = $scheduler->fetch_remote_job_progress( $remote_sites );
				$children_completed += $remote_progress['completed'];
				$children_terminal  += $remote_progress['terminal'];
				$children_total     += $remote_total;

				$job['children_completed'] = $children_completed;
				$job['children_total']     = $children_total;
			}
		}

		$determine_status_from_children = static function () use ( $local_failed, $local_cancelled, $children_terminal, $children_total ): string {
			if ( $local_cancelled > 0 ) {
				return AbstractJob::STATUS_CANCELLED;
			}
			if ( $local_failed > 0 ) {
				return AbstractJob::STATUS_FAILED;
			}
			if ( $children_terminal === $children_total && $children_total > 0 ) {
				return AbstractJob::STATUS_COMPLETED;
			}
			return '';
		};

		$derived_status = $determine_status_from_children();

		if ( '' !== $derived_status && ( $job['status'] ?? '' ) !== $derived_status ) {
			$job['status'] = $derived_status;

			if ( AbstractJob::STATUS_COMPLETED !== $derived_status && empty( $job['error'] ) && $local_failed > 0 ) {
				$job['error'] = sprintf(
					/* translators: 1: failed child batches, 2: total child batches */
					__( '%1$d/%2$d child batches failed', 'onesearch' ),
					$local_failed,
					$children_total
				);
			}
		}

		$needs_finalize = $job['data']['_needs_remote_finalize'] ?? false;
		if ( $needs_finalize
			&& AbstractJob::STATUS_RUNNING === ( $job['status'] ?? '' )
			&& 0 === $local_failed && 0 === $local_cancelled
			&& Settings::is_governing_site()
		) {
			$remote_sites = $job['data']['sites'] ?? [];
			if ( is_array( $remote_sites ) && count( $remote_sites ) > 1 ) {
				$r = $scheduler->check_remote_job_statuses( $remote_sites );

				if ( 0 === $r['running'] ) {
					if ( $r['cancelled'] > 0 ) {
						$job['status'] = AbstractJob::STATUS_CANCELLED;
					} elseif ( $r['failed'] > 0 ) {
						$job['status'] = AbstractJob::STATUS_FAILED;
						$job['error']  = sprintf(
							/* translators: 1: failed remote sites */
							__( '%1$d remote site(s) failed', 'onesearch' ),
							$r['failed']
						);
					} else {
						$job['status'] = AbstractJob::STATUS_COMPLETED;
					}

					$job['children_failed'] = max(
						(int) ( $job['children_failed'] ?? 0 ),
						$r['failed'] + $r['cancelled']
					);

					$job['finished_at'] = time();
					$job['updated_at']  = time();

					$repository = new JobRepository();
					$repository->upsert( $job );
				}
			}
		}

		return $job;
	}

	/**
	 * Get the post types to index for the current site.
	 *
	 * @return string[]
	 */
	private function get_post_types_to_index(): array {
		if ( \OneSearch\Modules\Settings\Settings::is_governing_site() ) {
			$opt        = \OneSearch\Modules\Search\Settings::get_indexable_entities();
			$site_url   = \OneSearch\Utils::normalize_url( get_site_url() );
			$post_types = $opt['entities'][ $site_url ] ?? null;

			return is_array( $post_types ) ? array_values( array_unique( array_map( 'strval', $post_types ) ) ) : [];
		}

		$parent_url = \OneSearch\Modules\Settings\Settings::get_parent_site_url();
		if ( empty( $parent_url ) ) {
			return [];
		}

		$brand_config = \OneSearch\Modules\Rest\Governing_Data_Handler::get_brand_config();
		if ( is_wp_error( $brand_config ) ) {
			return [];
		}

		return $brand_config['indexable_entities'] ?? [];
	}

	/**
	 * Permission check for job read endpoints.
	 *
	 * Allows access via WordPress admin session (manage_options)
	 * OR via the X-OneSearch-Token header (for remote governing-site requests).
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return bool|\WP_Error
	 */
	public function check_job_read_permissions( $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$token = $request->get_header( 'X-OneSearch-Token' );
		if ( empty( $token ) ) {
			return false;
		}

		$api_key = Settings::get_api_key();

		return ! empty( $api_key ) && hash_equals( $api_key, sanitize_text_field( wp_unslash( $token ) ) );
	}

	/**
	 * Proxy endpoint: fetch job status from a remote (child) site.
	 *
	 * The governing site calls this to poll a child site's job status
	 * without exposing the child's API key to the browser.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_remote_job_status( $request ) {
		$site_url = $request->get_param( 'site_url' );
		$job_id   = $request->get_param( 'job_id' );
		$context  = $this->get_remote_job_request_context( (string) $site_url );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$base_url       = $context['base_url'];
		$headers        = array_merge(
			$context['headers'],
			[ 'Origin' => get_site_url() ]
		);
		$namespace      = self::NAMESPACE;
		$encoded_job_id = rawurlencode( (string) $job_id );

		// Fetch job status.
		$job_response = wp_safe_remote_get(
			sprintf( '%s/wp-json/%s/jobs/%s', $base_url, $namespace, $encoded_job_id ),
			[
				'headers' => $headers,
				'timeout' => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		// Fetch children.
		$children_response = wp_safe_remote_get(
			sprintf( '%s/wp-json/%s/jobs/%s/children', $base_url, $namespace, $encoded_job_id ),
			[
				'headers' => $headers,
				'timeout' => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		$job_data      = null;
		$children_data = [];

		// Parse job response.
		if ( ! is_wp_error( $job_response ) ) {
			$code = wp_remote_retrieve_response_code( $job_response );
			$body = wp_remote_retrieve_body( $job_response );
			if ( 200 === $code ) {
				$decoded  = json_decode( $body, true );
				$job_data = $decoded['job'] ?? null;
			}
		}

		// Parse children response.
		if ( ! is_wp_error( $children_response ) ) {
			$code = wp_remote_retrieve_response_code( $children_response );
			$body = wp_remote_retrieve_body( $children_response );
			if ( 200 === $code ) {
				$decoded       = json_decode( $body, true );
				$children_data = $decoded['children'] ?? [];
			}
		}

		if ( ! $job_data ) {
			return new \WP_Error(
				'onesearch_remote_job_not_found',
				__( 'Job not found on remote site.', 'onesearch' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'job'      => $job_data,
				'children' => $children_data,
			]
		);
	}

	/**
	 * Retry a failed batch on a remote child site through the governing site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry_remote_job( $request ) {
		$site_url = $request->get_param( 'site_url' );
		$job_id   = $request->get_param( 'job_id' );
		$context  = $this->get_remote_job_request_context( (string) $site_url );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$response = wp_safe_remote_post(
			sprintf(
				'%s/wp-json/%s/jobs/%s/retry',
				$context['base_url'],
				self::NAMESPACE,
				rawurlencode( (string) $job_id )
			),
			[
				'headers' => array_merge(
					$context['headers'],
					[ 'Origin' => get_site_url() ]
				),
				'timeout' => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'onesearch_remote_retry_failed',
				$data['message'] ?? __( 'Remote retry failed.', 'onesearch' ),
				[ 'status' => $code ]
			);
		}

		return new WP_REST_Response( is_array( $data ) ? $data : [ 'success' => true ] );
	}

	/**
	 * Send a cancel request to a job on a remote child site.
	 *
	 * Used internally to propagate reindex cancellations from the governing
	 * site down to child sites, as well as by the cancel_remote_job endpoint.
	 *
	 * @param string $site_url The child site URL.
	 * @param string $job_id   The job ID to cancel on the child site.
	 * @return bool True if the cancel was accepted by the child site.
	 */
	private function send_remote_cancel( string $site_url, string $job_id ): bool {
		$context = $this->get_remote_job_request_context( $site_url );

		if ( is_wp_error( $context ) ) {
			return false;
		}

		$headers = array_merge(
			$context['headers'],
			[ 'Origin' => get_site_url() ]
		);

		$response = wp_safe_remote_request(
			sprintf(
				'%s/wp-json/%s/jobs/%s',
				$context['base_url'],
				self::NAMESPACE,
				rawurlencode( $job_id )
			),
			[
				'method'  => 'DELETE',
				'headers' => $headers,
				'timeout' => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);

		// If the remote site is unreachable the child's jobs will time out.
		return ! is_wp_error( $response );
	}

	/**
	 * Cancel a job on a remote child site through the governing site.
	 *
	 * Proxies a DELETE request to the child site's /jobs/{id} endpoint.
	 * If the remote site is unreachable, still returns success so the
	 * local UI can clean up its state without waiting.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_remote_job( $request ) {
		$site_url = $request->get_param( 'site_url' );
		$job_id   = $request->get_param( 'job_id' );

		$sent = $this->send_remote_cancel( (string) $site_url, (string) $job_id );

		if ( ! $sent ) {
			return new WP_REST_Response(
				[
					'success' => true,
					'warning' => __( 'Remote site unreachable; local state cleaned.', 'onesearch' ),
				]
			);
		}

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * Resolve request data needed to talk to a child site's jobs endpoints.
	 *
	 * @param string $site_url Site URL.
	 * @return array{base_url:string,headers:array<string,string>}|\WP_Error
	 */
	private function get_remote_job_request_context( string $site_url ) {
		$shared_sites = Settings::get_shared_sites();
		$site_key     = '';
		$matched_url  = '';

		foreach ( $shared_sites as $url => $site_data ) {
			if ( Utils::normalize_url( $url ) === Utils::normalize_url( $site_url ) ) {
				$site_key    = $site_data['api_key'] ?? '';
				$matched_url = $url;
				break;
			}
		}

		if ( empty( $site_key ) ) {
			return new \WP_Error(
				'onesearch_unknown_site',
				__( 'Site not found in shared sites.', 'onesearch' ),
				[ 'status' => 404 ]
			);
		}

		return [
			'base_url' => untrailingslashit( $matched_url ),
			'headers'  => [
				'Content-Type'      => 'application/json',
				'X-OneSearch-Token' => $site_key,
			],
		];
	}
}
