<?php
/**
 * Tests for Basic_Options_Controller.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

use OneSearch\Modules\Rest\Basic_Options_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the basic options REST endpoints.
 */
#[CoversClass( Basic_Options_Controller::class )]
class Basic_Options_ControllerTest extends TestCase {
	/**
	 * Controller under test.
	 */
	private Basic_Options_Controller $controller;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = null;

		$this->controller = new Basic_Options_Controller();
	}

	// ── Route registration ──────────────────────────────────────────────

	/**
	 * Verify all expected routes are registered.
	 *
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function test_register_routes_registers_expected_endpoints(): void {
		$this->controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$ns     = '/' . Basic_Options_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/site-type', $routes );
		$this->assertArrayHasKey( $ns . '/shared-sites', $routes );
		$this->assertArrayHasKey( $ns . '/health-check', $routes );
		$this->assertArrayHasKey( $ns . '/secret-key', $routes );
		$this->assertArrayHasKey( $ns . '/governing-site', $routes );
	}

	// ── get_site_type ───────────────────────────────────────────────────

	/**
	 * Returns governing site type when configured.
	 */
	public function test_get_site_type_returns_governing(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$response = $this->controller->get_site_type();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( Settings::SITE_TYPE_GOVERNING, $data['site_type'] );
	}

	/**
	 * Returns consumer site type when configured.
	 */
	public function test_get_site_type_returns_consumer(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$response = $this->controller->get_site_type();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( Settings::SITE_TYPE_CONSUMER, $data['site_type'] );
	}

	/**
	 * Returns null when site type is not set.
	 */
	public function test_get_site_type_returns_null_when_unset(): void {
		delete_option( Settings::OPTION_SITE_TYPE );

		$response = $this->controller->get_site_type();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertNull( $data['site_type'] );
	}

	// ── get_shared_sites ────────────────────────────────────────────────

	/**
	 * Returns empty array when no shared sites are configured.
	 */
	public function test_get_shared_sites_returns_empty_when_unset(): void {
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		$response = $this->controller->get_shared_sites();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( [], $data['shared_sites'] );
	}

	/**
	 * Returns stored shared sites as a numerically-indexed array.
	 */
	public function test_get_shared_sites_returns_indexed_array(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand A',
					'url'     => 'https://brand-a.example.com',
					'api_key' => 'key-a',
				],
				[
					'name'    => 'Brand B',
					'url'     => 'https://brand-b.example.com',
					'api_key' => 'key-b',
				],
			]
		);

		$response = $this->controller->get_shared_sites();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertCount( 2, $data['shared_sites'] );
		$this->assertSame( 'Brand A', $data['shared_sites'][0]['name'] );
		$this->assertNotSame( 'Brand B', $data['shared_sites'][0]['name'] );
	}

	// ── set_shared_sites ────────────────────────────────────────────────

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

		$response = $this->controller->set_shared_sites( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'duplicate_site_url', $response->get_error_code() );
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

		$response = $this->controller->set_shared_sites( $request );
		$data     = $response->get_data();

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

		$response = $this->controller->set_shared_sites( $request );
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

		$response = $this->controller->set_shared_sites( $request );
		$data     = $response->get_data();

		// sites_data falls back to [] so this should succeed with empty data.
		$this->assertSame( [], $data['shared_sites'] );
	}

	// ── health_check ────────────────────────────────────────────────────

	/**
	 * Returns success response.
	 */
	public function test_health_check_returns_success(): void {
		$response = $this->controller->health_check();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'message', $data );
	}

	// ── get_governing_site ──────────────────────────────────────────────

	/**
	 * Returns stored governing site URL.
	 */
	public function test_get_governing_site_returns_stored_url(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$response = $this->controller->get_governing_site();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'https://governing.example.com', $data['governing_site_url'] );
	}

	/**
	 * Returns null when governing site not configured.
	 */
	public function test_get_governing_site_returns_null_when_unset(): void {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		$response = $this->controller->get_governing_site();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertNull( $data['governing_site_url'] );
	}

	// ── remove_governing_site ───────────────────────────────────────────

	/**
	 * Removes the governing site option and returns success.
	 */
	public function test_remove_governing_site_deletes_option(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$response = $this->controller->remove_governing_site();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * Succeeds even when no governing site was previously set.
	 */
	public function test_remove_governing_site_succeeds_when_already_unset(): void {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		$response = $this->controller->remove_governing_site();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
	}

	// ── secret-key ─────────────────────────────────────────────────────────

	/**
	 * GET secret-key returns a non-empty key (auto-generated if absent).
	 *
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function test_get_secret_key_returns_key(): void {
		$this->controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$ns     = '/' . Basic_Options_Controller::NAMESPACE;

		// Invoke the GET callback directly from the registered route.
		$callback = $routes[ $ns . '/secret-key' ][0]['callback'];
		$response = call_user_func( $callback );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['secret_key'] );
	}

	/**
	 * PUT secret-key regenerates and returns a new key.
	 *
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function test_regenerate_secret_key_returns_new_key(): void {
		$this->controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$ns     = '/' . Basic_Options_Controller::NAMESPACE;

		// Get the current key first.
		$get_callback = $routes[ $ns . '/secret-key' ][0]['callback'];
		$old_data     = call_user_func( $get_callback )->get_data();
		$old_key      = $old_data['secret_key'];

		// Invoke the PUT/PATCH callback.
		$put_callback = $routes[ $ns . '/secret-key' ][1]['callback'];
		$response     = call_user_func( $put_callback );
		$data         = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['secret_key'] );
		$this->assertNotSame( $old_key, $data['secret_key'] );
	}
}
