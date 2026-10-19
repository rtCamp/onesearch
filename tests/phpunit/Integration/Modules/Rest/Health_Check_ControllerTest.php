<?php
/**
 * Tests for Health_Check_Controller.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Health_Check_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the /health-check route.
 */
#[CoversClass( Health_Check_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Health_Check_ControllerTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		( new Health_Check_Controller() )->register_hooks();
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
	 * Only a governing site with this site's key can health-check it, so a logged-in admin (from set_up) is refused.
	 */
	public function test_health_check_refuses_an_admin_without_the_key(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/health-check' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://governing.example.com' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * The first health-check with this site's key connects it to the sender, identified by
	 * X-OneSearch-Site-URL. The user is logged out so success can only come from the key.
	 */
	public function test_health_check_with_the_key_connects_the_sender(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		$api_key = Settings::get_api_key();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/health-check' );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://governing.example.com' );

		$response = $this->server->dispatch( $request );

		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( 'https://governing.example.com', Settings::get_parent_site_url() );
	}

	/**
	 * A brand that is already connected refuses to be repointed to a different governing site.
	 */
	public function test_health_check_rejects_a_different_governing_site_when_connected(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		$api_key = Settings::get_api_key();
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/health-check' );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://other.example.com' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'onesearch_already_connected', $response->get_data()['code'] );
		$this->assertSame( 'https://governing.example.com', Settings::get_parent_site_url() );
	}

	/**
	 * The same governing site, with or without a trailing slash, can health-check again.
	 */
	public function test_health_check_accepts_the_same_governing_site_when_connected(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		$api_key = Settings::get_api_key();
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/health-check' );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://governing.example.com/' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://governing.example.com', Settings::get_parent_site_url() );
	}
}
