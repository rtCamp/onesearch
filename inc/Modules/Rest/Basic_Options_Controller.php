<?php
/**
 * This is routes for Settings options.
 *
 * @package OneSearch
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Basic_Options_Controller
 */
class Basic_Options_Controller extends Abstract_REST_Controller {
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

		/**
		 * Register a route to regenerate the api key.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/secret-key',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => static function () {
						$api_key = Settings::regenerate_api_key();

						return new \WP_REST_Response(
							[
								'success'    => true,
								'message'    => __( 'Secret key regenerated successfully.', 'onesearch' ),
								'secret_key' => $api_key,
							]
						);
					},
					'permission_callback' => static fn () => current_user_can( 'manage_options' ),
				],
			]
		);

		/**
		 * Register a route to manage governing site.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/governing-site',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_governing_site' ],
					'permission_callback' => static fn () => current_user_can( 'manage_options' ),
				],
			],
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

		$success = Settings::set_shared_sites( $sites_data );

		return rest_ensure_response(
			[
				'success'      => $success,
				'shared_sites' => array_values( $sites_data ),
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

	/**
	 * Remove governing site url.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_governing_site(): WP_REST_Response|\WP_Error {
		$governing_site_url = Settings::get_parent_site_url();

		// Tell the governing site first, since this needs the stored governing site URL.
		$deregistered = ! empty( $governing_site_url ) ? Governing_Data_Handler::deregister_from_governing_site() : true;

		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		// Clear cached brand configuration.
		Governing_Data_Handler::clear_brand_config_cache();

		// If the governing site couldn't be notified, inform the admin through the response message.
		if ( is_wp_error( $deregistered ) ) {
			$message = 'onesearch_unrecognized_site' === $deregistered->get_error_code() ? sprintf(
				/* translators: %s: governing site URL. */
				__( 'This site was disconnected, but the governing site (%s) did not recognize it, so it may already have been removed there. If this site is still listed on the governing site, remove it there.', 'onesearch' ),
				$governing_site_url
			) : sprintf(
				/* translators: %s: governing site URL. */
				__( 'This site was disconnected, but the governing site (%s) could not be notified. To finish disconnecting, remove this site from the governing site.', 'onesearch' ),
				$governing_site_url
			);

			return rest_ensure_response(
				[
					'success'                 => true,
					'governing_site_notified' => false,
					'message'                 => $message,
				]
			);
		}

		return rest_ensure_response(
			[
				'success'                 => true,
				'governing_site_notified' => true,
				'message'                 => __( 'Governing site removed successfully.', 'onesearch' ),
			]
		);
	}
}
