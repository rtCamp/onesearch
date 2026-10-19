<?php
/**
 * Tests for Basic_Options_Controller.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Basic_Options_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the basic options REST endpoints.
 */
#[CoversClass( Basic_Options_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Basic_Options_ControllerTest extends TestCase {
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

		// Register the controller's routes on rest_api_init.
		( new Basic_Options_Controller() )->register_hooks();
		do_action( 'rest_api_init' );

		// Most endpoints require manage_options; authenticate as an admin.
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
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
	 * Verify all expected routes are registered.
	 */
	public function test_register_routes_registers_expected_endpoints(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Basic_Options_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/shared-sites', $routes );
		$this->assertArrayHasKey( $ns . '/health-check', $routes );
		$this->assertArrayHasKey( $ns . '/secret-key', $routes );
		$this->assertArrayHasKey( $ns . '/governing-site', $routes );
	}

	/**
	 * Returns 400 when duplicate site URLs exist.
	 */
	public function test_set_shared_sites_rejects_duplicate_urls(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/shared-sites' );
		$request->set_body(
			wp_json_encode(
				[
					'sites_data' => [
						[
							'url'     => 'https://dup.example.com',
							'name'    => 'Dup 1',
							'api_key' => 'k1',
						],
						[
							'url'     => 'https://dup.example.com',
							'name'    => 'Dup 2',
							'api_key' => 'k2',
						],
					],
				]
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'duplicate_site_url', $response->get_data()['code'] );
	}

	/**
	 * Saves valid sites data successfully.
	 */
	public function test_set_shared_sites_returns_success_for_valid_data(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/shared-sites' );
		$request->set_body(
			wp_json_encode(
				[
					'sites_data' => [
						[
							'url'     => 'https://site-a.example.com',
							'name'    => 'Site A',
							'api_key' => 'ka',
						],
						[
							'url'     => 'https://site-b.example.com',
							'name'    => 'Site B',
							'api_key' => 'kb',
						],
					],
				]
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertCount( 2, $data['shared_sites'] );

		// Verify data was actually persisted to the database.
		$stored = Settings::get_shared_sites();
		$this->assertCount( 2, $stored );
	}

	/**
	 * Handles empty sites_data gracefully.
	 */
	public function test_set_shared_sites_allows_empty_sites_data(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/shared-sites' );
		$request->set_body( wp_json_encode( [ 'sites_data' => [] ] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( [], $data['shared_sites'] );
	}

	/**
	 * Handles missing sites_data key gracefully.
	 */
	public function test_set_shared_sites_handles_missing_sites_data_key(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/shared-sites' );
		$request->set_body( wp_json_encode( [ 'other' => 'data' ] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );

		// sites_data is required by the route schema → request fails validation.
		$this->assertSame( 400, $response->get_status() );
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

	/**
	 * Removes the governing site option and returns success.
	 */
	public function test_remove_governing_site_deletes_option(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * Succeeds even when no governing site was previously set.
	 */
	public function test_remove_governing_site_succeeds_when_already_unset(): void {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
	}

	/**
	 * Disconnecting tells the governing site to remove this brand site before clearing the local pairing.
	 */
	public function test_remove_governing_site_notifies_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$requested_urls[] = $url;
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => '{"success":true}',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['governing_site_notified'] );
		$this->assertSame( [ 'https://governing.example.com/wp-json/onesearch/v1/brand-site' ], $requested_urls );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * An unreachable governing site doesn't block the local disconnect, but the admin is told to finish it there.
	 */
	public function test_remove_governing_site_disconnects_locally_when_governing_site_unreachable(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$filter = static fn () => new \WP_Error( 'http_request_failed', 'cURL error 7' );
		add_filter( 'pre_http_request', $filter );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertTrue( $data['success'] );
		$this->assertFalse( $data['governing_site_notified'] );
		$this->assertStringContainsString( 'https://governing.example.com', $data['message'] );
		$this->assertStringContainsString( 'could not be notified', $data['message'] );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * A governing site that rejects this site's key may have already removed it, and the warning says so.
	 */
	public function test_remove_governing_site_warns_when_governing_site_does_not_recognize_it(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$filter = static fn () => [
			'response' => [
				'code'    => 401,
				'message' => 'Unauthorized',
			],
			'body'     => '',
			'headers'  => [],
			'cookies'  => [],
		];
		add_filter( 'pre_http_request', $filter );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertTrue( $data['success'] );
		$this->assertFalse( $data['governing_site_notified'] );
		$this->assertStringContainsString( 'did not recognize', $data['message'] );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * PUT secret-key regenerates and returns a new key.
	 */
	public function test_regenerate_secret_key_returns_new_key(): void {
		$old_key = Settings::get_api_key();

		// Regenerate via PUT.
		$put_request = new WP_REST_Request( 'PUT', '/onesearch/v1/secret-key' );
		$response    = $this->server->dispatch( $put_request );
		$data        = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['secret_key'] );
		$this->assertNotSame( $old_key, $data['secret_key'] );
	}
}
