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

		$response = self::request_disconnect( $parent_url, $our_public_key );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'onesearch_rest_failed_to_connect',
				__( 'The governing site could not be notified of the disconnection.', 'onesearch' ),
				[
					'status' => $code,
					'body'   => wp_remote_retrieve_body( $response ),
				]
			);
		}

		return true;
	}

	/**
	 * Tells brand sites that they are no longer governed by this site.
	 *
	 * @param array<string,string> $removed_sites Map of normalized brand site URL to its (decrypted) API key.
	 */
	public static function notify_brand_sites_of_disconnection( array $removed_sites ): void {
		foreach ( $removed_sites as $site_url => $api_key ) {
			if ( isset( self::$suppressed_disconnect_notices[ $site_url ] ) ) {
				unset( self::$suppressed_disconnect_notices[ $site_url ] );
				continue;
			}

			if ( empty( $site_url ) || empty( $api_key ) ) {
				continue;
			}

			self::request_disconnect( $site_url, $api_key );
		}
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
	 * Sends a disconnection request to the paired site.
	 *
	 * @param string $site_url The URL of the site to disconnect from.
	 * @param string $api_key  The API key used to authenticate against that site.
	 *
	 * @return array<string,mixed>|\WP_Error The response, or WP_Error on failure.
	 */
	private static function request_disconnect( string $site_url, string $api_key ) {
		$endpoint = sprintf(
			'%s/wp-json/%s/connection',
			untrailingslashit( $site_url ),
			Abstract_REST_Controller::NAMESPACE,
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
