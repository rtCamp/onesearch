<?php
/**
 * The /brand-site routes, which remove a brand site from its governing site.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Settings\Settings;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class - Brand_Site_Controller
 */
class Brand_Site_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		// Only on governing sites.
		if ( Settings::is_governing_site() ) {
			// Lets a brand site remove itself when it disconnects.
			register_rest_route(
				self::NAMESPACE,
				'/brand-site',
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_current_brand_site' ],
					'permission_callback' => [ $this, 'is_brand_request' ],
				]
			);
		}

		// Only on consumer sites.
		if ( Settings::is_consumer_site() ) {
			// Lets the governing site disconnect this brand site when it removes it.
			register_rest_route(
				self::NAMESPACE,
				'/brand-site',
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_governing_site_from_brand' ],
					'permission_callback' => [ $this, 'is_governing_request' ],
				]
			);
		}
	}

	/**
	 * Removes the requesting brand site from this governing site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function remove_current_brand_site( $request ): WP_REST_Response|\WP_Error {
		$site_url = $this->get_requesting_site_url( $request );

		if ( empty( $site_url ) || ! Settings::remove_shared_site( $site_url ) ) {
			return new \WP_Error(
				'onesearch_remove_site_failed',
				__( 'The brand site could not be removed.', 'onesearch' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Brand site removed successfully.', 'onesearch' ),
			]
		);
	}

	/**
	 * Removes the governing site from this brand site, after the governing site removed it.
	 */
	public function remove_governing_site_from_brand(): WP_REST_Response {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		// Clear cached brand configuration, including the Algolia credentials.
		Remote_Client::clear_brand_config_cache();

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Governing site removed successfully.', 'onesearch' ),
			]
		);
	}
}
