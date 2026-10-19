<?php
/**
 * Tests for Governing_Data_Controller on a brand (consumer) site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Governing_Data_Controller;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Brand-site (consumer) scenarios for {@see Governing_Data_Controller}.
 */
#[CoversClass( Governing_Data_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Governing_Data_Controller_BrandSiteTest extends TestCase {
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

		( new Governing_Data_Controller() )->register_hooks();
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
	 * Brand site registers brand-config DELETE and all-post-types.
	 */
	public function test_registers_brand_config_delete_and_all_post_types(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Governing_Data_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-config', $routes );
		$this->assertArrayHasKey( 'DELETE', $routes[ $ns . '/brand-config' ][0]['methods'] );
		$this->assertArrayHasKey( $ns . '/all-post-types', $routes );
		$this->assertArrayHasKey( $ns . '/brand-site', $routes );
		$this->assertArrayHasKey( 'DELETE', $routes[ $ns . '/brand-site' ][0]['methods'] );
	}

	/**
	 * DELETE /brand-config from the governing site clears the cached brand config transient.
	 */
	public function test_delete_brand_config_cache_clears_transient(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'cached' => true ], 3600 );

		$response = $this->server->dispatch( $this->governing_request( 'DELETE', '/onesearch/v1/brand-config' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * DELETE /brand-config succeeds when there was nothing cached.
	 */
	public function test_delete_brand_config_cache_succeeds_when_no_cache(): void {
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		$response = $this->server->dispatch( $this->governing_request( 'DELETE', '/onesearch/v1/brand-config' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Only the governing site clears the cached brand config, so a logged-in admin (from set_up) is refused.
	 */
	public function test_delete_brand_config_cache_refuses_an_admin(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'cached' => true ], 3600 );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-config' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [ 'cached' => true ], get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * The governing site, presenting this site's token, clears the pairing and the cached
	 * brand configuration with its Algolia credentials.
	 */
	public function test_remove_governing_site_clears_pairing_and_cached_config(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'algolia_credentials' => [ 'write_key' => 'cached' ] ], 3600 );

		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->governing_request( 'DELETE', '/onesearch/v1/brand-site' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertNull( Settings::get_parent_site_url() );
		$this->assertFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * Only the governing site this brand site is connected to can remove it.
	 */
	public function test_remove_governing_site_rejects_other_sites(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-site' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://other.example.com' );
		$request->set_header( 'X-OneSearch-Token', Settings::get_api_key() );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'https://governing.example.com', Settings::get_parent_site_url() );
	}

	/**
	 * The governing site can't remove this brand site without its token.
	 */
	public function test_remove_governing_site_rejects_wrong_token(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-site' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://governing.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'wrong-key' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'https://governing.example.com', Settings::get_parent_site_url() );
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
