<?php
/**
 * The /governing-site routes, which disconnect a brand site from its governing site.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Settings\Settings;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class - Governing_Site_Controller
 */
class Governing_Site_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
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
	 * Remove governing site url.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_governing_site(): WP_REST_Response|\WP_Error {
		$governing_site_url = Settings::get_parent_site_url();

		// Tell the governing site first, since this needs the stored governing site URL.
		$deregistered = ! empty( $governing_site_url ) ? Remote_Client::deregister_from_governing_site() : true;

		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		// Clear cached brand configuration.
		Remote_Client::clear_brand_config_cache();

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
