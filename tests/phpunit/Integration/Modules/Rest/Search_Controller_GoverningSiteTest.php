<?php
/**
 * Tests for Search_Controller on a governing site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Jobs\Reindex_Job;
use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Search_Controller;
use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia as AlgoliaSDK;
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

		AlgoliaSDK::resetHttpClient();

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
	 * failure response, since clearing the site's old records fails.
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
	 * GET /re-index/status hands the UI a live run to restore after a page refresh, and forgets it once it finishes,
	 * so a finished run is neither restored nor left blocking the next re-index.
	 */
	public function test_reindex_status_restores_a_live_run_and_forgets_a_finished_one(): void {
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

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/re-index/status' ) )->get_data();

		$this->assertTrue( $data['active'] );
		$this->assertEquals( $jobs, $data['jobs'] );

		$job->mark_completed();
		$scheduler->persist_job( $job );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/re-index/status' ) )->get_data();

		$this->assertFalse( $data['active'] );
		$this->assertNull( $data['jobs'] );
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

	/**
	 * The lock keeps a second re-index request out while it's held, and one left behind by a request that died is
	 * taken over once it lapses, instead of blocking every re-index after it.
	 */
	public function test_reindex_lock_blocks_while_held_and_is_taken_over_once_lapsed(): void {
		$lock_key = Search_Controller::REINDEX_STATE_TRANSIENT . '_lock';

		update_option( $lock_key, time() + MINUTE_IN_SECONDS, false );

		$this->assertSame( 409, $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/re-index' ) )->get_status() );

		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		update_option( $lock_key, time() - 1, false );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/re-index' ) );

		$this->assertNotSame( 409, $response->get_status() );
		$this->assertFalse( get_option( $lock_key ), 'The request releases the lock it took over.' );
	}

	/**
	 * When some brand sites fail to start, the response reports each failure but still lists the runs that did start,
	 * and keeps them as the live re-index, so the admin can follow and cancel them instead of starting over.
	 */
	public function test_partial_reindex_reports_failures_and_keeps_the_runs_that_started(): void {
		$this->set_up_governing_site();
		Settings::set_shared_sites(
			array_map(
				static fn ( string $site ): array => [
					'name'    => 'Site ' . strtoupper( $site ),
					'url'     => sprintf( 'https://site-%s.example.com/', $site ),
					'api_key' => 'key-' . $site,
				],
				[ 'a', 'b', 'c', 'd' ]
			)
		);

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );
		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$brands = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );

			if ( 'site-a.example.com' === $host ) {
				return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}

			$responses = [
				'site-b.example.com' => [ 500, [] ],
				'site-c.example.com' => [
					200,
					[
						'success' => false,
						'errors'  => [ [ 'message' => 'Site C has no indexable entities.' ] ],
					],
				],
				'site-d.example.com' => [
					200,
					[
						'success'     => true,
						'job_id'      => 'brand-d-job',
						'batch_count' => 2,
					],
				],
			];

			if ( ! isset( $responses[ $host ] ) ) {
				return $preempt;
			}

			return [
				'response' => [
					'code'    => $responses[ $host ][0],
					'message' => '',
				],
				'body'     => (string) wp_json_encode( $responses[ $host ][1] ),
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $brands, 10, 3 );

		$data = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/re-index' ) )->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame(
			[ 'https://site-a.example.com/', 'https://site-b.example.com/', 'https://site-c.example.com/' ],
			array_column( $data['errors'], 'site_url' )
		);
		$this->assertStringContainsString( 'Site C has no indexable entities.', $data['message'], 'The brand site\'s own reason is passed on.' );
		$this->assertSame( [ $data['job_id'], 'brand-d-job' ], array_column( $data['jobs'], 'job_id' ) );

		$status = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/re-index/status' ) )->get_data();

		$this->assertTrue( $status['active'], 'A page refresh has to bring back the runs that started.' );
		$this->assertEquals( $data['jobs'], $status['jobs'] );
		$this->assertSame( 409, $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/re-index' ) )->get_status() );
	}

	/**
	 * A governing site with nothing of its own to index still waits for the brand sites it started.
	 */
	public function test_reindex_waits_for_brand_sites_when_nothing_is_indexed_here(): void {
		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					\OneSearch\Utils::normalize_url( get_site_url() ) => [],
				],
			]
		);
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

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$brand = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( ! str_contains( $url, 'site-a.example.com' ) ) {
				return $preempt;
			}

			$body = str_contains( $url, '/re-index' )
				? [
					'success'     => true,
					'job_id'      => 'brand-job',
					'batch_count' => 2,
				]
				: [
					'success' => true,
					'job'     => [
						'status'             => Abstract_Job::STATUS_RUNNING,
						'children_total'     => 2,
						'children_completed' => 0,
					],
				];

			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => (string) wp_json_encode( $body ),
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $brand, 10, 3 );

		$data = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/re-index' ) )->get_data();

		remove_filter( 'pre_http_request', $brand );
		AlgoliaSDK::resetHttpClient();

		$this->assertTrue( $data['success'] );

		$job = ( new Job_Scheduler() )->get_status( $data['job_id'] );
		$this->assertSame( Abstract_Job::STATUS_RUNNING, $job['status'], 'The brand site is still running.' );
		$this->assertTrue( $job['data']['_needs_remote_finalize'] );
	}
}
