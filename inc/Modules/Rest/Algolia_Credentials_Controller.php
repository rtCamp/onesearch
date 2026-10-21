<?php
/**
 * The /algolia-credentials route, which saves the governing site's Algolia credentials.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Modules\Search\Search_Index;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use WP_REST_Server;

/**
 * Class - Algolia_Credentials_Controller
 */
class Algolia_Credentials_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		if ( Settings::is_governing_site() ) {

			// Set only, so the write key never leaves the server.
			register_rest_route(
				self::NAMESPACE,
				'/algolia-credentials',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'args'                => [
							'app_id'    => [
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'rest_sanitize_request_arg',
							],
							'write_key' => [
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'rest_sanitize_request_arg',
							],
						],
						'callback'            => [ $this, 'update_algolia_credentials' ],
						'permission_callback' => static fn () => current_user_can( 'manage_options' ),
					],
				]
			);
		}
	}

	/**
	 * Update Algolia credentials on governing site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function update_algolia_credentials( $request ): \WP_REST_Response|\WP_Error {
		$parameters = $request->get_json_params();

		$app_id    = isset( $parameters['app_id'] ) ? sanitize_text_field( $parameters['app_id'] ) : '';
		$write_key = isset( $parameters['write_key'] ) ? sanitize_text_field( $parameters['write_key'] ) : '';

		if ( empty( $app_id ) || empty( $write_key ) ) {
			return new \WP_Error(
				'onesearch_algolia_credentials_invalid',
				__( 'Both App ID and Write Key are required.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! Search_Index::validate_credentials( $app_id, $write_key ) ) {
			return new \WP_Error(
				'onesearch_algolia_credentials_invalid',
				__( 'The provided Algolia credentials are invalid or lack necessary permissions.', 'onesearch' ),
				[ 'status' => 400 ]
			);
		}

		$success = Search_Settings::set_algolia_credentials(
			[
				'app_id'    => $app_id,
				'write_key' => $write_key,
			]
		);

		if ( ! $success ) {
			return new \WP_Error(
				'onesearch_algolia_credentials_save_failed',
				__( 'Failed to save Algolia credentials.', 'onesearch' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response(
			[
				'message' => __( 'Algolia credentials updated successfully.', 'onesearch' ),
			]
		);
	}
}
