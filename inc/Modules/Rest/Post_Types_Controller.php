<?php
/**
 * The /all-post-types route, which lists the post types of a site and its brand sites.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
use WP_REST_Server;

/**
 * Class - Post_Types_Controller
 */
class Post_Types_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		// Both sites: get all post types.
		register_rest_route(
			self::NAMESPACE,
			'/all-post-types',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_all_post_types' ],
				'permission_callback' => fn ( $request ) => current_user_can( 'manage_options' ) || $this->is_governing_request( $request ),
			]
		);
	}

	/**
	 * Return public post types for the current site (and children if governing).
	 */
	public function get_all_post_types(): \WP_REST_Response|\WP_Error {
		$site_url = Utils::get_current_site_url();

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
			$child_post_type_data = Remote_Client::get_all_brand_post_types();

			if ( is_wp_error( $child_post_type_data ) ) {
				return $child_post_type_data;
			}

			$all_sites = array_merge( $all_sites, $child_post_type_data['sites'] );
			$errors    = $child_post_type_data['errors'] ?? [];
		}

		return rest_ensure_response(
			[
				'sites'  => $all_sites,
				'errors' => $errors,
			]
		);
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
