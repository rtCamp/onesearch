<?php
/**
 * Tests for Search_Controller.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

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
class Search_ControllerTest extends TestCase {
	/**
	 * Controller under test.
	 */
	private Search_Controller $controller;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = null;

		$this->controller = new Search_Controller();
	}

	// ── Route registration ──────────────────────────────────────────────

	/**
	 * Verify re-index route is always registered.
	 *
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function test_register_routes_registers_reindex_endpoint(): void {
		$this->controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$ns     = '/' . Search_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/re-index', $routes );
	}

	/**
	 * Governing site registers algolia-credentials and indexable-entities.
	 *
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function test_register_routes_registers_governing_only_endpoints(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$controller = new Search_Controller();
		$controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$ns     = '/' . Search_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/algolia-credentials', $routes );
		$this->assertArrayHasKey( $ns . '/indexable-entities', $routes );
	}

	/**
	 * Consumer site does not register governing-only endpoints.
	 *
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function test_register_routes_skips_governing_endpoints_for_consumer(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$controller = new Search_Controller();
		$controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$ns     = '/' . Search_Controller::NAMESPACE;

		$this->assertArrayNotHasKey( $ns . '/algolia-credentials', $routes );
		$this->assertArrayNotHasKey( $ns . '/indexable-entities', $routes );
	}

	// ── get_algolia_credentials ─────────────────────────────────────────

	/**
	 * Returns empty strings when credentials are not set.
	 */
	public function test_get_algolia_credentials_returns_empty_when_unset(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$response = $this->controller->get_algolia_credentials();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( '', $data['app_id'] );
		$this->assertSame( '', $data['write_key'] );
	}

	/**
	 * Returns stored credential values.
	 */
	public function test_get_algolia_credentials_returns_stored_values(): void {
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP_ID',
				'write_key' => 'TEST_WRITE_KEY',
			]
		);

		$response = $this->controller->get_algolia_credentials();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'TEST_APP_ID', $data['app_id'] );
		$this->assertSame( 'TEST_WRITE_KEY', $data['write_key'] );
	}

	// ── update_algolia_credentials ──────────────────────────────────────

	/**
	 * Returns 400 error when app_id is empty.
	 */
	public function test_update_algolia_credentials_rejects_empty_app_id(): void {
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

		$response = $this->controller->update_algolia_credentials( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'onesearch_algolia_credentials_invalid', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Returns 400 error when write_key is empty.
	 */
	public function test_update_algolia_credentials_rejects_empty_write_key(): void {
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

		$response = $this->controller->update_algolia_credentials( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'onesearch_algolia_credentials_invalid', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Returns 400 error when both fields are missing entirely.
	 */
	public function test_update_algolia_credentials_rejects_missing_fields(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/algolia-credentials' );
		$request->set_body( wp_json_encode( [] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->controller->update_algolia_credentials( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'onesearch_algolia_credentials_invalid', $response->get_error_code() );
	}

	// ── get_indexable_entities ───────────────────────────────────────────

	/**
	 * Returns stored indexable entities.
	 */
	public function test_get_indexable_entities_returns_stored_data(): void {
		$entities = [
			'entities' => [
				'https://site-a.example.com/' => [ 'post', 'page' ],
			],
		];
		update_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, $entities );

		$response = $this->controller->get_indexable_entities();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $entities, $data['indexableEntities'] );
	}

	/**
	 * Returns empty array when no entities are configured.
	 */
	public function test_get_indexable_entities_returns_empty_when_unset(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );

		$response = $this->controller->get_indexable_entities();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( [], $data['indexableEntities'] );
	}

	// ── set_indexable_entities ───────────────────────────────────────────

	/**
	 * Saves valid entities and persists to database.
	 */
	public function test_set_indexable_entities_saves_valid_data(): void {
		$entities = [
			'entities' => [
				'https://site-a.example.com/' => [ 'post' ],
			],
		];

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/indexable-entities' );
		$request->set_body( wp_json_encode( $entities ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->controller->set_indexable_entities( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $entities, $data['indexableEntities'] );
		$this->assertSame( $entities, get_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES ) );
	}

	/**
	 * Returns 400 when body is not an array.
	 */
	public function test_set_indexable_entities_rejects_non_array_body(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/indexable-entities' );
		$request->set_body( '"not-an-array"' );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->controller->set_indexable_entities( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'invalid_data', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Saves an empty object as valid entities.
	 */
	public function test_set_indexable_entities_allows_empty_object(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/indexable-entities' );
		$request->set_body( wp_json_encode( [] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->controller->set_indexable_entities( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( [], $data['indexableEntities'] );
	}

	// ── reindex ─────────────────────────────────────────────────────────

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

		$response = $this->controller->reindex();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
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

		$response = $this->controller->reindex();

		// Should be a WP_Error because get_post_types_to_index() fails.
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'no_parent_url', $response->get_error_code() );
	}
}
