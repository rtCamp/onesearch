<?php
/**
 * Routes for Search-related operations.
 *
 * @package OneSearch
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Jobs\ReindexJob;
use OneSearch\Modules\Scheduler\JobScheduler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Search_Controller
 */
class Search_Controller extends Abstract_REST_Controller {
	/**
	 * Transient key for the active reindex state.
	 *
	 * Stores the jobs array so the frontend can restore progress UI
	 * after a page refresh. Cleared when the reindex completes or is
	 * cancelled.
	 */
	public const REINDEX_STATE_TRANSIENT = 'onesearch_reindex_state';

	/**
	 * TTL in seconds for the reindex state transient.
	 */
	private const REINDEX_STATE_TTL = 3600;

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		if ( Settings::is_governing_site() ) {

			// Algolia credentials: get / set.
			register_rest_route(
				self::NAMESPACE,
				'/algolia-credentials',
				[
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this, 'get_algolia_credentials' ],
						'permission_callback' => [ $this, 'check_api_permissions' ],
					],
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'args'                => [
							'app_id'    => [
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'rest_sanitize_request_arg',
							],
							'write_key' => [
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'rest_sanitize_request_arg',
							],
						],
						'callback'            => [ $this, 'update_algolia_credentials' ],
						'permission_callback' => [ $this, 'check_api_permissions' ],
					],
				]
			);

			// Indexable entities (per site URL): get / set.
			register_rest_route(
				self::NAMESPACE,
				'/indexable-entities',
				[
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this, 'get_indexable_entities' ],
						'permission_callback' => [ $this, 'check_api_permissions' ],
					],
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this, 'set_indexable_entities' ],
						'permission_callback' => static function () {
							return current_user_can( 'manage_options' );
						},
						'args'                => [
							'entities' => [
								'required'          => true,
								'type'              => 'array',
								'sanitize_callback' => static function ( $value ) {
									if ( ! is_array( $value ) ) {
										return [];
									}
									return $value;
								},
							],
						],
					],
				]
			);
		}

		// Re-index current site (and children for governing sites).
		register_rest_route(
			self::NAMESPACE,
			'/re-index',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reindex' ],
				'permission_callback' => [ $this, 'check_api_permissions' ],
			]
		);

		// Get active reindex state for UI persistence across page refreshes.
		register_rest_route(
			self::NAMESPACE,
			'/re-index/status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_reindex_status' ],
				'permission_callback' => [ $this, 'check_api_permissions' ],
			]
		);
	}

	/**
	 * Get Algolia credentials from governing site.
	 */
	public function get_algolia_credentials(): WP_REST_Response {
		$creds = Search_Settings::get_algolia_credentials();

		return new \WP_REST_Response(
			[
				'success'   => true,
				'app_id'    => $creds['app_id'] ?? '',
				'write_key' => $creds['write_key'] ?? '',
			]
		);
	}

	/**
	 * Update Algolia credentials on governing site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function update_algolia_credentials( $request ): \WP_REST_Response|\WP_Error {
		$parameters = $request->get_json_params();

		$app_id    = isset( $parameters['app_id'] ) ? sanitize_text_field( $parameters['app_id'] ) : '';
		$write_key = isset( $parameters['write_key'] ) ? sanitize_text_field( $parameters['write_key'] ) : '';

		if ( empty( $app_id ) || empty( $write_key ) ) {
			return new \WP_Error(
				'onesearch_algolia_credentials_invalid',
				__( 'Both App ID and Write Key are required.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		$is_valid = $this->validate_algolia_key( $app_id, $write_key );
		if ( ! $is_valid ) {
			return new \WP_Error(
				'onesearch_algolia_credentials_invalid',
				__( 'The provided Algolia credentials are invalid or lack necessary permissions.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		$success = Search_Settings::set_algolia_credentials(
			[
				'app_id'    => $app_id,
				'write_key' => $write_key,
			]
		);

		if ( ! $success ) {
			return new \WP_Error(
				'onesearch_algolia_credentials_save_failed',
				__( 'Failed to save Algolia credentials.', 'onesearch' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Algolia credentials updated successfully.', 'onesearch' ),
			]
		);
	}

	/**
	 * Get the stored indexable entities map (governing only).
	 */
	public function get_indexable_entities(): \WP_REST_Response|\WP_Error {
		$indexable_entities = Search_Settings::get_indexable_entities();

		return rest_ensure_response(
			[
				'success'           => true,
				'indexableEntities' => $indexable_entities,
			]
		);
	}

	/**
	 * Save the indexable entities map (governing only).
	 *
	 * @param \WP_REST_Request $request Request object with JSON body.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_indexable_entities( \WP_REST_Request $request ) {

		$indexable_entities = json_decode( $request->get_body(), true );

		if ( ! is_array( $indexable_entities ) ) {
			return new \WP_Error( 'invalid_data', __( 'Failed saving settings. Please try again', 'onesearch' ), [ 'status' => 400 ] );
		}

		$sanitized = $this->sanitize_indexable_entities( $indexable_entities );

		update_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, $sanitized );

		return rest_ensure_response(
			[
				'success'           => true,
				'message'           => __( 'Data saved successfully.', 'onesearch' ),
				'indexableEntities' => $sanitized,
			]
		);
	}

	/**
	 * Recursively sanitize indexable entities data.
	 *
	 * @param mixed $data The data to sanitize.
	 * @return mixed The sanitized data.
	 */
	private function sanitize_indexable_entities( $data ) {
		if ( is_array( $data ) ) {
			$sanitized = [];
			foreach ( $data as $key => $value ) {
				$sanitized_key               = is_string( $key ) ? sanitize_text_field( $key ) : $key;
				$sanitized[ $sanitized_key ] = $this->sanitize_indexable_entities( $value );
			}
			return $sanitized;
		}

		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}

		return $data;
	}

	/**
	 * Reindex the current site.
	 *
	 * If the site is a governing site, trigger the reindex on children as well.
	 * The parent ReindexJob runs in this request to resolve posts and enqueue
	 * child SyncJobs; the child SyncJobs then run asynchronously via Action Scheduler.
	 */
	public function reindex(): \WP_REST_Response|\WP_Error {
		// Guard: prevent starting a new reindex while one is already running.
		// Use an option-based mutex (add_option is atomic in MySQL) to prevent
		// race conditions between concurrent requests.
		$lock_key = self::REINDEX_STATE_TRANSIENT . '_lock';
		if ( ! add_option( $lock_key, '1', '', 'no' ) ) {
			return new \WP_Error(
				'onesearch_reindex_active',
				__( 'A re-index is already in progress. Cancel it or wait for it to complete before starting a new one.', 'onesearch' ),
				[ 'status' => 409 ]
			);
		}

		// Auto-expire the lock after 5 minutes in case the process crashes
		// before cleanup. wp_schedule_single_action is preferred but not
		// guaranteed to be available during plugin init, so we use a transient
		// as a safety net.
		set_transient( $lock_key . '_expiry', '1', 5 * MINUTE_IN_SECONDS );

		$active_state = $this->get_active_reindex_state();
		if ( null !== $active_state ) {
			$this->release_reindex_lock( $lock_key );
			return new \WP_Error(
				'onesearch_reindex_active',
				__( 'A re-index is already in progress. Cancel it or wait for it to complete before starting a new one.', 'onesearch' ),
				[ 'status' => 409 ]
			);
		}

		$jobs   = [];
		$errors = [];

		// If Governing, trigger re-index on children as well.
		if ( Settings::is_governing_site() ) {
			$child_result = $this->reindex_child_sites();

			if ( isset( $child_result['jobs'] ) ) {
				$jobs = array_merge( $jobs, $child_result['jobs'] );
			}

			if ( isset( $child_result['errors'] ) ) {
				$errors = array_merge( $errors, $child_result['errors'] );
			}
		}

		$post_types = $this->get_post_types_to_index();

		if ( is_wp_error( $post_types ) ) {
			$this->release_reindex_lock( $lock_key );
			return $post_types;
		}

		// Create and execute the ReindexJob synchronously.
		// The parent job runs in this request (resolve posts, clear index,
		// schedule child SyncJobs). Only the child SyncJobs run async via AS.
		$job = new ReindexJob();
		$job->set_data(
			[
				'post_types' => $post_types,
				'batch_size' => 100,
			]
		);
		$job->set_max_retries( 2 );
		$job->set_retry_delay_seconds( 60 );

		$scheduler = new JobScheduler();
		$job_id    = $job->get_id();

		try {
			$job->mark_running();
			$scheduler->persist_job( $job );
			$job->handle();

			if ( $job->has_pending_children() ) {
				$job->mark_running();
			} elseif ( ! $job->is_finished() ) {
				$job->mark_completed();
			}

			$scheduler->persist_job( $job );
		} catch ( \Throwable $e ) {
			$job->fail( $e->getMessage() );
			$scheduler->persist_job( $job );
			$errors[] = [
				'site_url' => get_site_url(),
				'message'  => $e->getMessage(),
			];
		}

		// Add local site to the jobs list.
		$local_site_name = Settings::is_governing_site() ? __( 'Governing Site', 'onesearch' ) : get_bloginfo( 'name' );
		$local_site_url  = get_site_url();
		$local_batches   = $job->get_progress_total();

		if ( $job_id ) {
			array_unshift(
				$jobs,
				[
					'site_name'   => $local_site_name,
					'site_url'    => $local_site_url,
					'job_id'      => $job_id,
					'batch_count' => $local_batches,
				]
			);
		}

		// Compute combined total across all sites and store in the
		// governing site's job data so the history table can display it.
		if ( Settings::is_governing_site() ) {
			$total_batches      = $local_batches;
			$child_batch_counts = $child_result['batch_counts'] ?? [];
			foreach ( $child_batch_counts as $count ) {
				$total_batches += $count;
			}
			$job->set_data(
				array_merge(
					$job->get_data() ?: [],
					[
						'total_batches' => $total_batches,
						'sites'         => $jobs,
					]
				)
			);
			$scheduler->persist_job( $job );
		}

		// Persist the reindex state so the UI can survive page refreshes.
		set_transient( self::REINDEX_STATE_TRANSIENT, $jobs, self::REINDEX_STATE_TTL );

		// Release the reindex lock now that the state has been persisted.
		$this->release_reindex_lock( $lock_key );

		return rest_ensure_response(
			[
				'success'     => empty( $errors ),
				'message'     => empty( $errors )
					? __( 'Re-indexing scheduled successfully.', 'onesearch' )
					: implode( "\n", array_column( $errors, 'message' ) ),
				'job_id'      => $job_id,
				'batch_count' => $job->get_progress_total(),
				'jobs'        => $jobs,
			]
		);
	}

	/**
	 * Get the current reindex status for UI persistence.
	 *
	 * Used by the frontend on mount to detect if a reindex was in progress
	 * before a page refresh, so it can restore the progress UI.
	 */
	public function get_reindex_status(): WP_REST_Response {
		$state = $this->get_active_reindex_state();

		return new WP_REST_Response(
			[
				'success' => true,
				'active'  => null !== $state,
				'jobs'    => $state,
			]
		);
	}

	/**
	 * Helper to get the active reindex state, or null if none/broken.
	 *
	 * Validates that the stored job IDs still exist in storage
	 * and haven't reached terminal status. If they're terminal,
	 * the state is auto-cleaned up.
	 *
	 * @return array<int, array{site_name:string, site_url:string, job_id:string}>|null
	 */
	private function get_active_reindex_state(): ?array {
		$state = get_transient( self::REINDEX_STATE_TRANSIENT );

		if ( ! is_array( $state ) || empty( $state ) ) {
			return null;
		}

		$scheduler    = new JobScheduler();
		$all_terminal = true;

		foreach ( $state as $entry ) {
			$job_id     = $entry['job_id'] ?? '';
			$job_status = $scheduler->get_status( $job_id );

			if ( ! $job_status ) {
				// Job data not found in storage — conservatively treat as
				// still active since we can't confirm it has finished.
				$all_terminal = false;
				continue;
			}

			if ( ! in_array( $job_status['status'] ?? '', JobScheduler::TERMINAL_STATUSES, true ) ) {
				$all_terminal = false;
			}
		}

		// If all jobs have finished, clean up the stale state.
		if ( $all_terminal ) {
			delete_transient( self::REINDEX_STATE_TRANSIENT );
			return null;
		}

		return $state;
	}

	/**
	 * Clear the active reindex state — called when a reindex completes
	 * or is cancelled.
	 */
	public static function clear_reindex_state(): void {
		delete_transient( self::REINDEX_STATE_TRANSIENT );
		delete_option( self::REINDEX_STATE_TRANSIENT . '_lock' );
		delete_transient( self::REINDEX_STATE_TRANSIENT . '_lock_expiry' );
	}

	/**
	 * Release the reindex lock acquired at the start of reindex().
	 *
	 * @param string $lock_key The option name used as the mutex lock.
	 */
	private function release_reindex_lock( string $lock_key ): void {
		delete_option( $lock_key );
		delete_transient( $lock_key . '_expiry' );
	}

	/**
	 * Validate the Algolia Key before saving.
	 *
	 * @param string $app_id    The Algolia Application ID.
	 * @param string $write_key The Algolia Write Key.
	 */
	private function validate_algolia_key( string $app_id, string $write_key ): bool {
		try {
			$client = \OneSearch\Vendor\Algolia\AlgoliaSearch\SearchClient::create( $app_id, $write_key );
			// Try to get API key information to check permissions (ACL).
			$key_info = $client->getApiKey( $write_key );

			// Check if key has required write permissions.
			$acl = $key_info['acl'] ?? [];

			// Required permissions for write operations.
			$required_permissions = [ 'addObject', 'deleteObject' ];
			foreach ( $required_permissions as $permission ) {
				if ( ! in_array( $permission, $acl, true ) ) {
					return false;
				}
			}

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get the post types to index for the site.
	 *
	 * @return \WP_Error|string[]
	 */
	private function get_post_types_to_index(): array|\WP_Error {
		// For governing sets, get it from the local options.
		if ( Settings::is_governing_site() ) {
			$opt        = Search_Settings::get_indexable_entities();
			$site_url   = Utils::normalize_url( get_site_url() );
			$post_types = $opt['entities'][ $site_url ] ?? null;

			return is_array( $post_types ) ? array_values( array_unique( array_map( 'strval', $post_types ) ) ) : [];
		}

		// For consumer sites, fetch from parent.
		$parent_url = Settings::get_parent_site_url();
		if ( empty( $parent_url ) ) {
			return new \WP_Error( 'no_parent_url', __( 'Parent site URL not configured.', 'onesearch' ), [ 'status' => 400 ] );
		}

		$brand_config = Governing_Data_Handler::get_brand_config();

		if ( is_wp_error( $brand_config ) ) {
			return $brand_config;
		}

		return $brand_config['indexable_entities'] ?? [];
	}

	/**
	 * Trigger batch reindexing of all child sites (for governing sites).
	 *
	 * @return array{ jobs: array<int,array{site_name:string,site_url:string,job_id:string}>, errors: array<int,array{site_url:string,message:string}> }
	 */
	private function reindex_child_sites(): array {
		$shared_sites = Settings::get_shared_sites();

		$child_jobs   = [];
		$errors       = [];
		$batch_counts = [];
		// Build the requests array for each site.
		foreach ( $shared_sites as $site_data ) {
			if ( empty( $site_data['url'] ) || empty( $site_data['api_key'] ) ) {
				$errors[] = [
					'site_url' => $site_data['url'] ?: '(missing)',
					'message'  => __( 'Missing url or api_key.', 'onesearch' ),
				];
				continue;
			}

			$endpoint = sprintf(
				'%s/wp-json/%s/re-index',
				untrailingslashit( $site_data['url'] ),
				Abstract_REST_Controller::NAMESPACE,
			);

			$response = wp_safe_remote_post(
				$endpoint,
				[
					'headers' => [
						'Accept'            => 'application/json',
						'Content-Type'      => 'application/json',
						'Origin'            => get_site_url(),
						'X-OneSearch-Token' => $site_data['api_key'],
					],
					'timeout' => 45, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Allow longer timeout for reindexing.
				]
			);

			if ( is_wp_error( $response ) ) {
				$errors[] = [
					'site_url' => $site_data['url'],
					// translators: %s is the error message.
					'message'  => sprintf( __( 'Invalid response received. Error %s', 'onesearch' ), esc_html( $response->get_error_message() ) ),
				];
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 !== $code ) {
				$errors[] = [
					'site_url' => $site_data['url'],
					// translators: %s is the error code.
					'message'  => sprintf( esc_html__( 'Failed to connect to the child site. Error code %s', 'onesearch' ), esc_html( (string) $code ) ),
				];
				continue;
			}

			$response_data = json_decode( $body, true );
			if ( null === $response_data || ! is_array( $response_data ) ) {
				$errors[] = [
					'site_url' => $site_data['url'],
					// translators: %s is the error message.
					'message'  => __( 'The site returned an invalid response.', 'onesearch' ),
				];
				continue;
			}

			// Capture the child job ID from the child's response.
			if ( ! empty( $response_data['job_id'] ) ) {
				$child_jobs[]   = [
					'site_name'   => $site_data['name'] ?? $site_data['url'],
					'site_url'    => $site_data['url'],
					'job_id'      => $response_data['job_id'],
					'batch_count' => (int) ( $response_data['batch_count'] ?? 0 ),
				];
				$batch_counts[] = (int) ( $response_data['batch_count'] ?? 0 );
			}
		}

		return [
			'jobs'         => $child_jobs,
			'errors'       => $errors,
			'batch_counts' => $batch_counts,
		];
	}
}
