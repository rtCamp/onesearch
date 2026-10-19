<?php
/**
 * Tests for Brand_Config_Controller on a brand (consumer) site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Brand_Config_Controller;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Brand-site (consumer) scenarios for {@see Brand_Config_Controller}.
 */
#[CoversClass( Brand_Config_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Brand_Config_Controller_BrandSiteTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		( new Brand_Config_Controller() )->register_hooks();
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
	 * Brand site registers brand-config DELETE.
	 */
	public function test_registers_brand_config_delete(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Brand_Config_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-config', $routes );
		$this->assertArrayHasKey( 'DELETE', $routes[ $ns . '/brand-config' ][0]['methods'] );
	}

	/**
	 * DELETE /brand-config from the governing site clears the cached brand config transient.
	 */
	public function test_delete_brand_config_cache_clears_transient(): void {
		set_transient( Remote_Client::TRANSIENT_KEY, [ 'cached' => true ], 3600 );

		$response = $this->server->dispatch( $this->governing_request( 'DELETE', '/onesearch/v1/brand-config' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertFalse( get_transient( Remote_Client::TRANSIENT_KEY ) );
	}

	/**
	 * DELETE /brand-config succeeds when there was nothing cached.
	 */
	public function test_delete_brand_config_cache_succeeds_when_no_cache(): void {
		delete_transient( Remote_Client::TRANSIENT_KEY );

		$response = $this->server->dispatch( $this->governing_request( 'DELETE', '/onesearch/v1/brand-config' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Only the governing site clears the cached brand config, so a logged-in admin (from set_up) is refused.
	 */
	public function test_delete_brand_config_cache_refuses_an_admin(): void {
		set_transient( Remote_Client::TRANSIENT_KEY, [ 'cached' => true ], 3600 );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-config' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [ 'cached' => true ], get_transient( Remote_Client::TRANSIENT_KEY ) );
	}

	/**
	 * Builds a request from the governing site this brand site is connected to.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 *
	 * @return \WP_REST_Request<array<string,mixed>>
	 */
	private function governing_request( string $method, string $route ): WP_REST_Request {
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://governing.example.com' );
		$request->set_header( 'X-OneSearch-Token', Settings::get_api_key() );

		return $request;
	}
}
