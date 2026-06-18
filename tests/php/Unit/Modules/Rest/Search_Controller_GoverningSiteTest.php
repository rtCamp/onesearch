<?php
/**
 * Tests for Search_Controller on a governing site.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

use OneSearch\Modules\Jobs\Reindex_Job;
use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Search_Controller;
use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Governing-site scenarios for {@see Search_Controller}.
 *
 * The re-index endpoint is registered on every site type, so its governing-mode
 * behavior is exercised here too.
 */
#[CoversClass( Search_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Search_Controller_GoverningSiteTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		Search_Controller::clear_reindex_state();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		( new Search_Controller() )->register_hooks();
		do_action( 'rest_api_init' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		parent::tear_down();
	}

	/**
	 * Governing site registers algolia-credentials, indexable-entities, and re-index.
	 */
	public function test_registers_governing_endpoints(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Search_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/algolia-credentials', $routes );
		$this->assertArrayHasKey( $ns . '/indexable-entities', $routes );
		$this->assertArrayHasKey( $ns . '/re-index', $routes );
	}

	/**
	 * GET /algolia-credentials returns empty strings when no credentials are stored.
	 */
	public function test_get_algolia_credentials_returns_empty_when_unset(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/algolia-credentials' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( '', $data['app_id'] );
		$this->assertSame( '', $data['write_key'] );
	}

	/**
	 * GET /algolia-credentials returns the stored values.
	 */
	public function test_get_algolia_credentials_returns_stored_values(): void {
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP_ID',
				'write_key' => 'TEST_WRITE_KEY',
			]
		);

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/algolia-credentials' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'TEST_APP_ID', $data['app_id'] );
		$this->assertSame( 'TEST_WRITE_KEY', $data['write_key'] );
	}

	/**
	 * POST /algolia-credentials with an empty app_id returns 400.
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

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_algolia_credentials_invalid', $data['code'] );
	}

	/**
	 * POST /algolia-credentials with an empty write_key returns 400.
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

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_algolia_credentials_invalid', $data['code'] );
	}

	/**
	 * POST /algolia-credentials with no fields is rejected by the schema validator
	 * before the callback runs, because app_id and write_key are declared required.
	 */
	public function test_update_algolia_credentials_rejects_missing_fields(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/algolia-credentials' );
		$request->set_body( wp_json_encode( [] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * GET /indexable-entities returns the stored map.
	 */
	public function test_get_indexable_entities_returns_stored_data(): void {
		$entities = [
			'entities' => [
				'https://site-a.example.com/' => [ 'post', 'page' ],
			],
		];
		update_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, $entities );

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/indexable-entities' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $entities, $data['indexableEntities'] );
	}

	/**
	 * GET /indexable-entities returns an empty array when nothing is stored.
	 */
	public function test_get_indexable_entities_returns_empty_when_unset(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/indexable-entities' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( [], $data['indexableEntities'] );
	}

	/**
	 * POST /indexable-entities persists valid data.
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

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $entities, $data['indexableEntities'] );
		$this->assertSame( $entities, get_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES ) );
	}

	/**
	 * POST /indexable-entities with a non-array body is rejected by schema validation
	 * (the route declares `entities` as a required array arg).
	 */
	public function test_set_indexable_entities_rejects_non_array_body(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/indexable-entities' );
		$request->set_body( '"not-an-array"' );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * POST /indexable-entities saves an empty entities map as valid data.
	 */
	public function test_set_indexable_entities_allows_empty_object(): void {
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
	 * POST /re-index on a governing site with no Algolia credentials returns a
	 * failure response: get_post_types_to_index() yields [] (no WP_Error), then
	 * index_all_posts() collects an error from the missing-credentials delete_by()
	 * call and reports success: false.
	 */
	public function test_reindex_returns_failure_without_algolia(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'message', $data );
	}

	/**
	 * Governing reindex fans out to each shared site via wp_safe_remote_post.
	 */
	public function test_reindex_dispatches_to_each_shared_site(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
				[
					'name'    => 'Site B',
					'url'     => 'https://site-b.example.com/',
					'api_key' => 'key-b',
				],
			]
		);

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/re-index' ) ) {
				return $preempt;
			}
			$requested_urls[] = $url;
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode( [ 'success' => true ] ),
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$this->server->dispatch( $request );

		remove_filter( 'pre_http_request', $filter );

		$this->assertCount( 2, $requested_urls );
		$this->assertStringContainsString( 'site-a.example.com', $requested_urls[0] );
		$this->assertStringContainsString( 'site-b.example.com', $requested_urls[1] );
	}

	/**
	 * Non-200 from a child site flips `success` to false on the governing response.
	 */
	public function test_reindex_records_child_non_200_as_failure(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'APP',
				'write_key' => 'KEY',
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
			]
		);

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/re-index' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 500,
					'message' => 'Internal Server Error',
				],
				'body'     => '',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertFalse( $data['success'] );
	}

	/**
	 * A WP_Error from a child site flips `success` to false on the governing response.
	 */
	public function test_reindex_records_child_wp_error_as_failure(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'APP',
				'write_key' => 'KEY',
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
			]
		);

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/re-index' ) ) {
				return $preempt;
			}
			return new \WP_Error( 'http_request_failed', 'cURL timeout' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertFalse( $data['success'] );
	}

	/**
	 * GET /re-index/status returns inactive when no reindex is running.
	 */
	public function test_reindex_status_returns_inactive_when_no_state(): void {
		delete_transient( Search_Controller::REINDEX_STATE_TRANSIENT );

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/re-index/status' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertFalse( $data['active'] );
		$this->assertNull( $data['jobs'] );
	}

	/**
	 * GET /re-index/status returns active when a reindex state transient exists.
	 */
	public function test_reindex_status_returns_active_with_state(): void {
		$scheduler = new Job_Scheduler();
		$job       = new Reindex_Job();
		$job->mark_running();
		$scheduler->persist_job( $job );

		$jobs = [
			[
				'site_name'   => 'Test Site',
				'site_url'    => 'https://example.com',
				'job_id'      => $job->get_id(),
				'batch_count' => 5,
			],
		];
		set_transient( Search_Controller::REINDEX_STATE_TRANSIENT, $jobs, 3600 );

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/re-index/status' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['active'] );
		$this->assertEquals( $jobs, $data['jobs'] );
	}

	/**
	 * POST /re-index returns 409 when a reindex is already active.
	 */
	public function test_reindex_returns_409_when_active_reindex_exists(): void {
		$scheduler = new Job_Scheduler();
		$job       = new Reindex_Job();
		$job->mark_running();
		$scheduler->persist_job( $job );

		$jobs = [
			[
				'site_name'   => 'Test Site',
				'site_url'    => get_site_url(),
				'job_id'      => $job->get_id(),
				'batch_count' => 3,
			],
		];
		set_transient( Search_Controller::REINDEX_STATE_TRANSIENT, $jobs, 3600 );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'onesearch_reindex_active', $data['code'] );
	}
}
