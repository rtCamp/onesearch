<?php
/**
 * REST API controller for job management.
 *
 * @package OneSearch\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Jobs\ReindexJob;
use OneSearch\Modules\Jobs\SyncJob;
use OneSearch\Modules\Scheduler\JobScheduler;
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
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
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
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
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
	 * Retry a failed SyncJob batch by cancelling the old and creating a new one.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry_job( $request ) {
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

		// Cancel the old failed SyncJob.
		$scheduler->cancel( $job_id );

		// Create a new SyncJob with the same data.
		$post_ids   = $status['data']['post_ids'] ?? [];
		$post_types = $status['data']['post_types'] ?? [];
		$new_job    = new SyncJob();
		$new_job->set_data(
			[
				'post_ids'   => $post_ids,
				'post_types' => $post_types,
			]
		);
		$new_job->set_parent_id( $status['parent_id'] ?? '' );
		$new_job->set_group( $status['group'] ?? 'sync' );
		$new_job->set_max_retries( 2 );
		$new_job->set_retry_delay_seconds( 30 );

		try {
			$scheduler->schedule( $new_job );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_retry_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Batch retry scheduled.', 'onesearch' ),
				'job_id'  => $new_job->get_id(),
			]
		);
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
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_id DESC LIMIT 50",
				$prefix . '%',
				$prefix . '%_action_%'
			)
		);

		$jobs = [];
		if ( $results ) {
			foreach ( $results as $row ) {
				$data = maybe_unserialize( $row->option_value );
				if ( is_array( $data ) && in_array( $data['status'] ?? '', [ 'completed', 'failed', 'cancelled' ], true ) ) {
					$jobs[] = $data;
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
}
