<?php
/**
 * Tests for Search_Controller on a consumer site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Jobs\Reindex_Job;
use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Rest\Search_Controller;
use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Consumer-site scenarios for {@see Search_Controller}.
 */
#[CoversClass( Search_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Search_Controller_ConsumerSiteTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

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

		parent::tear_down();
	}

	/**
	 * Consumer site only registers re-index; governing-only endpoints are omitted.
	 */
	public function test_skips_governing_endpoints(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Search_Controller::NAMESPACE;

		$this->assertArrayNotHasKey( $ns . '/algolia-credentials', $routes );
		$this->assertArrayNotHasKey( $ns . '/indexable-entities', $routes );
		$this->assertArrayHasKey( $ns . '/re-index', $routes );
	}

	/**
	 * POST /re-index on a consumer site with no parent URL configured reports failure.
	 */
	public function test_reindex_fails_without_parent_url(): void {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['success'] );
	}

	/**
	 * Consumer reindex reports failure when the parent's brand-config request fails.
	 */
	public function test_reindex_fails_when_brand_config_fails(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );
		Settings::regenerate_api_key();

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/brand-config' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 500,
					'message' => 'Server Error',
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

		$this->assertSame( 200, $response->get_status() );
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
	}

	/**
	 * POST /re-index returns 409 when a reindex is already active.
	 */
	public function test_reindex_returns_409_when_active_reindex_exists(): void {
		$job = new Reindex_Job();
		$job->mark_running();
		( new Job_Scheduler() )->persist_job( $job );

		$jobs = [
			[
				'site_name'   => 'Test Site',
				'site_url'    => get_site_url(),
				'job_id'      => $job->get_id(),
				'batch_count' => 2,
			],
		];
		set_transient( Search_Controller::REINDEX_STATE_TRANSIENT, $jobs, 3600 );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'onesearch_reindex_active', $data['code'] );

		delete_transient( Search_Controller::REINDEX_STATE_TRANSIENT );
	}

	/**
	 * A reindex the governing site starts here runs in the background, since the governing site is waiting on the
	 * response and clearing the old records could outlast its request.
	 */
	public function test_reindex_from_governing_site_runs_in_the_background(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		Settings::regenerate_api_key();

		$method = new \ReflectionMethod( Governing_Data_Handler::class, 'set_brand_config_cache' );
		$method->invoke(
			null,
			[
				'algolia_credentials' => [
					'app_id'    => 'test-app',
					'write_key' => 'test-key',
				],
				'search_settings'     => [
					'algolia_enabled'  => true,
					'searchable_sites' => [],
				],
				'indexable_entities'  => [ 'post' ],
				'available_sites'     => [],
			]
		);

		self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		$paths = [];
		$this->mock_algolia_http_client( $paths );

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$request->set_header( 'X-OneSearch-Token', Settings::get_api_key() );
		$request->set_header( 'Origin', 'https://governing.example.com' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['batch_count'], 'The batch count is estimated up front.' );
		$this->assertSame( [], $paths, 'Algolia is only called once the background run starts.' );

		$this->assertSame( Abstract_Job::STATUS_PENDING, ( new Job_Scheduler() )->get_status( $data['job_id'] )['status'] );
		$this->assertNotFalse(
			as_next_scheduled_action(
				Job_Scheduler::HOOK,
				[
					'job_class' => Reindex_Job::class,
					'job_id'    => $data['job_id'],
				],
				'onesearch_reindex'
			)
		);

		delete_transient( Search_Controller::REINDEX_STATE_TRANSIENT );
	}
}
