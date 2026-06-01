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
							'default'           => 10,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[a-zA-Z0-9_.]+)/children',
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
			'/jobs/(?P<id>[a-zA-Z0-9_.]+)/retry',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'retry_job' ],
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
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
			'/jobs/(?P<id>[a-zA-Z0-9_.]+)',
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
			'/jobs/(?P<id>[a-zA-Z0-9_.]+)',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'cancel_job' ],
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
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
			$active_ids = $scheduler->get_active_job_ids();
			$jobs       = [];
			foreach ( $active_ids as $job_id ) {
				$status = $scheduler->get_status( $job_id );
				if ( $status ) {
					$jobs[] = $status;
				}
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

		return new WP_REST_Response(
			[
				'success' => true,
				'job'     => $status,
			]
		);
	}

	/**
	 * Create and schedule a ReindexJob.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_reindex( $request ) {
		$post_types = $request->get_param( 'post_types' ) ?: [];
		$batch_size = $request->get_param( 'batch_size' ) ?: 10;

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

		if ( in_array( $status['status'] ?? '', [ 'completed', 'failed', 'cancelled' ], true ) ) {
			return new \WP_Error(
				'onesearch_job_terminal',
				__( 'Job is already in a terminal state.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		$scheduler->cancel( $job_id );

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

		// Unschedule any remaining retry actions for this job.
		$group     = 'onesearch_' . ( $status['group'] ?? 'default' );
		$action_id = get_option( JobScheduler::OPTION_PREFIX . $job_id . '_action_id', 0 );
		if ( $action_id ) {
			$args = get_option( JobScheduler::OPTION_PREFIX . $job_id . '_action_args', [] );
			as_unschedule_action( JobScheduler::HOOK, $args, $group );
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
	 * Decrement the parent's children_done counter and set back to RUNNING if terminal.
	 *
	 * @param \OneSearch\Modules\Scheduler\JobScheduler $scheduler The job scheduler instance.
	 * @param \wpdb                                     $wpdb      WordPress database abstraction.
	 * @param string                                    $parent_id The parent job ID.
	 */
	private function reset_parent_for_retry( JobScheduler $scheduler, $wpdb, string $parent_id ): void {
		$parent_key  = JobScheduler::OPTION_PREFIX . $parent_id;
		$parent_data = $scheduler->get_status( $parent_id );
		if ( ! $parent_data ) {
			return;
		}

		// Decrement children_done counter to compensate for the retried child.
		$counter_key = JobScheduler::OPTION_PREFIX . $parent_id . '_children_done';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			// @phpstan-ignore argument.type
			$wpdb->prepare(
				// @phpstan-ignore argument.type
				"UPDATE {$wpdb->options} SET option_value = GREATEST(CAST(option_value AS UNSIGNED) - 1, 0) WHERE option_name = %s",
				$counter_key
			)
		);
		wp_cache_delete( $counter_key, 'options' );
		$parent_data['children_completed'] = (int) get_option( $counter_key, 0 );

		// If parent was terminal, reset to RUNNING.
		if ( in_array( $parent_data['status'] ?? '', JobScheduler::TERMINAL_STATUSES, true ) ) {
			$parent_data['status']     = AbstractJob::STATUS_RUNNING;
			$parent_data['updated_at'] = time();
		}

		$is_terminal = in_array( $parent_data['status'], JobScheduler::TERMINAL_STATUSES, true );
		if ( $is_terminal ) {
			update_option( $parent_key, $parent_data, false );
			delete_transient( $parent_key );
		} else {
			set_transient( $parent_key, $parent_data, JobScheduler::TRANSIENT_EXPIRATION );
			$scheduler->add_to_active_index( $parent_id );
		}
	}

	/**
	 * Get job history: all terminal (completed, failed, cancelled) jobs.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function get_job_history( $request ): WP_REST_Response { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		global $wpdb;

		$prefix  = 'onesearch_job_status_';
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_id DESC",
				$prefix . '%',
				$prefix . '%_action_%'
			)
		);

		$jobs = [];
		if ( $results ) {
			foreach ( $results as $row ) {
				$data = maybe_unserialize( $row->option_value );
				if ( is_array( $data ) && in_array( $data['status'] ?? '', [ 'completed', 'failed', 'cancelled' ], true ) ) {
					// Only show parent (top-level) jobs, not individual batches.
					if ( empty( $data['parent_id'] ) ) {
						$jobs[] = $data;
					}
				}
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

		// Look up the API key for this child site.
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

		$base_url       = untrailingslashit( $matched_url );
		$namespace      = self::NAMESPACE;
		$encoded_job_id = rawurlencode( $job_id );

		$headers = [
			'Content-Type'      => 'application/json',
			'X-OneSearch-Token' => $site_key,
		];

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
}
