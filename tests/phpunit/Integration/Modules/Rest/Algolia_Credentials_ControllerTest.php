<?php
/**
 * Tests for Algolia_Credentials_Controller.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Algolia_Credentials_Controller;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the /algolia-credentials route, which only governing sites register.
 */
#[CoversClass( Algolia_Credentials_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Algolia_Credentials_ControllerTest extends TestCase {
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

		( new Algolia_Credentials_Controller() )->register_hooks();
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
	 * Governing site registers algolia-credentials.
	 */
	public function test_registers_algolia_credentials(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Algolia_Credentials_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/algolia-credentials', $routes );
	}

	/**
	 * The stored write key can't be read back, even by an admin.
	 */
	public function test_algolia_credentials_cannot_be_read(): void {
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP_ID',
				'write_key' => 'TEST_WRITE_KEY',
			]
		);

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/algolia-credentials' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * POST /algolia-credentials with an empty app_id returns 400.
	 */
	public function test_update_algolia_credentials_rejects_empty_app_id(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/algolia-credentials' );
		$request->set_body(
			wp_json_encode(
				[
					'app_id'    => '',
					'write_key' => 'some-key',
				]
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_algolia_credentials_invalid', $data['code'] );
	}

	/**
	 * POST /algolia-credentials with an empty write_key returns 400.
	 */
	public function test_update_algolia_credentials_rejects_empty_write_key(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/algolia-credentials' );
		$request->set_body(
			wp_json_encode(
				[
					'app_id'    => 'APPID',
					'write_key' => '',
				]
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_algolia_credentials_invalid', $data['code'] );
	}

	/**
	 * POST /algolia-credentials with no fields is rejected by the schema validator
	 * before the callback runs, because app_id and write_key are declared required.
	 */
	public function test_update_algolia_credentials_rejects_missing_fields(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/algolia-credentials' );
		$request->set_body( wp_json_encode( [] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * A brand site's token can't replace the governing site's Algolia credentials.
	 */
	public function test_update_algolia_credentials_refuses_brand_token(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.example.com',
					'api_key' => 'brand-key',
				],
			]
		);
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/algolia-credentials' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://brand.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'brand-key' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				[
					'app_id'    => 'OTHER_APP',
					'write_key' => 'other-key',
				]
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}
}
