<?php
/**
 * The /shared-sites routes, which store the brand sites of a governing site.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class - Shared_Sites_Controller
 */
class Shared_Sites_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		/**
		 * Register a route which will store array of sites data like site name, site url, its GitHub repo and api key.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/shared-sites',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'set_shared_sites' ],
					'permission_callback' => static fn () => current_user_can( 'manage_options' ),
					'args'                => [
						'sites_data' => [
							'required'          => true,
							'type'              => 'array',
							'validate_callback' => 'rest_validate_request_arg',
						],
					],
				],
			]
		);
	}

	/**
	 * Set shared sites data.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_shared_sites( WP_REST_Request $request ): WP_REST_Response|\WP_Error {

		$body         = $request->get_body();
		$decoded_body = json_decode( $body, true );
		$sites_data   = $decoded_body['sites_data'] ?? [];

		// check if same url exists more than once or not.
		$urls = [];
		foreach ( $sites_data as $site ) {
			if ( isset( $site['url'] ) && in_array( $site['url'], $urls, true ) ) {
				return new \WP_Error( 'duplicate_site_url', __( 'Brand Site already exists.', 'onesearch' ), [ 'status' => 400 ] );
			}

			// Add a unique ID if it doesn't exist.
			$site['id'] = $site['id'] ?? wp_generate_uuid4();

			$urls[] = $site['url'] ?? '';
		}

		Settings::set_shared_sites( $sites_data );

		return rest_ensure_response(
			[
				'shared_sites' => array_values( $sites_data ),
			]
		);
	}
}
