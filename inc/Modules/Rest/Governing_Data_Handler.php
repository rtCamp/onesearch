<?php
/**
 * Handles cross-site requests for governing brand data.
 *
 * Powered by the Governing_Data_Controller REST endpoint.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Encryptor;
use OneSearch\Modules\Settings\Settings;

/**
 * Class - Governing_Data_Handler
 *
 * @phpstan-type SiteConfig array{
 *  algolia_credentials: array{app_id: string, write_key: string},
 *  search_settings: array{algolia_enabled: bool, searchable_sites: string[]},
 *  indexable_entities: string[],
 *  available_sites: string[],
 * }
 */
class Governing_Data_Handler {
	/**
	 * The transient key used by the consumer sites to cache brand configuration.
	 */
	public const TRANSIENT_KEY = 'onesearch_brand_config_cache';

	/**
	 * Option storing brand-disconnect notices that couldn't be delivered, for the admin to retry.
	 */
	private const OPTION_PENDING_DISCONNECT_NOTICES = 'onesearch_pending_brand_disconnect_notices';

	/**
	 * Option storing a failed governing-site disconnect notice, for the admin to retry.
	 */
	private const OPTION_PENDING_GOVERNING_DISCONNECT = 'onesearch_pending_governing_disconnect_notice';

	/**
	 * Normalized brand site URLs that should not be sent a disconnection notice.
	 *
	 * Populated when a brand site deregisters itself: it has already disconnected.
	 *
	 * @var array<string,bool>
	 */
	private static array $suppressed_disconnect_notices = [];

	/**
	 * Retrieve consolidated brand site configuration with transient caching.
	 *
	 * This method consolidates multiple configuration requests into a single endpoint call.
	 *
	 * @return SiteConfig|\WP_Error
	 */
	public static function get_brand_config(): array|\WP_Error {
		// Only call on brand sites.
		if ( ! Settings::is_consumer_site() ) {
			return new \WP_Error(
				'onesearch_unauthorized_site',
				__( 'The requesting site is not a shared brand site.', 'onesearch' ),
			);
		}

		// Return cached value when available.
		$cached = self::get_brand_config_cache();
		if ( false !== $cached ) {
			/** @var SiteConfig $cached */
			return $cached;
		}

		// If no parent is configured, return an error.
		$parent_url = Settings::get_parent_site_url();
		if ( empty( $parent_url ) ) {
			return new \WP_Error(
				'onesearch_no_parent',
				__( 'No governing site is configured.', 'onesearch' ),
				[ 'status' => 500 ]
			);
		}

		// Child authenticating to the governing site.
		$our_public_key = Settings::get_api_key();
		if ( empty( $our_public_key ) ) {
			return new \WP_Error(
				'onesearch_no_key',
				__( 'No API key is configured.', 'onesearch' ),
				[ 'status' => 500 ]
			);
		}

		$endpoint = sprintf(
			'%s/wp-json/%s/brand-config',
			untrailingslashit( $parent_url ),
			Abstract_REST_Controller::NAMESPACE,
		);

		$response = wp_safe_remote_get(
			$endpoint,
			[
				'headers' => [
					'Accept'            => 'application/json',
					'Content-Type'      => 'application/json',
					'Origin'            => get_site_url(),
					'X-OneSearch-Token' => $our_public_key,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'onesearch_rest_failed_to_connect',
				__( 'Failed to connect to the governing site.', 'onesearch' ),
				[
					'status' => $code,
					'body'   => $body,
				]
			);
		}

		$response_data = json_decode( $body, true );
		if ( null === $response_data || ! is_array( $response_data ) ) {
			return new \WP_Error(
				'onesearch_rest_invalid_response',
				__( 'The governing site returned an invalid response.', 'onesearch' ),
				[ 'status' => 500 ]
			);
		}

		// Validate and structure the response.
		$algolia_creds = is_array( $response_data['algolia_credentials'] ?? null )
			? $response_data['algolia_credentials']
			: [];

		$search_settings = is_array( $response_data['search_settings'] ?? null )
			? $response_data['search_settings']
			: [];

		$indexable_entities = is_array( $response_data['indexable_entities'] ?? null )
			? $response_data['indexable_entities']
			: [];

		$available_sites = is_array( $response_data['available_sites'] ?? null )
			? $response_data['available_sites']
			: [];

		$config = [
			'algolia_credentials' => [
				'app_id'    => is_string( $algolia_creds['app_id'] ?? null ) ? sanitize_text_field( $algolia_creds['app_id'] ) : '',
				'write_key' => is_string( $algolia_creds['write_key'] ?? null ) ? sanitize_text_field( $algolia_creds['write_key'] ) : '',
			],
			'search_settings'     => [
				'algolia_enabled'  => ! empty( $search_settings['algolia_enabled'] ),
				'searchable_sites' => is_array( $search_settings['searchable_sites'] ?? null )
					? array_values( array_filter( array_map( 'sanitize_text_field', $search_settings['searchable_sites'] ), 'is_string' ) )
					: [],
			],
			'indexable_entities'  => array_values( array_filter( array_map( 'sanitize_text_field', $indexable_entities ), 'is_string' ) ),
			'available_sites'     => array_values( array_filter( array_map( 'sanitize_text_field', $available_sites ), 'is_string' ) ),
		];

		self::set_brand_config_cache( $config );

		return $config;
	}

	/**
	 * Gets available public post types for child sites.
	 *
	 * @return \WP_Error|array{
	 *   sites: array<string, array{
	 *     site_name: string,
	 *     site_url: string,
	 *     post_types: array{
	 *       slug: string,
	 *       label: string,
	 *       restBase: string,
	 *     }[],
	 *   }>,
	 *   errors: array{site_url: string, message: string}[],
	 * }
	 */
	public static function get_all_brand_post_types(): array|\WP_Error {
		// Only call on Governing sites.
		if ( ! Settings::is_governing_site() ) {
			return new \WP_Error(
				'onesearch_unauthorized_site',
				__( 'The requesting site is not a governing site.', 'onesearch' ),
			);
		}

		$shared_sites = Settings::get_shared_sites();

		$all_sites = [];
		$errors    = [];
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
				'%s/wp-json/%s/all-post-types',
				untrailingslashit( $site_data['url'] ),
				Abstract_REST_Controller::NAMESPACE,
			);

			$response = wp_safe_remote_get(
				$endpoint,
				[
					'headers' => [
						'Accept'            => 'application/json',
						'Content-Type'      => 'application/json',
						'Origin'            => get_site_url(),
						'X-OneSearch-Token' => $site_data['api_key'],
					],
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

			foreach ( $response_data['sites'] as $site_url => $site_data ) {
				if ( ! is_array( $site_data ) ) {
					continue;
				}
				/** @var array{
				 *   site_name: string,
				 *   site_url: string,
				 *   post_types: array{
				 *     slug: string,
				 *     label: string,
				 *     restBase: string,
				 *   }[],
				 * } $site_data
				 */
				$all_sites[ $site_url ] = $site_data;
			}
		}

		return [
			'sites'  => $all_sites,
			'errors' => $errors,
		];
	}

	/**
	 * Deregisters this brand site from its governing site.
	 *
	 * @return true|\WP_Error True on success, WP_Error when the governing site could not be told.
	 */
	public static function deregister_from_governing_site(): true|\WP_Error {
		if ( ! Settings::is_consumer_site() ) {
			return new \WP_Error(
				'onesearch_unauthorized_site',
				__( 'Only brand sites can disconnect from a governing site.', 'onesearch' ),
			);
		}

		$parent_url = Settings::get_parent_site_url();
		if ( empty( $parent_url ) ) {
			return new \WP_Error(
				'onesearch_no_parent',
				__( 'No governing site is configured.', 'onesearch' ),
			);
		}

		$our_public_key = Settings::get_api_key();
		if ( empty( $our_public_key ) ) {
			return new \WP_Error(
				'onesearch_no_key',
				__( 'No API key is configured.', 'onesearch' ),
			);
		}

		$response = self::request_disconnect( $parent_url, $our_public_key, Governing_Data_Controller::ROUTE_REMOVE_BRAND );

		$error = self::get_disconnect_error( $response );
		if ( null === $error ) {
			self::clear_pending_governing_disconnect();
			return true;
		}

		self::record_pending_governing_disconnect( $parent_url, $our_public_key, $error );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new \WP_Error(
			'onesearch_rest_failed_to_connect',
			__( 'The governing site could not be notified of the disconnection.', 'onesearch' ),
			[
				'status' => wp_remote_retrieve_response_code( $response ),
				'body'   => wp_remote_retrieve_body( $response ),
			]
		);
	}

	/**
	 * Tells brand sites that they are no longer governed by this site.
	 *
	 * Best effort: the sites have already been dropped from the option by the time this
	 * runs, so a notice that fails is reported rather than undone.
	 *
	 * @param array<string,string> $removed_sites Map of normalized brand site URL to its (decrypted) API key.
	 * @param array<string,string> $site_names    Map of normalized brand site URL to its display name,
	 *                                             for the admin notice if the notice fails. Falls back
	 *                                             to the URL for any site missing from this map.
	 */
	public static function notify_brand_sites_of_disconnection( array $removed_sites, array $site_names = [] ): void {
		foreach ( $removed_sites as $site_url => $api_key ) {
			if ( isset( self::$suppressed_disconnect_notices[ $site_url ] ) ) {
				unset( self::$suppressed_disconnect_notices[ $site_url ] );
				self::clear_pending_disconnect_notice( $site_url );
				continue;
			}

			if ( empty( $site_url ) || empty( $api_key ) ) {
				continue;
			}

			$error = self::get_disconnect_error( self::request_disconnect( $site_url, $api_key, Governing_Data_Controller::ROUTE_REMOVE_GOVERNING ) );
			if ( null === $error ) {
				self::clear_pending_disconnect_notice( $site_url );
				continue;
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- @todo Surface this better with a Logger class.
			error_log(
				sprintf(
					'OneSearch: %1$s could not be told that it is no longer governed by this site: %2$s',
					$site_url,
					$error
				)
			);

			do_action( 'onesearch_brand_disconnect_notice_failed', $site_url, $error );

			self::record_pending_disconnect_notice( $site_url, $site_names[ $site_url ] ?? $site_url, $api_key, $error );
		}
	}

	/**
	 * Describes why a disconnection notice failed, or null when it got through.
	 *
	 * @param array<string,mixed>|\WP_Error $response The response to the notice.
	 */
	private static function get_disconnect_error( $response ): ?string {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return 200 === $code ? null : sprintf( 'HTTP %d', $code );
	}

	/**
	 * Skips the outbound disconnection notice for a brand site.
	 *
	 * @param string $site_url Normalized brand site URL.
	 */
	public static function suppress_disconnect_notice( string $site_url ): void {
		self::$suppressed_disconnect_notices[ $site_url ] = true;
	}

	/**
	 * Persists a failed disconnect notice for the admin to retry.
	 *
	 * @param string $site_url  Normalized brand site URL that could not be notified.
	 * @param string $site_name The site's display name, for the admin notice.
	 * @param string $api_key   The (decrypted) API key to notify it with.
	 * @param string $error     Description of why the notice failed.
	 */
	private static function record_pending_disconnect_notice( string $site_url, string $site_name, string $api_key, string $error ): void {
		$pending = self::get_raw_pending_disconnect_notices();

		$attempts             = isset( $pending[ $site_url ] ) ? (int) $pending[ $site_url ]['attempts'] : 0;
		$pending[ $site_url ] = [
			'name'       => $site_name,
			'api_key'    => Encryptor::encrypt( $api_key ) ?: '',
			'attempts'   => $attempts + 1,
			'last_error' => $error,
			'updated_at' => time(),
		];

		update_option( self::OPTION_PENDING_DISCONNECT_NOTICES, $pending, false );
	}

	/**
	 * Clears a pending disconnect notice, e.g. once it has gone through.
	 *
	 * @param string $site_url Normalized brand site URL.
	 */
	private static function clear_pending_disconnect_notice( string $site_url ): void {
		$pending = self::get_raw_pending_disconnect_notices();

		if ( ! isset( $pending[ $site_url ] ) ) {
			return;
		}

		unset( $pending[ $site_url ] );
		update_option( self::OPTION_PENDING_DISCONNECT_NOTICES, $pending, false );
	}

	/**
	 * Retries a single pending brand-disconnect notice, triggered by the admin.
	 *
	 * @param string $site_url Normalized brand site URL to retry.
	 *
	 * @return bool True if the notice went through (or there was nothing pending for this
	 *              site), false if it still failed.
	 */
	public static function retry_pending_disconnect_notice( string $site_url ): bool {
		$pending = self::get_raw_pending_disconnect_notices();

		if ( ! isset( $pending[ $site_url ] ) ) {
			return true;
		}

		/*
		 * A notice outlives the disconnection it describes, so the brand may have been
		 * added back since. Replaying the request then tears down that live pairing and
		 * leaves it one-sided, which is the very thing this teardown exists to prevent.
		 */
		if ( null !== Settings::get_shared_site_by_url( $site_url ) ) {
			unset( $pending[ $site_url ] );
			update_option( self::OPTION_PENDING_DISCONNECT_NOTICES, $pending, false );
			return true;
		}

		$notice  = $pending[ $site_url ];
		$api_key = ! empty( $notice['api_key'] ) ? ( Encryptor::decrypt( $notice['api_key'] ) ?: '' ) : '';

		if ( empty( $api_key ) ) {
			unset( $pending[ $site_url ] );
			update_option( self::OPTION_PENDING_DISCONNECT_NOTICES, $pending, false );
			return true;
		}

		$error = self::get_disconnect_error( self::request_disconnect( $site_url, $api_key, Governing_Data_Controller::ROUTE_REMOVE_GOVERNING ) );
		if ( null === $error ) {
			unset( $pending[ $site_url ] );
			update_option( self::OPTION_PENDING_DISCONNECT_NOTICES, $pending, false );
			return true;
		}

		$pending[ $site_url ]['attempts']   = (int) $notice['attempts'] + 1;
		$pending[ $site_url ]['last_error'] = $error;
		update_option( self::OPTION_PENDING_DISCONNECT_NOTICES, $pending, false );

		return false;
	}

	/**
	 * Brand-disconnect notices that could not be delivered, for display to the admin.
	 *
	 * @return array<string,array{name:string,attempts:int,last_error:string,updated_at:int}>
	 */
	public static function get_pending_disconnect_notices(): array {
		$notices      = [];
		$shared_sites = Settings::get_shared_sites();

		foreach ( self::get_raw_pending_disconnect_notices() as $site_url => $notice ) {
			// The brand was added back, so the disconnection this describes no longer holds.
			if ( isset( $shared_sites[ $site_url ] ) ) {
				continue;
			}

			$notices[ $site_url ] = [
				'name'       => ! empty( $notice['name'] ) ? (string) $notice['name'] : $site_url,
				'attempts'   => (int) $notice['attempts'],
				'last_error' => (string) $notice['last_error'],
				'updated_at' => (int) $notice['updated_at'],
			];
		}

		return $notices;
	}

	/**
	 * Raw pending-disconnect-notices option value.
	 *
	 * @return array<string,array{name:string,api_key:string,attempts:int,last_error:string,updated_at:int}>
	 */
	private static function get_raw_pending_disconnect_notices(): array {
		$pending = get_option( self::OPTION_PENDING_DISCONNECT_NOTICES, [] );

		return is_array( $pending ) ? $pending : [];
	}

	/**
	 * Persists a failed governing-site disconnect notice for the admin to retry.
	 *
	 * @param string $parent_url The governing site's URL.
	 * @param string $api_key    The (decrypted) API key to notify it with.
	 * @param string $error      Description of why the notice failed.
	 */
	private static function record_pending_governing_disconnect( string $parent_url, string $api_key, string $error ): void {
		$existing = self::get_raw_pending_governing_disconnect();
		$attempts = ! empty( $existing ) ? (int) $existing['attempts'] : 0;

		update_option(
			self::OPTION_PENDING_GOVERNING_DISCONNECT,
			[
				'url'        => $parent_url,
				'api_key'    => Encryptor::encrypt( $api_key ) ?: '',
				'attempts'   => $attempts + 1,
				'last_error' => $error,
				'updated_at' => time(),
			],
			false
		);
	}

	/**
	 * Clears the pending governing-site disconnect notice, e.g. once it has gone through.
	 */
	private static function clear_pending_governing_disconnect(): void {
		delete_option( self::OPTION_PENDING_GOVERNING_DISCONNECT );
	}

	/**
	 * Retries the pending governing-site disconnect notice, triggered by the admin.
	 *
	 * @return bool True if the notice went through (or there was nothing pending), false if it still failed.
	 */
	public static function retry_pending_governing_disconnect(): bool {
		$pending = self::get_raw_pending_governing_disconnect();

		if ( empty( $pending ) ) {
			return true;
		}

		$api_key = ! empty( $pending['api_key'] ) ? ( Encryptor::decrypt( $pending['api_key'] ) ?: '' ) : '';

		if ( empty( $api_key ) || empty( $pending['url'] ) ) {
			self::clear_pending_governing_disconnect();
			return true;
		}

		/*
		 * This site has since paired with the same governing site again - by health check
		 * or by hand - so replaying the request would tear down that live pairing and
		 * leave it one-sided. A notice naming a different governing site still stands.
		 */
		if ( self::is_pending_governing_disconnect_stale( (string) $pending['url'] ) ) {
			self::clear_pending_governing_disconnect();
			return true;
		}

		$error = self::get_disconnect_error( self::request_disconnect( $pending['url'], $api_key, Governing_Data_Controller::ROUTE_REMOVE_BRAND ) );
		if ( null === $error ) {
			self::clear_pending_governing_disconnect();
			return true;
		}

		$pending['attempts']   = (int) $pending['attempts'] + 1;
		$pending['last_error'] = $error;
		update_option( self::OPTION_PENDING_GOVERNING_DISCONNECT, $pending, false );

		return false;
	}

	/**
	 * The undelivered disconnections this site should warn its admin about.
	 *
	 * @return array<int,array{site_url:string,message:string}>
	 */
	public static function get_pending_disconnects_for_admin(): array {
		if ( Settings::is_consumer_site() ) {
			$pending = self::get_pending_governing_disconnect();

			if ( null === $pending || self::is_pending_governing_disconnect_stale( $pending['url'] ) ) {
				return [];
			}

			return [
				[
					'site_url' => '',
					'message'  => sprintf(
						/* translators: %s: governing site URL. */
						__( 'The governing site "%s" could not be notified that this site disconnected, and may still list this site as connected.', 'onesearch' ),
						$pending['url']
					),
				],
			];
		}

		if ( ! Settings::is_governing_site() ) {
			return [];
		}

		$notices = [];

		foreach ( self::get_pending_disconnect_notices() as $site_url => $notice ) {
			$notices[] = [
				'site_url' => (string) $site_url,
				'message'  => sprintf(
					/* translators: %s: brand site name. */
					__( 'The "%s" couldn\'t be notified that it was disconnected.', 'onesearch' ),
					$notice['name']
				),
			];
		}

		return $notices;
	}

	/**
	 * Retries one undelivered disconnection on behalf of the admin.
	 *
	 * @param string $site_url Brand site to retry, or an empty string for the governing site.
	 *
	 * @return bool True if it went through (or there was nothing pending), false if it failed again.
	 */
	public static function retry_pending_disconnect( string $site_url ): bool {
		if ( '' === $site_url ) {
			return self::retry_pending_governing_disconnect();
		}

		return self::retry_pending_disconnect_notice( $site_url );
	}

	/**
	 * The pending governing-site disconnect notice, for display to the admin.
	 *
	 * @return array{url:string,attempts:int,last_error:string,updated_at:int}|null
	 */
	public static function get_pending_governing_disconnect(): ?array {
		$pending = self::get_raw_pending_governing_disconnect();

		if ( empty( $pending ) ) {
			return null;
		}

		return [
			'url'        => (string) $pending['url'],
			'attempts'   => (int) $pending['attempts'],
			'last_error' => (string) $pending['last_error'],
			'updated_at' => (int) $pending['updated_at'],
		];
	}

	/**
	 * Whether a pending governing-disconnect notice has been overtaken by a new pairing
	 * with the same governing site, making the disconnect it describes no longer true.
	 *
	 * Only meaningful once the disconnection has run to completion: the parent option is
	 * still in place while the notice is being recorded.
	 *
	 * @param string $pending_url The governing site URL the notice was recorded for.
	 */
	public static function is_pending_governing_disconnect_stale( string $pending_url ): bool {
		$parent_url = Settings::get_parent_site_url();

		return ! empty( $parent_url )
			&& untrailingslashit( $parent_url ) === untrailingslashit( $pending_url );
	}

	/**
	 * Raw pending-governing-disconnect option value.
	 *
	 * @return array{url:string,api_key:string,attempts:int,last_error:string,updated_at:int}|array{}
	 */
	private static function get_raw_pending_governing_disconnect(): array {
		$pending = get_option( self::OPTION_PENDING_GOVERNING_DISCONNECT, [] );

		return is_array( $pending ) ? $pending : [];
	}

	/**
	 * Sends a disconnection request to the paired site.
	 *
	 * The two roles expose different routes, so the caller picks the one the
	 * receiving site registers.
	 *
	 * @param string $site_url The URL of the site to disconnect from.
	 * @param string $api_key  The API key used to authenticate against that site.
	 * @param string $route    Route on the receiving site, a Governing_Data_Controller::ROUTE_REMOVE_* constant.
	 *
	 * @return array<string,mixed>|\WP_Error The response, or WP_Error on failure.
	 */
	private static function request_disconnect( string $site_url, string $api_key, string $route ) {
		$endpoint = sprintf(
			'%s/wp-json/%s%s',
			untrailingslashit( $site_url ),
			Abstract_REST_Controller::NAMESPACE,
			$route,
		);

		return wp_safe_remote_request(
			$endpoint,
			[
				'method'  => \WP_REST_Server::DELETABLE,
				'timeout' => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- The pairing teardown must be confirmed before reporting back.
				'headers' => [
					'Accept'            => 'application/json',
					'Content-Type'      => 'application/json',
					'Origin'            => get_site_url(),
					'X-OneSearch-Token' => $api_key,
				],
			]
		);
	}

	/**
	 * Clear the cached brand configuration.
	 *
	 * @param ?string $site_url Optional site URL to clear cache for a specific site. If null, clears cache for all shared sites.
	 */
	public static function clear_brand_config_cache( ?string $site_url = null ): void {
		if ( ! Settings::is_governing_site() ) {
			delete_transient( self::TRANSIENT_KEY );
			return;
		}

		$shared_sites = Settings::get_shared_sites();

		// If a specific site URL is provided, we'll just target that one.
		if ( ! empty( $site_url ) && isset( $shared_sites[ $site_url ] ) ) {
			$shared_sites = [ $shared_sites[ $site_url ] ];
		}

		foreach ( $shared_sites as $site_data ) {
			if ( empty( $site_data['url'] ) || empty( $site_data['api_key'] ) ) {
				continue;
			}

			// Clear cache on each shared site.
			$endpoint = sprintf(
				'%s/wp-json/%s/brand-config',
				untrailingslashit( $site_data['url'] ),
				Abstract_REST_Controller::NAMESPACE,
			);

			wp_safe_remote_post(
				$endpoint,
				[
					'method'   => \WP_REST_Server::DELETABLE,
					'headers'  => [
						'Accept'            => 'application/json',
						'Content-Type'      => 'application/json',
						'Origin'            => get_site_url(),
						'X-OneSearch-Token' => $site_data['api_key'],
					],
					// Don't wait to see if the cache flush was successful.
					'blocking' => false,
				]
			);
		}
	}

	/**
	 * Sets the cached config transient, encrypting any creds.
	 *
	 * @param array<string,mixed> $config The site configuration.
	 * @phpstan-param SiteConfig $config
	 */
	private static function set_brand_config_cache( array $config ): void {
		// Encrypt the algolia keys before caching.
		if ( ! empty( $config['algolia_credentials']['write_key'] ) ) {
			$config['algolia_credentials']['write_key'] = Encryptor::encrypt( $config['algolia_credentials']['write_key'] );
		}

		// Cache for 1 week.
		set_transient( self::TRANSIENT_KEY, $config, WEEK_IN_SECONDS );
	}

	/**
	 * Gets the cached transient, decrypting any creds.
	 *
	 * @return SiteConfig|false
	 */
	private static function get_brand_config_cache(): array|false {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false === $cached || ! is_array( $cached ) ) {
			return false;
		}

		// Decrypt the algolia keys before returning.
		if ( ! empty( $cached['algolia_credentials']['write_key'] ) ) {
			$cached['algolia_credentials']['write_key'] = Encryptor::decrypt( $cached['algolia_credentials']['write_key'] );
		}

		/** @var SiteConfig $cached */
		return $cached;
	}
}
