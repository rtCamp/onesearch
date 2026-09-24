<?php
/**
 * Routes for Search-related operations.
 *
 * @package OneSearch
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Governing_Data_Controller
 */
class Governing_Data_Controller extends Abstract_REST_Controller {
	/**
	 * Route a brand site calls on its governing site to remove itself.
	 */
	public const ROUTE_REMOVE_BRAND = '/site-connection/brand/remove';

	/**
	 * Route a governing site calls on a brand site to clear the pairing.
	 */
	public const ROUTE_REMOVE_GOVERNING = '/site-connection/governing/remove';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		// Only on governing sites.
		if ( Settings::is_governing_site() ) {
			register_rest_route(
				self::NAMESPACE,
				'/brand-config',
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_brand_config' ],
					'permission_callback' => [ $this, 'check_api_permissions' ],
				]
			);

			register_rest_route(
				self::NAMESPACE,
				self::ROUTE_REMOVE_BRAND,
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_brand_site' ],
					'permission_callback' => [ $this, 'check_api_permissions' ],
				]
			);
		}

		// Only on consumer sites.
		if ( Settings::is_consumer_site() ) {
			// Prime the config cache on brand sites.
			register_rest_route(
				self::NAMESPACE,
				'/brand-config',
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_brand_config_cache' ],
					'permission_callback' => [ $this, 'check_api_permissions' ],
				]
			);

			register_rest_route(
				self::NAMESPACE,
				self::ROUTE_REMOVE_GOVERNING,
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_governing_site_connection' ],
					'permission_callback' => [ $this, 'check_api_permissions' ],
				]
			);
		}

		// Both sites: get all post types.
		register_rest_route(
			self::NAMESPACE,
			'/all-post-types',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_all_post_types' ],
				'permission_callback' => [ $this, 'check_api_permissions' ],
			]
		);
	}

	/**
	 * Resolves the normalized URL of the site making the request.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 *
	 * @return string The normalized site URL, or an empty string when it is unknown.
	 */
	protected function get_request_site_url( $request ): string {
		$site_url = $this->parse_origin( $request->get_header( 'origin' ) )['url'];

		if ( empty( $site_url ) ) {
			$site_url = $this->parse_origin( $request->get_header( 'X-OneSearch-Site-URL' ) )['url'];
		}

		return $site_url;
	}

	/**
	 * Get consolidated configuration for a brand site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function get_brand_config( $request ): WP_REST_Response|\WP_Error {
		// Get the origin from the request headers and confirm it's a known site.
		$site_url = $this->get_request_site_url( $request );

		if ( empty( $site_url ) || ! $this->is_allowed_site( $site_url ) ) {
			return new \WP_Error(
				'onesearch_unauthorized_site',
				__( 'The requesting site is not a shared brand site.', 'onesearch' ),
				[ 'status' => 403 ]
			);
		}

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
		$searchable_urls[] = trailingslashit( get_site_url() );

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

	/**
	 * Deletes the config cache for the brand site.
	 */
	public function delete_brand_config_cache(): WP_REST_Response {
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Brand configuration cache cleared successfully.', 'onesearch' ),
			]
		);
	}

	/**
	 * Removes the requesting brand site from current governing site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function remove_brand_site( $request ): WP_REST_Response|\WP_Error {
		$site_url = $this->get_request_site_url( $request );

		if ( empty( $site_url ) ) {
			return new \WP_Error(
				'onesearch_unknown_site',
				__( 'The requesting site could not be identified.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		// The brand site already disconnected locally, so don't notify it back.
		$removed_site = Settings::remove_shared_site( $site_url, true );

		if ( false === $removed_site ) {
			return new \WP_Error(
				'onesearch_disconnect_failed',
				__( 'The brand site could not be removed from the governing site.', 'onesearch' ),
				[ 'status' => 500 ]
			);
		}

		if ( null === $removed_site ) {
			return rest_ensure_response(
				[
					'success' => true,
					'message' => __( 'The brand site is not connected to this governing site.', 'onesearch' ),
				]
			);
		}

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Brand site disconnected successfully.', 'onesearch' ),
			]
		);
	}

	/**
	 * Clears the governing site pairing on current brand site.
	 */
	public function remove_governing_site_connection(): WP_REST_Response {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		Governing_Data_Handler::clear_brand_config_cache();

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Governing site disconnected successfully.', 'onesearch' ),
			]
		);
	}

	/**
	 * Return public post types for the current site (and children if governing).
	 */
	public function get_all_post_types(): \WP_REST_Response|\WP_Error {
		$site_url = trailingslashit( get_site_url() );

		$site_payload = [
			'site_name'  => (string) get_bloginfo( 'name' ),
			'site_url'   => $site_url,
			'post_types' => $this->get_local_post_types(),
		];

		$all_sites = [
			$site_url => $site_payload,
		];
		$errors    = [];

		// If governing, get post types from child sites as well.
		if ( Settings::is_governing_site() ) {
			$child_post_type_data = Governing_Data_Handler::get_all_brand_post_types();

			if ( is_wp_error( $child_post_type_data ) ) {
				return $child_post_type_data;
			}

			$all_sites = array_merge( $all_sites, $child_post_type_data['sites'] );
			$errors    = $child_post_type_data['errors'] ?? [];
		}

		return rest_ensure_response(
			[
				'success' => empty( $errors ),
				'sites'   => $all_sites,
				'errors'  => $errors,
			]
		);
	}

	/**
	 * Check whether the request is from a known brand site.
	 *
	 * @param string $origin The origin URL.
	 */
	private function is_allowed_site( string $origin ): bool {
		$shared_sites = Settings::get_shared_sites();

		return isset( $shared_sites[ $origin ] );
	}

	/**
	 * Get available public post types for the site.
	 *
	 * @return array{
	 *   slug: string,
	 *   label: string,
	 *   restBase: string,
	 * }[]
	 */
	private function get_local_post_types(): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );

		$payload = [];
		foreach ( $post_types as $slug => $post_type ) {
			$payload[] = [
				'slug'     => $slug,
				'label'    => isset( $post_type->labels->name ) ? (string) $post_type->labels->name : $slug,
				'restBase' => ! empty( $post_type->rest_base ) ? (string) $post_type->rest_base : $slug,
			];
		}

		return $payload;
	}
}
