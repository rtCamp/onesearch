<?php
/**
 * Tests for Governing_Data_Controller on a brand (consumer) site.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

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
	}

	/**
	 * DELETE /brand-config clears the cached brand config transient.
	 */
	public function test_delete_brand_config_cache_clears_transient(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'cached' => true ], 3600 );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-config' );
		$response = $this->server->dispatch( $request );
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

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-config' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}
}
