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
use OneSearch\Utils;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Governing_Data_Controller
 */
class Governing_Data_Controller extends Abstract_REST_Controller {
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

			// Lets a brand site remove itself when it disconnects.
			register_rest_route(
				self::NAMESPACE,
				'/brand-site',
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

			// Lets the governing site disconnect this brand site when it removes it.
			register_rest_route(
				self::NAMESPACE,
				'/brand-site',
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_governing_site' ],
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
	 * Removes the requesting brand site from this governing site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function remove_brand_site( $request ): WP_REST_Response|\WP_Error {
		$site_url = $this->get_request_site_url( $request );

		if ( empty( $site_url ) || ! $this->is_allowed_site( $site_url ) ) {
			return new \WP_Error(
				'onesearch_unauthorized_site',
				__( 'The requesting site is not a shared brand site.', 'onesearch' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! Settings::remove_shared_site( $site_url ) ) {
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
	 * Removes the governing site from this brand site, after the governing site removed it.
	 */
	public function remove_governing_site(): WP_REST_Response {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		// Clear cached brand configuration, including the Algolia credentials.
		Governing_Data_Handler::clear_brand_config_cache();

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Governing site removed successfully.', 'onesearch' ),
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
	 * Get the normalized URL of the site making the request, from its Origin header.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	private function get_request_site_url( $request ): string {
		$origin = $request->get_header( 'origin' );
		$origin = ! empty( $origin ) ? esc_url_raw( wp_unslash( $origin ) ) : '';

		return Utils::normalize_url( $origin );
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
