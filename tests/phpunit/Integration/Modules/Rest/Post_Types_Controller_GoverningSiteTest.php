<?php
/**
 * Tests for Post_Types_Controller on a governing site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Post_Types_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Governing-site scenarios for {@see Post_Types_Controller}.
 */
#[CoversClass( Post_Types_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Post_Types_Controller_GoverningSiteTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		( new Post_Types_Controller() )->register_hooks();
		do_action( 'rest_api_init' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Returns an `errors` entry for shared sites missing required configuration.
	 */
	public function test_get_all_post_types_reports_errors_for_bad_sites(): void {
		Settings::set_shared_sites(
			[
				[
					'name' => 'Test Site',
					'url'  => 'https://test.example.com',
					// Missing api_key.
				],
			]
		);

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/all-post-types' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$site_url = Utils::get_current_site_url();
		$this->assertArrayHasKey( $site_url, $data['sites'] );
		$this->assertNotEmpty( $data['errors'] );
	}
}
