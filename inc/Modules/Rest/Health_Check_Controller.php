<?php
/**
 * The /health-check route, which connects a brand site to the governing site that checks it.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class - Health_Check_Controller
 */
class Health_Check_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		/**
		 * Register a route for health-check.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/health-check',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'health_check' ],
				'permission_callback' => [ $this, 'check_health_check_permissions' ],
			]
		);
	}

	/**
	 * Allows a governing site holding this brand site's API key to health-check it.
	 *
	 * Fails if the request is not from the governing site this brand site is connected to, or if the site is already connected to a different governing site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function check_health_check_permissions( $request ): bool|\WP_Error {
		$site_url = $this->get_requesting_site_url( $request );

		// Bail if not a consumer site, the site URL is empty, or the API key doesn't match.
		if ( ! Settings::is_consumer_site() || empty( $site_url ) || ! $this->has_api_key( $request, Settings::get_api_key() ) ) {
			return false;
		}

		// Never repoint a connected brand.
		$governing_site_url = Settings::get_parent_site_url();
		if ( ! empty( $governing_site_url ) && Utils::normalize_url( $governing_site_url ) !== $site_url ) {
			return new \WP_Error(
				'onesearch_already_connected',
				__( 'This site is already connected to a different governing site. Disconnect it there first.', 'onesearch' ),
				[ 'status' => 409 ]
			);
		}

		return true;
	}

	/**
	 * Health check endpoint.
	 *
	 * The first health-check connects this brand site to the governing site that sent it.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function health_check( $request ): WP_REST_Response|\WP_Error {
		// Autoconnect to the governing site if not already connected.
		if ( empty( Settings::get_parent_site_url() ) ) {
			Settings::set_parent_site_url( $this->get_requesting_site_url( $request ) );
		}

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Health check passed successfully.', 'onesearch' ),
			]
		);
	}
}
