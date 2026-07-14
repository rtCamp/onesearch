<?php
/**
 * Base REST controller class.
 *
 * Includes the shared namespace, version and hook registration.
 *
 * @package OneSearch\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Rest;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
use WP_REST_Controller;

/**
 * Class - Abstract_REST_Controller
 */
abstract class Abstract_REST_Controller extends WP_REST_Controller implements Registrable {
	/**
	 * The namespace for the REST API.
	 */
	public const NAMESPACE = 'onesearch/v1';

	/**
	 * {@inheritDoc}
	 *
	 * Reuses the namespace constant.
	 *
	 * @var string
	 */
	protected $namespace = self::NAMESPACE;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * We throw an exception here to force the child class to implement this method.
	 *
	 * @throws \Exception If method not implemented.
	 *
	 * @codeCoverageIgnore
	 */
	public function register_routes(): void {
		throw new \Exception( __FUNCTION__ . ' Method not implemented.' );
	}

	/**
	 * Checks for the use of the OneDesign API key in the request headers.
	 *
	 * @todo this should be on a hook.
	 *
	 * @param \WP_REST_Request<array{}> $request Request.
	 */
	public function check_api_permissions( $request ): bool {
		$request_origin = $request->get_header( 'origin' );
		$request_origin = ! empty( $request_origin ) ? esc_url_raw( wp_unslash( $request_origin ) ) : '';
		$parsed_origin  = wp_parse_url( $request_origin );
		$parsed_origin  = is_array( $parsed_origin ) ? $parsed_origin : [];
		$request_url    = ! empty( $parsed_origin['scheme'] ) && ! empty( $parsed_origin['host'] )
			? Utils::normalize_url( $request_origin )
			: '';

		// Browsers omit the default port from Origin, so treat a missing port as 80
		// (matches the URL-side default in ::is_url_from_host).
		$origin_port = isset( $parsed_origin['port'] ) ? (int) $parsed_origin['port'] : 80;

		// See if the `X-OneSearch-Token` header is present.
		// Token-based auth takes priority so that cross-site requests from sub-directory
		// multisite installs (where Origin loses the path and same-host detection fires
		// on sibling sub-sites) are validated by key rather than by logged-in user.
		$token = $request->get_header( 'X-OneSearch-Token' );
		$token = ! empty( $token ) ? sanitize_text_field( wp_unslash( $token ) ) : '';

		if ( ! empty( $token ) ) {
			// When Origin is absent (same-origin browser requests in sub-directory multisite,
			// where the browser omits Origin for same-host fetches), fall back to the
			// explicitly-sent site URL header so token auth can still proceed.
			if ( empty( $request_url ) ) {
				$site_url_header = $request->get_header( 'X-OneSearch-Site-URL' );
				if ( ! empty( $site_url_header ) ) {
					$request_origin = esc_url_raw( wp_unslash( $site_url_header ) );
					$parsed_origin  = wp_parse_url( $request_origin );
					$parsed_origin  = is_array( $parsed_origin ) ? $parsed_origin : [];
					$request_url    = ! empty( $parsed_origin['scheme'] ) && ! empty( $parsed_origin['host'] )
						? Utils::normalize_url( $request_origin )
						: '';
					$origin_port    = isset( $parsed_origin['port'] ) ? (int) $parsed_origin['port'] : 80;
				}
			}

			if ( empty( $request_url ) ) {
				return false;
			}

			// $request_url is already normalized above via Utils::normalize_url().
			$stored_key = $this->get_stored_api_key( $request_url );
			if ( empty( $stored_key ) || ! hash_equals( $stored_key, $token ) ) {
				return false;
			}

			// Governing sites were checked by ::get_stored_api_key already.
			if ( Settings::is_governing_site() ) {
				return true;
			}

			// If it's not a healthcheck, compare the origins.
			$governing_site_url = Settings::get_parent_site_url();
			if ( '/' . $this->namespace . '/health-check' !== $request->get_route() ) {
				return ! empty( $governing_site_url ) ? $this->is_url_from_host( $governing_site_url, $parsed_origin['host'], $origin_port ) : false;
			}

			// For health-checks, if no governing site is set, we set it now.
			Settings::set_parent_site_url( $request_origin );
			return true;
		}

		// No token: fall back to same-domain logged-in user check.
		if ( empty( $request_url ) || $this->is_url_from_host( get_site_url(), $parsed_origin['host'], $origin_port ) ) {
			return current_user_can( 'manage_options' );
		}

		return false;
	}

	/**
	 * Check if two URLs belong to the same host.
	 *
	 * @param string   $url  The URL to check.
	 * @param string   $host The host to compare against.
	 * @param int|null $port Optional. The port to compare against.
	 *
	 * @return bool True if both URLs belong to the same host (and port if specified), false otherwise.
	 */
	protected function is_url_from_host( string $url, string $host, ?int $port = null ): bool {
		$parsed_url = wp_parse_url( $url );

		// Compare both host and port to properly handle localhost with different ports.
		if ( ! isset( $parsed_url['host'] ) || $parsed_url['host'] !== $host ) {
			return false;
		}

		// If a port was provided, also compare ports.
		if ( null !== $port ) {
			$url_port = $parsed_url['port'] ?? 80;
			return $url_port === $port;
		}

		return true;
	}

	/**
	 * Gets the locally-stored API key for comparison.
	 *
	 * @param ?string $site_url Site URL. Only used for child->governing site requests.
	 *
	 * @return string The stored API key. Empty string if not found.
	 */
	private function get_stored_api_key( ?string $site_url = null ): string {
		if ( Settings::is_consumer_site() ) {
			return Settings::get_api_key();
		}

		// If there's no child site URL we cannot match the API key.
		if ( ! isset( $site_url ) ) {
			return '';
		}

		$shared_sites = Settings::get_shared_sites();

		return ! empty( $shared_sites[ $site_url ]['api_key'] ) ? $shared_sites[ $site_url ]['api_key'] : '';
	}
}
