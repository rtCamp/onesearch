<?php
/**
 * Tests for Search_Controller.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Rest\Search_Controller;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the search REST endpoints.
 */
#[CoversClass( Search_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Search_ControllerTest extends TestCase {
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
		( new Search_Controller() )->register_hooks();
		do_action( 'rest_api_init' );
	}

	/**
	 * Verify re-index route is always registered.
	 */
	public function test_register_routes_registers_reindex_endpoint(): void {
		$this->init_routes();

		$routes = $this->server->get_routes();
		$ns     = '/' . Search_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/re-index', $routes );
	}

	/**
	 * Governing site registers algolia-credentials and indexable-entities.
	 */
	public function test_register_routes_registers_governing_only_endpoints(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->init_routes();

		$routes = $this->server->get_routes();
		$ns     = '/' . Search_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/algolia-credentials', $routes );
		$this->assertArrayHasKey( $ns . '/indexable-entities', $routes );
	}

	/**
	 * Consumer site does not register governing-only endpoints.
	 */
	public function test_register_routes_skips_governing_endpoints_for_consumer(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$this->init_routes();

		$routes = $this->server->get_routes();
		$ns     = '/' . Search_Controller::NAMESPACE;

		$this->assertArrayNotHasKey( $ns . '/algolia-credentials', $routes );
		$this->assertArrayNotHasKey( $ns . '/indexable-entities', $routes );
	}

	/**
	 * Returns empty strings when credentials are not set.
	 */
	public function test_get_algolia_credentials_returns_empty_when_unset(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		$this->init_routes();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/algolia-credentials' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( '', $data['app_id'] );
		$this->assertSame( '', $data['write_key'] );
	}

	/**
	 * Returns stored credential values.
	 */
	public function test_get_algolia_credentials_returns_stored_values(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP_ID',
				'write_key' => 'TEST_WRITE_KEY',
			]
		);
		$this->init_routes();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/algolia-credentials' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'TEST_APP_ID', $data['app_id'] );
		$this->assertSame( 'TEST_WRITE_KEY', $data['write_key'] );
	}

	/**
	 * Returns 400 error when app_id is empty.
	 */
	public function test_update_algolia_credentials_rejects_empty_app_id(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->init_routes();

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/algolia-credentials' );
		$request->set_body(
			wp_json_encode(
				[
					'app_id'    => '',
					'write_key' => 'some-key',
				]
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_algolia_credentials_invalid', $data['code'] );
	}

	/**
	 * Returns 400 error when write_key is empty.
	 */
	public function test_update_algolia_credentials_rejects_empty_write_key(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->init_routes();

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/algolia-credentials' );
		$request->set_body(
			wp_json_encode(
				[
					'app_id'    => 'APPID',
					'write_key' => '',
				]
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_algolia_credentials_invalid', $data['code'] );
	}

	/**
	 * Returns 400 error when required fields are missing entirely.
	 *
	 * The route declares app_id and write_key as required args, so the REST
	 * schema validator rejects the request before the callback runs.
	 */
	public function test_update_algolia_credentials_rejects_missing_fields(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->init_routes();

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/algolia-credentials' );
		$request->set_body( wp_json_encode( [] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Returns stored indexable entities.
	 */
	public function test_get_indexable_entities_returns_stored_data(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$entities = [
			'entities' => [
				'https://site-a.example.com/' => [ 'post', 'page' ],
			],
		];
		update_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, $entities );
		$this->init_routes();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/indexable-entities' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $entities, $data['indexableEntities'] );
	}

	/**
	 * Returns empty array when no entities are configured.
	 */
	public function test_get_indexable_entities_returns_empty_when_unset(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		$this->init_routes();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/indexable-entities' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( [], $data['indexableEntities'] );
	}

	/**
	 * Saves valid entities and persists to database.
	 */
	public function test_set_indexable_entities_saves_valid_data(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->init_routes();

		$entities = [
			'entities' => [
				'https://site-a.example.com/' => [ 'post' ],
			],
		];

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/indexable-entities' );
		$request->set_body( wp_json_encode( $entities ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $entities, $data['indexableEntities'] );
		$this->assertSame( $entities, get_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES ) );
	}

	/**
	 * Returns 400 when body is not an array.
	 *
	 * The route declares `entities` as a required array arg, so a non-array body
	 * (which has no `entities` key) is rejected by schema validation.
	 */
	public function test_set_indexable_entities_rejects_non_array_body(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->init_routes();

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/indexable-entities' );
		$request->set_body( '"not-an-array"' );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Saves an empty entities map as valid data.
	 */
	public function test_set_indexable_entities_allows_empty_object(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->init_routes();

		$body = [ 'entities' => [] ];

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/indexable-entities' );
		$request->set_body( wp_json_encode( $body ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $body, $data['indexableEntities'] );
	}

	/**
	 * Reindex on governing site without Algolia credentials returns failure response.
	 */
	public function test_reindex_governing_returns_failure_without_algolia(): void {
		// Governing with no Algolia: get_post_types_to_index() returns [] (no WP_Error),
		// then index_all_posts() calls delete_by() which fails on missing Algolia
		// credentials, collecting an error entry and returning success: false.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		$this->init_routes();

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'message', $data );
	}

	/**
	 * Reindex on consumer site with no parent URL returns error.
	 */
	public function test_reindex_consumer_returns_error_without_parent_url(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );
		$this->init_routes();

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Controller returns WP_Error 'no_parent_url' with status 400.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'no_parent_url', $data['code'] );
	}
}
