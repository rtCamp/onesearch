<?php
/**
 * The /brand-site routes, which give a brand site its configuration and let it remove itself from its governing site.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
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

			// Gives a brand site its configuration.
			register_rest_route(
				self::NAMESPACE,
				'/brand-site/config',
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_brand_config' ],
					'permission_callback' => [ $this, 'is_brand_request' ],
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
	 * Get consolidated configuration for a brand site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function get_brand_config( $request ): WP_REST_Response|\WP_Error {
		// A shared brand site, checked by ::is_brand_request.
		$site_url = $this->get_requesting_site_url( $request );

		// Get Algolia credentials.
		$creds = Search_Settings::get_algolia_credentials();

		// Get search settings for this specific site.
		$all_search_settings = Search_Settings::get_search_settings();
		$site_search_config  = $all_search_settings[ $site_url ] ?? [
			'algolia_enabled'  => false,
			'searchable_sites' => [],
		];

		// Get indexable entities for this specific site.
		$all_indexable_entities = Search_Settings::get_indexable_entities();
		$entities_map           = isset( $all_indexable_entities['entities'] ) && is_array( $all_indexable_entities['entities'] )
			? $all_indexable_entities['entities']
			: [];
		$site_entities          = $entities_map[ $site_url ] ?? [];

		// Get all available sites (for searchable_sites to be meaningful).
		$shared_sites    = Settings::get_shared_sites();
		$searchable_urls = array_keys( $shared_sites );
		// Add governing site itself.
		$searchable_urls[] = Utils::get_current_site_url();

		return rest_ensure_response(
			[
				'success'             => true,
				'algolia_credentials' => [
					'app_id'    => $creds['app_id'] ?? '',
					'write_key' => $creds['write_key'] ?? '',
				],
				'search_settings'     => [
					'algolia_enabled'  => $site_search_config['algolia_enabled'],
					'searchable_sites' => $site_search_config['searchable_sites'],
				],
				'indexable_entities'  => is_array( $site_entities ) ? $site_entities : [],
				'available_sites'     => array_values( array_unique( $searchable_urls ) ),
			]
		);
	}
}
