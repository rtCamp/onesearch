<?php
/**
 * The /re-index route, which re-indexes a site and its brand sites.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
use WP_REST_Server;

/**
 * Class - Reindex_Controller
 */
class Reindex_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		// Re-index current site (and children for governing sites).
		register_rest_route(
			self::NAMESPACE,
			'/re-index',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reindex' ],
				'permission_callback' => fn ( $request ) => current_user_can( 'manage_options' ) || $this->is_governing_request( $request ),
			]
		);
	}

	/**
	 * Reindex the current site.
	 *
	 * If the site is a governing site, trigger the reindex on children as well.
	 */
	public function reindex(): \WP_REST_Response|\WP_Error {
		$errors = [];

		// If Governing, trigger re-index on children as well.
		if ( Settings::is_governing_site() ) {
			$child_errors = Remote_Client::reindex_brand_sites();

			if ( is_array( $child_errors ) ) {
				$errors = array_merge( $errors, $child_errors );
			}
		}

		// Index the current site.
		$indexed = ( new Indexer() )->reindex();

		if ( is_wp_error( $indexed ) ) {
			$errors[] = [
				'site_url' => Utils::get_current_site_url(),
				'message'  => $indexed->get_error_message() ?: __( 'Re-index failed.', 'onesearch' ),
			];
		}

		return rest_ensure_response(
			[
				'success' => empty( $errors ),
				'message' => empty( $errors )
					? __( 'Re-indexing scheduled successfully.', 'onesearch' )
					: __( 'Re-indexing was unsuccessful. Please try again later.', 'onesearch' ),
				'errors'  => $errors,
			]
		);
	}
}
