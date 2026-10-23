<?php
/**
 * Sends the requests between a governing site and its brand sites.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Encryptor;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - Remote_Client
 *
 * @phpstan-type SiteConfig array{
 *  algolia_credentials: array{app_id: string, write_key: string},
 *  search_settings: array{algolia_enabled: bool, searchable_sites: string[]},
 *  indexable_entities: string[],
 *  available_sites: string[],
 * }
 */
class Remote_Client {
	/**
	 * The transient key used by the consumer sites to cache brand configuration.
	 */
	public const TRANSIENT_KEY = 'onesearch_brand_config_cache';

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

		$response = self::request( $parent_url, 'brand-site/config', $our_public_key );

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
	 * Asks the governing site to remove this brand site from its shared sites.
	 *
	 * @return true|\WP_Error True if the governing site removed this site.
	 */
	public static function deregister_from_governing_site(): true|\WP_Error {
		// Only call on brand sites.
		if ( ! Settings::is_consumer_site() ) {
			return new \WP_Error(
				'onesearch_unauthorized_site',
				__( 'The requesting site is not a shared brand site.', 'onesearch' ),
			);
		}

		$parent_url = Settings::get_parent_site_url();
		if ( empty( $parent_url ) ) {
			return new \WP_Error(
				'onesearch_no_parent',
				__( 'No governing site is configured.', 'onesearch' ),
			);
		}

		// Child authenticating to the governing site.
		$our_public_key = Settings::get_api_key();
		if ( empty( $our_public_key ) ) {
			return new \WP_Error(
				'onesearch_no_key',
				__( 'No API key is configured.', 'onesearch' ),
			);
		}

		$response = self::request(
			$parent_url,
			'brand-site',
			$our_public_key,
			[
				'method' => \WP_REST_Server::DELETABLE,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// The governing site only authenticates brand sites it lists with a matching key.
		if ( 401 === $code || 403 === $code ) {
			return new \WP_Error(
				'onesearch_unrecognized_site',
				__( 'The governing site does not recognize this site.', 'onesearch' ),
				[ 'status' => $code ]
			);
		}

		if ( 200 !== $code ) {
			return new \WP_Error(
				'onesearch_rest_failed_to_connect',
				__( 'Failed to connect to the governing site.', 'onesearch' ),
				[
					'status' => $code,
					'body'   => wp_remote_retrieve_body( $response ),
				]
			);
		}

		return true;
	}

	/**
	 * Tells a brand site to forget this governing site, so it drops the connection and its cached configuration.
	 *
	 * Failures are logged, since a brand site that can't be reached can still be disconnected from its own settings.
	 *
	 * @param array{url: string, api_key: string} $site_data The brand site, with its stored key.
	 *
	 * @return bool True if the brand site confirmed it.
	 */
	public static function disconnect_brand_site( array $site_data ): bool {
		$response = self::request_brand_site(
			$site_data,
			'governing-site',
			[
				'method'  => \WP_REST_Server::DELETABLE,
				'timeout' => 5, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Only runs when an admin removes a brand site or uninstalls the plugin.
			]
		);

		if ( is_wp_error( $response ) ) {
			Utils::log( sprintf( 'Could not disconnect the brand site %s: %s', $site_data['url'], $response->get_error_message() ) );
			return false;
		}

		return true;
	}

	/**
	 * Runs a brand site's health check, which also connects it to this governing site.
	 *
	 * @param string $url     The brand site's URL.
	 * @param string $api_key The brand site's API key.
	 *
	 * @return true|\WP_Error True if the brand site accepted the key.
	 */
	public static function check_brand_site( string $url, string $api_key ): true|\WP_Error {
		$response = self::request(
			$url,
			'governing-site',
			$api_key,
			[
				'method'  => \WP_REST_Server::CREATABLE,
				'timeout' => 5, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- An admin is waiting for the answer.
			]
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return true;
		}

		if ( 401 === $code || 403 === $code ) {
			return new \WP_Error( 'onesearch_bad_key', __( 'The brand site rejected this API key. Copy the key from the brand site\'s OneSearch settings and try again.', 'onesearch' ), [ 'status' => 400 ] );
		}

		if ( 409 === $code ) {
			return new \WP_Error( 'onesearch_already_connected', __( 'This brand site is already connected to a different governing site. Disconnect it there first, then try again.', 'onesearch' ), [ 'status' => 409 ] );
		}

		return new \WP_Error( 'onesearch_health_check_failed', __( 'Could not reach the brand site. Check the URL, and that OneSearch is active there, then try again.', 'onesearch' ), [ 'status' => 502 ] );
	}

	/**
	 * Gets available public post types for brand sites.
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
		foreach ( $shared_sites as $site_data ) {
			$response = self::request_brand_site( $site_data, 'all-post-types' );

			if ( is_wp_error( $response ) ) {
				$errors[] = [
					'site_url' => $site_data['url'],
					'message'  => $response->get_error_message(),
				];
				continue;
			}

			$response_data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( null === $response_data || ! is_array( $response_data ) ) {
				$errors[] = [
					'site_url' => $site_data['url'],
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
	 * Tells a brand site to re-index itself.
	 *
	 * The brand site only queues the indexing run.
	 *
	 * @param array{url: string, api_key: string} $site_data The brand site, with its stored key.
	 *
	 * @return true|\WP_Error True if the brand site queued it.
	 */
	public static function reindex_brand_site( array $site_data ): true|\WP_Error {
		$response = self::request_brand_site( $site_data, 'indexer', [ 'method' => \WP_REST_Server::CREATABLE ] );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Gets the progress of a brand site's latest indexing run from the brand site.
	 *
	 * @param array{url: string, api_key: string} $site_data The brand site, with its stored key.
	 *
	 * @return mixed|\WP_Error The progress the brand site gave, unchecked.
	 */
	public static function get_brand_index_progress( array $site_data ): mixed {
		$response = self::request_brand_site( $site_data, 'indexer' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$sites = is_array( $body ) && is_array( $body['sites'] ?? null ) ? $body['sites'] : [];

		// A brand site only has its own.
		return [] !== $sites ? reset( $sites ) : null;
	}

	/**
	 * Clears this brand site's cached configuration.
	 */
	public static function clear_brand_config_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Tells a brand site to clear its cached configuration.
	 *
	 * @param array{url: string, api_key: string} $site_data The brand site, with its stored key.
	 *
	 * @return true|\WP_Error True if the brand site cleared it.
	 */
	public static function clear_brand_site_config_cache( array $site_data ): true|\WP_Error {
		$response = self::request_brand_site( $site_data, 'governing-site/config', [ 'method' => \WP_REST_Server::DELETABLE ] );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Sends a request to a OneSearch route on a brand site, and fails unless the brand site answers with a 200.
	 *
	 * @param array{url: string, api_key: string} $site_data The brand site, with its stored key.
	 * @param string                              $route     The route, relative to the OneSearch namespace.
	 * @param array<string,mixed>                 $args      Extra request arguments, like the method.
	 *
	 * @return array<string,mixed>|\WP_Error The response, or the brand site's error.
	 */
	private static function request_brand_site( array $site_data, string $route, array $args = [] ): array|\WP_Error {
		if ( empty( $site_data['url'] ) || empty( $site_data['api_key'] ) ) {
			return new \WP_Error( 'onesearch_missing_site_data', __( 'Missing url or api_key.', 'onesearch' ) );
		}

		$response = self::request( $site_data['url'], $route, $site_data['api_key'], $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$site_error = json_decode( wp_remote_retrieve_body( $response ), true );

			return new \WP_Error(
				'onesearch_rest_failed_to_connect',
				// translators: %s is the error code.
				is_string( $site_error['message'] ?? null ) ? $site_error['message'] : sprintf( __( 'Failed to connect to the brand site. Error code %s', 'onesearch' ), (string) $code ),
				[ 'status' => $code ]
			);
		}

		return $response;
	}

	/**
	 * Sends a request to a OneSearch route on another site, signed with the API key that site checks.
	 *
	 * @param string              $site_url The other site's URL.
	 * @param string              $route    The route, relative to the OneSearch namespace.
	 * @param string              $api_key  The API key the other site checks.
	 * @param array<string,mixed> $args     Extra request arguments, like the method and timeout.
	 *
	 * @return array<string,mixed>|\WP_Error The response.
	 */
	private static function request( string $site_url, string $route, string $api_key, array $args = [] ): array|\WP_Error {
		return wp_safe_remote_request(
			sprintf( '%s/wp-json/%s/%s', untrailingslashit( $site_url ), Abstract_REST_Controller::NAMESPACE, $route ),
			$args + [
				'headers' => [
					'Accept'               => 'application/json',
					'Content-Type'         => 'application/json',
					'X-OneSearch-Token'    => $api_key,
					'X-OneSearch-Site-URL' => Utils::get_current_site_url(),
				],
			]
		);
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
