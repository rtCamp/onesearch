<?php
/**
 * The /indexer route, which starts indexing a site and its brand sites, and shows their progress.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Search\Indexer;
use WP_REST_Server;

/**
 * Class - Indexer_Controller
 */
class Indexer_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/indexer',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_progress' ],
					// The governing site asks a brand site for its progress.
					'permission_callback' => fn ( $request ) => current_user_can( 'manage_options' ) || $this->is_governing_request( $request ),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'reindex' ],
					// The governing site starts its brand sites' indexing run.
					'permission_callback' => fn ( $request ) => current_user_can( 'manage_options' ) || $this->is_governing_request( $request ),
				],
			]
		);
	}

	/**
	 * Gets the progress of the latest indexing run of the site, and of a governing site's brand sites.
	 */
	public function get_progress(): \WP_REST_Response {
		return rest_ensure_response(
			[
				'sites' => Indexer::get_all_progress(),
			]
		);
	}

	/**
	 * Starts indexing the site, and a governing site's brand sites too.
	 */
	public function reindex(): \WP_REST_Response|\WP_Error {
		$started = Indexer::start();

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		return $this->get_progress();
	}
}
