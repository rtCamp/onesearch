<?php
/**
 * Routes for Search-related operations.
 *
 * @package OneSearch
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Jobs\Reindex_Job;
use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
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
	 * Seconds of inactivity after which a non-terminal job is considered abandoned.
	 */
	private const STALE_JOB_THRESHOLD = 900;

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
						'permission_callback' => [ $this, 'check_api_permissions_except_brands' ],
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
				'permission_callback' => [ $this, 'check_api_permissions_except_brands' ],
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
	 * Same as check_api_permissions(), except the governing site refuses brand tokens.
	 *
	 * Brands only call the governing site to read their config; letting their token
	 * through here would let any brand overwrite the Algolia keys or start a reindex.
	 *
	 * @param \WP_REST_Request<array{}> $request Request.
	 */
	public function check_api_permissions_except_brands( $request ): bool {
		if ( Settings::is_governing_site() && ! empty( $request->get_header( 'X-OneSearch-Token' ) ) ) {
			return false;
		}

		return $this->check_api_permissions( $request );
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

		if ( ! Indexer::validate_credentials( $app_id, $write_key ) ) {
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
	 * The parent Reindex_Job runs in this request to resolve posts and enqueue
	 * child Sync_Jobs; the child Sync_Jobs then run asynchronously via Action Scheduler.
	 * When a governing site starts the reindex on a brand site, the brand's parent
	 * runs in the background too, since the governing site is waiting on the response.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function reindex( $request ): \WP_REST_Response|\WP_Error {
		// add_option() is atomic in MySQL, so it doubles as a mutex against concurrent reindex requests.
		$lock_key = self::REINDEX_STATE_TRANSIENT . '_lock';
		if ( ! add_option( $lock_key, '1', '', false ) ) {
			return new \WP_Error(
				'onesearch_reindex_active',
				__( 'A re-index is already in progress. Cancel it or wait for it to complete before starting a new one.', 'onesearch' ),
				[ 'status' => 409 ]
			);
		}

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

		$post_types = Indexer::get_indexable_post_types();

		// Reported like any other failure, so a governing site can relay the brand's own message.
		if ( is_wp_error( $post_types ) ) {
			$this->release_reindex_lock( $lock_key );

			$errors[] = [
				'site_url' => get_site_url(),
				'message'  => $post_types->get_error_message(),
			];

			return rest_ensure_response(
				[
					'success' => false,
					'message' => implode( "\n", array_column( $errors, 'message' ) ),
					'errors'  => $errors,
				]
			);
		}

		$job = new Reindex_Job();
		$job->set_data(
			[
				'post_types' => $post_types,
				'batch_size' => Reindex_Job::DEFAULT_BATCH_SIZE,
			]
		);
		$job->set_max_retries( 2 );
		$job->set_retry_delay_seconds( 60 );

		$scheduler = new Job_Scheduler();
		$job_id    = $job->get_id();

		$local_site = [
			'site_name'   => Settings::is_governing_site() ? __( 'Governing Site', 'onesearch' ) : get_bloginfo( 'name' ),
			'site_url'    => get_site_url(),
			'job_id'      => $job_id,
			'batch_count' => 0,
		];

		// Recorded up front, so a batch that finishes before this request does still waits for the brand sites.
		if ( Settings::is_governing_site() ) {
			$job->set_data( array_merge( $job->get_data(), [ 'sites' => array_merge( [ $local_site ], $jobs ) ] ) );
		}

		// Only a governing site sends a brand site its token.
		$is_from_governing_site = Settings::is_consumer_site() && ! empty( $request->get_header( 'X-OneSearch-Token' ) );

		try {
			if ( $is_from_governing_site ) {
				// Clearing the old records can outlast the governing site's request timeout, which would lose track of this job.
				$local_site['batch_count'] = Reindex_Job::count_batches( $post_types, Reindex_Job::DEFAULT_BATCH_SIZE );
				$scheduler->schedule( $job );
				Job_Scheduler::dispatch_queue();
			} else {
				$job->mark_running();
				$scheduler->persist_job( $job );
				$job->handle();

				// Once children exist notify_parent() owns the parent lifecycle; re-persisting here would clobber a terminal status.
				if ( ! $job->has_pending_children() ) {
					if ( ! $job->is_finished() ) {
						$job->mark_completed();
					}
					$scheduler->persist_job( $job );
				}

				$local_site['batch_count'] = count( $job->get_child_ids() );
			}
		} catch ( \Throwable $e ) {
			$job->fail( $e->getMessage() );
			$scheduler->persist_job( $job );
			$errors[] = [
				'site_url' => get_site_url(),
				'message'  => $e->getMessage(),
			];
		}

		array_unshift( $jobs, $local_site );

		// The history table reads the combined cross-site total from the governing job's data.
		if ( Settings::is_governing_site() ) {
			$total_batches = (int) array_sum( array_column( $jobs, 'batch_count' ) );

			// Re-read stored state so we never downgrade a terminal status notify_parent() already wrote.
			$latest    = $scheduler->get_status( $job_id );
			$merge_job = $latest ? Reindex_Job::from_array( $latest ) : $job;
			$merge_job->set_data(
				array_merge(
					$merge_job->get_data() ?: [],
					[
						'total_batches' => $total_batches,
						'sites'         => $jobs,
					]
				)
			);
			$scheduler->persist_job( $merge_job );

			// With nothing of its own to index, the run finished before its brand sites did, so it waits for them instead.
			if ( Abstract_Job::STATUS_COMPLETED === $merge_job->get_status() && empty( $merge_job->get_child_ids() ) && count( $jobs ) > 1 ) {
				$scheduler->await_remote_sites( $merge_job->to_array() );
			}
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
				'errors'      => $errors,
				'job_id'      => $job_id,
				'batch_count' => $local_site['batch_count'],
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
	 * Get the reindex state while any of its jobs is still live, clearing it once none is.
	 *
	 * A job stops counting once it's gone, finished, or idle past STALE_JOB_THRESHOLD.
	 *
	 * @return array<int, array{site_name:string, site_url:string, job_id:string}>|null
	 */
	private function get_active_reindex_state(): ?array {
		$state = get_transient( self::REINDEX_STATE_TRANSIENT );

		if ( ! is_array( $state ) || empty( $state ) ) {
			return null;
		}

		$scheduler = new Job_Scheduler();
		$blocking  = false;
		$now       = time();

		foreach ( $state as $entry ) {
			$job_id     = $entry['job_id'] ?? '';
			$job_status = $scheduler->get_status( $job_id );

			// Live jobs are persisted before the lock is released, so a missing row means the job is gone, not blocking.
			if ( ! $job_status ) {
				continue;
			}

			if ( in_array( $job_status['status'] ?? '', Job_Scheduler::TERMINAL_STATUSES, true ) ) {
				continue;
			}

			// Settling a parent bumps updated_at, so 15 idle minutes means the job is wedged or its process died.
			$updated_at = (int) ( $job_status['updated_at'] ?? 0 );
			if ( $updated_at > 0 && ( $now - $updated_at ) > self::STALE_JOB_THRESHOLD ) {
				continue;
			}

			$blocking = true;
		}

		if ( ! $blocking ) {
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
	 * Trigger batch reindexing of all child sites (for governing sites).
	 *
	 * @return array{ jobs: array<int,array{site_name:string,site_url:string,job_id:string,batch_count:int}>, errors: array<int,array{site_url:string,message:string}> }
	 */
	private function reindex_child_sites(): array {
		$shared_sites = Settings::get_shared_sites();

		$child_jobs = [];
		$errors     = [];
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

			if ( empty( $response_data['success'] ) ) {
				$site_messages = is_array( $response_data['errors'] ?? null ) ? array_filter( array_column( $response_data['errors'], 'message' ), 'is_string' ) : [];

				$errors[] = [
					'site_url' => $site_data['url'],
					'message'  => $site_messages ? implode( ' ', $site_messages ) : __( 'The site failed to re-index.', 'onesearch' ),
				];
			}

			if ( ! empty( $response_data['job_id'] ) ) {
				$child_jobs[] = [
					'site_name'   => $site_data['name'] ?? $site_data['url'],
					'site_url'    => $site_data['url'],
					'job_id'      => $response_data['job_id'],
					'batch_count' => (int) ( $response_data['batch_count'] ?? 0 ),
				];
			}
		}

		return [
			'jobs'   => $child_jobs,
			'errors' => $errors,
		];
	}
}
