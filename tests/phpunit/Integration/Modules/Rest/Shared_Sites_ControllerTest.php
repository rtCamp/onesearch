<?php
/**
 * Tests for Shared_Sites_Controller.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Shared_Sites_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the /shared-sites route.
 */
#[CoversClass( Shared_Sites_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Shared_Sites_ControllerTest extends TestCase {
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

		( new Shared_Sites_Controller() )->register_hooks();
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
}
