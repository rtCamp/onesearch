<?php
/**
 * Tests for Governing_Data_Controller.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

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
class Governing_Data_ControllerTest extends TestCase {
	/**
	 * Controller under test.
	 */
	private Governing_Data_Controller $controller;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = null;

		$this->controller = new Governing_Data_Controller();
	}

	// ── Route registration ──────────────────────────────────────────────

	/**
	 * Governing site registers brand-config GET and all-post-types.
	 *
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function test_register_routes_for_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$this->controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$ns     = '/' . Governing_Data_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-config', $routes );
		$this->assertArrayHasKey( 'GET', $routes[ $ns . '/brand-config' ][0]['methods'] );
		$this->assertArrayHasKey( $ns . '/all-post-types', $routes );
	}

	/**
	 * Consumer site registers brand-config DELETE and all-post-types.
	 *
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function test_register_routes_for_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$this->controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$ns     = '/' . Governing_Data_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-config', $routes );
		$this->assertArrayHasKey( 'DELETE', $routes[ $ns . '/brand-config' ][0]['methods'] );
		$this->assertArrayHasKey( $ns . '/all-post-types', $routes );
	}

	/**
	 * Standalone site only registers all-post-types.
	 *
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function test_register_routes_for_standalone_site(): void {
		delete_option( Settings::OPTION_SITE_TYPE );

		$this->controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$ns     = '/' . Governing_Data_Controller::NAMESPACE;

		$this->assertArrayNotHasKey( $ns . '/brand-config', $routes );
		$this->assertArrayHasKey( $ns . '/all-post-types', $routes );
	}

	// ── get_brand_config ────────────────────────────────────────────────

	/**
	 * Returns 403 when origin is empty.
	 */
	public function test_get_brand_config_rejects_empty_origin(): void {
		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );

		$response = $this->controller->get_brand_config( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'onesearch_unauthorized_site', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	/**
	 * Returns 403 when origin is not a known shared site.
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

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'origin', 'https://unknown.example.com' );

		$response = $this->controller->get_brand_config( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'onesearch_unauthorized_site', $response->get_error_code() );
	}

	/**
	 * Returns full brand config for a known shared site.
	 */
	public function test_get_brand_config_returns_config_for_known_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$site_url = 'https://brand.example.com/';
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => $site_url,
					'api_key' => 'the-key',
				],
			]
		);

		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'origin', $site_url );

		$response = $this->controller->get_brand_config( $request );
		$data     = $response->get_data();

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

		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.example.com',
					'api_key' => 'key',
				],
			]
		);

		// No search settings configured.
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'origin', 'https://brand.example.com' );

		$response = $this->controller->get_brand_config( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['search_settings']['algolia_enabled'] );
		$this->assertSame( [], $data['search_settings']['searchable_sites'] );
	}

	// ── delete_brand_config_cache ───────────────────────────────────────

	/**
	 * Clears the transient and returns success.
	 */
	public function test_delete_brand_config_cache_clears_transient(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'cached' => true ], 3600 );

		$response = $this->controller->delete_brand_config_cache();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * Returns success even when transient was already absent.
	 */
	public function test_delete_brand_config_cache_succeeds_when_no_cache(): void {
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		$response = $this->controller->delete_brand_config_cache();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
	}

	// ── get_all_post_types ──────────────────────────────────────────────

	/**
	 * Returns local post types for a non-governing site.
	 */
	public function test_get_all_post_types_returns_local_types_for_non_governing(): void {
		delete_option( Settings::OPTION_SITE_TYPE );

		$response = $this->controller->get_all_post_types();
		$data     = $response->get_data();

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

		$response = $this->controller->get_all_post_types();
		$data     = $response->get_data();

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

		$response = $this->controller->get_all_post_types();
		$data     = $response->get_data();

		$site_url   = trailingslashit( get_site_url() );
		$post_types = $data['sites'][ $site_url ]['post_types'];
		$first      = $post_types[0];

		$this->assertArrayHasKey( 'slug', $first );
		$this->assertArrayHasKey( 'label', $first );
		$this->assertArrayHasKey( 'restBase', $first );
	}
}
