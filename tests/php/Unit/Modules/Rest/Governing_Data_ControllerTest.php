<?php
/**
 * Tests for Governing_Data_Controller.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Governing_Data_Controller;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the governing data REST endpoints.
 */
#[CoversClass( Governing_Data_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Governing_Data_ControllerTest extends TestCase {
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
	 * Hook the controller's routes onto the test REST server.
	 *
	 * Call AFTER any option setup that affects which routes are registered.
	 */
	private function init_routes(): void {
		( new Governing_Data_Controller() )->register_hooks();
		do_action( 'rest_api_init' );
	}

	/**
	 * Governing site registers brand-config GET and all-post-types.
	 */
	public function test_register_routes_for_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$this->init_routes();

		$routes = $this->server->get_routes();
		$ns     = '/' . Governing_Data_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-config', $routes );
		$this->assertArrayHasKey( 'GET', $routes[ $ns . '/brand-config' ][0]['methods'] );
		$this->assertArrayHasKey( $ns . '/all-post-types', $routes );
	}

	/**
	 * Consumer site registers brand-config DELETE and all-post-types.
	 */
	public function test_register_routes_for_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$this->init_routes();

		$routes = $this->server->get_routes();
		$ns     = '/' . Governing_Data_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-config', $routes );
		$this->assertArrayHasKey( 'DELETE', $routes[ $ns . '/brand-config' ][0]['methods'] );
		$this->assertArrayHasKey( $ns . '/all-post-types', $routes );
	}

	/**
	 * Standalone site only registers all-post-types.
	 */
	public function test_register_routes_for_standalone_site(): void {
		delete_option( Settings::OPTION_SITE_TYPE );

		$this->init_routes();

		$routes = $this->server->get_routes();
		$ns     = '/' . Governing_Data_Controller::NAMESPACE;

		$this->assertArrayNotHasKey( $ns . '/brand-config', $routes );
		$this->assertArrayHasKey( $ns . '/all-post-types', $routes );
	}

	/**
	 * Returns 403 when origin is empty.
	 *
	 * No origin -> auth layer falls back to manage_options (admin is set in set_up),
	 * so the request reaches the controller which itself rejects the empty origin.
	 */
	public function test_get_brand_config_rejects_empty_origin(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->init_routes();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'onesearch_unauthorized_site', $response->get_data()['code'] );
	}

	/**
	 * Rejects an origin that does not belong to any shared site.
	 *
	 * The cross-site auth layer requires a valid X-OneSearch-Token for any non-same-host
	 * origin; an unknown origin cannot present one, so the request is rejected at the
	 * permission layer (401) before the controller runs.
	 */
	public function test_get_brand_config_rejects_unknown_origin(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Known Site',
					'url'     => 'https://known.example.com',
					'api_key' => 'key-known',
				],
			]
		);
		$this->init_routes();

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'origin', 'https://unknown.example.com' );

		$response = $this->server->dispatch( $request );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	/**
	 * Returns full brand config for a known shared site presenting a valid token.
	 */
	public function test_get_brand_config_returns_config_for_known_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$site_url = 'https://brand.example.com/';
		$api_key  = 'the-key';
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => $site_url,
					'api_key' => $api_key,
				],
			]
		);

		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);
		$this->init_routes();

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'origin', $site_url );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'algolia_credentials', $data );
		$this->assertSame( 'TEST_APP', $data['algolia_credentials']['app_id'] );
		$this->assertArrayHasKey( 'search_settings', $data );
		$this->assertArrayHasKey( 'indexable_entities', $data );
		$this->assertArrayHasKey( 'available_sites', $data );
	}

	/**
	 * Returns default search settings when none configured for the requesting site.
	 */
	public function test_get_brand_config_returns_default_search_settings(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$api_key = 'brand-key';
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.example.com',
					'api_key' => $api_key,
				],
			]
		);

		// No search settings configured.
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );
		$this->init_routes();

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'origin', 'https://brand.example.com' );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['search_settings']['algolia_enabled'] );
		$this->assertSame( [], $data['search_settings']['searchable_sites'] );
	}

	/**
	 * Clears the transient and returns success.
	 */
	public function test_delete_brand_config_cache_clears_transient(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'cached' => true ], 3600 );
		$this->init_routes();

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-config' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * Returns success even when transient was already absent.
	 */
	public function test_delete_brand_config_cache_succeeds_when_no_cache(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );
		$this->init_routes();

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-config' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Returns local post types for a non-governing site.
	 */
	public function test_get_all_post_types_returns_local_types_for_non_governing(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		$this->init_routes();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/all-post-types' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Every WP install has at least 'post' and 'page'.
		$site_url = trailingslashit( get_site_url() );
		$this->assertArrayHasKey( $site_url, $data['sites'] );

		$slugs = array_column( $data['sites'][ $site_url ]['post_types'], 'slug' );
		$this->assertContains( 'post', $slugs );
		$this->assertContains( 'page', $slugs );
	}

	/**
	 * Returns error array when governing but shared sites have issues.
	 */
	public function test_get_all_post_types_governing_reports_errors_for_bad_sites(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name' => 'Test Site',
					'url'  => 'https://test.example.com',
					// Missing api_key.
				],
			]
		);
		$this->init_routes();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/all-post-types' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		// Should still include the local site's post types.
		$site_url = trailingslashit( get_site_url() );
		$this->assertArrayHasKey( $site_url, $data['sites'] );
		// Should have errors for the bad shared site.
		$this->assertNotEmpty( $data['errors'] );
	}

	/**
	 * Each post type entry has slug, label, and restBase.
	 */
	public function test_get_all_post_types_post_type_structure(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		$this->init_routes();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/all-post-types' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$site_url   = trailingslashit( get_site_url() );
		$post_types = $data['sites'][ $site_url ]['post_types'];
		$first      = $post_types[0];

		$this->assertArrayHasKey( 'slug', $first );
		$this->assertArrayHasKey( 'label', $first );
		$this->assertArrayHasKey( 'restBase', $first );
	}
}
