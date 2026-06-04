<?php
/**
 * Tests for Search_Controller on a consumer site.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Rest\Search_Controller;
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
	 * POST /re-index on a consumer site with no parent URL configured returns
	 * a 400 with the `no_parent_url` error code.
	 */
	public function test_reindex_returns_error_without_parent_url(): void {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'no_parent_url', $data['code'] );
	}

	/**
	 * Consumer reindex propagates a non-200 brand-config response from the parent
	 * as a `onesearch_rest_failed_to_connect` error.
	 */
	public function test_reindex_propagates_brand_config_failure_from_parent(): void {
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

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'onesearch_rest_failed_to_connect', $data['code'] );
	}

	/**
	 * Consumer reindex reaches the indexing step when the parent returns a valid
	 * brand-config payload; without Algolia credentials, indexing reports failure
	 * but the brand-config fetch path is fully traversed.
	 */
	public function test_reindex_proceeds_when_parent_returns_valid_brand_config(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );
		Settings::regenerate_api_key();

		$payload = [
			'algolia_credentials' => [
				'app_id'    => '',
				'write_key' => '',
			],
			'search_settings'     => [
				'algolia_enabled'  => false,
				'searchable_sites' => [],
			],
			'indexable_entities'  => [ 'post' ],
			'available_sites'     => [],
		];

		$filter = static function ( $preempt, $args, $url ) use ( $payload ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/brand-config' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode( $payload ),
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
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'message', $data );
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
		$jobs = [
			[
				'site_name'   => 'Test Site',
				'site_url'    => get_site_url(),
				'job_id'      => 'test_job_789',
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
}
