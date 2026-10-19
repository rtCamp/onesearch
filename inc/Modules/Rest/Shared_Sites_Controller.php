<?php
/**
 * The /shared-sites routes, which store the brand sites of a governing site and remove them.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
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

		/**
		 * Register a route to remove a brand site, and tell it.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/shared-sites/(?P<id>[A-Za-z0-9-]+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_shared_site' ],
				'permission_callback' => static fn () => current_user_can( 'manage_options' ),
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

		$stored_sites = array_column( Settings::get_shared_sites(), null, 'id' );

		// check if same url exists more than once or not.
		$urls     = [];
		$to_check = [];
		foreach ( $sites_data as $site ) {
			if ( isset( $site['url'] ) && in_array( $site['url'], $urls, true ) ) {
				return new \WP_Error( 'duplicate_site_url', __( 'Brand Site already exists.', 'onesearch' ), [ 'status' => 400 ] );
			}

			// Add a unique ID if it doesn't exist.
			$site['id'] = $site['id'] ?? wp_generate_uuid4();

			// New sites, and sites whose URL or key changed, are health-checked before they're saved, which also connects them.
			$stored = $stored_sites[ $site['id'] ] ?? null;
			if ( null === $stored || Utils::normalize_url( (string) ( $site['url'] ?? '' ) ) !== Utils::normalize_url( $stored['url'] ) || ( $site['api_key'] ?? '' ) !== $stored['api_key'] ) {
				$to_check[] = $site;
			}

			$urls[] = $site['url'] ?? '';
		}

		foreach ( $to_check as $site ) {
			$checked = Remote_Client::check_brand_site( (string) ( $site['url'] ?? '' ), (string) ( $site['api_key'] ?? '' ) );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
		}

		Settings::set_shared_sites( $sites_data );

		// Return what was stored, so the browser has the ids it removes sites by.
		return rest_ensure_response(
			[
				'shared_sites' => array_values( Settings::get_shared_sites() ),
			]
		);
	}

	/**
	 * Removes a brand site, and then tells it to disconnect from this governing site.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	public function delete_shared_site( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$site_id = (string) $request['id'];
		$site    = null;

		foreach ( Settings::get_shared_sites() as $stored_site ) {
			if ( $stored_site['id'] === $site_id ) {
				$site = $stored_site;
				break;
			}
		}

		if ( null === $site ) {
			return new \WP_Error( 'onesearch_site_not_found', __( 'The brand site was not found.', 'onesearch' ), [ 'status' => 404 ] );
		}

		// Through the setting's own update hooks, so the site's records and settings are cleaned up.
		if ( ! Settings::remove_shared_site( $site['url'] ) ) {
			return new \WP_Error( 'onesearch_remove_site_failed', __( 'The brand site could not be removed.', 'onesearch' ), [ 'status' => 500 ] );
		}

		if ( Remote_Client::disconnect_brand_site( $site ) ) {
			return rest_ensure_response(
				[
					'governing_site_notified' => true,
					'message'                 => __( 'Brand site removed successfully.', 'onesearch' ),
				]
			);
		}

		return rest_ensure_response(
			[
				'governing_site_notified' => false,
				'message'                 => sprintf(
					/* translators: %s: brand site name. */
					__( '%s was removed, but it could not be notified. If it still shows this site under Governing Site Connection, click Disconnect Governing Site in its OneSearch settings.', 'onesearch' ),
					$site['name']
				),
			]
		);
	}
}
