<?php
/**
 * Tests for Brand_Site_Controller on a brand (consumer) site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Brand_Site_Controller;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Brand-site (consumer) scenarios for {@see Brand_Site_Controller}.
 */
#[CoversClass( Brand_Site_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Brand_Site_Controller_BrandSiteTest extends TestCase {
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

		( new Brand_Site_Controller() )->register_hooks();
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
	 * Brand site registers brand-site DELETE.
	 */
	public function test_registers_brand_site_delete(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Brand_Site_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-site', $routes );
		$this->assertArrayHasKey( 'DELETE', $routes[ $ns . '/brand-site' ][0]['methods'] );
	}

	/**
	 * The governing site, presenting this site's token, clears the pairing and the cached
	 * brand configuration with its Algolia credentials.
	 */
	public function test_remove_governing_site_clears_pairing_and_cached_config(): void {
		set_transient( Remote_Client::TRANSIENT_KEY, [ 'algolia_credentials' => [ 'write_key' => 'cached' ] ], 3600 );

		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->governing_request( 'DELETE', '/onesearch/v1/brand-site' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertNull( Settings::get_parent_site_url() );
		$this->assertFalse( get_transient( Remote_Client::TRANSIENT_KEY ) );
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
