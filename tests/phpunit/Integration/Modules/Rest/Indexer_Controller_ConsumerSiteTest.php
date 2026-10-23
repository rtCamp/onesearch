<?php
/**
 * Tests for Indexer_Controller on a consumer site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Jobs\Progress;
use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Indexer_Controller;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Consumer-site scenarios for {@see Indexer_Controller}.
 */
#[CoversClass( Indexer_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Indexer_Controller_ConsumerSiteTest extends TestCase {
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

		( new Indexer_Controller() )->register_hooks();
		( new Indexer() )->register_hooks();
		do_action( 'rest_api_init' );

		// Keep the requests to the governing site off the network.
		add_filter( 'pre_http_request', static fn ( $preempt ) => false === $preempt ? new \WP_Error( 'http_request_failed', 'Blocked in tests.' ) : $preempt, 99 );
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
	 * Consumer site registers the /indexer route.
	 */
	public function test_registers_the_indexer_route(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Indexer_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/indexer', $routes );
	}

	/**
	 * The governing site can start indexing with this site's key, and the response has only this site's progress.
	 */
	public function test_governing_site_starts_indexing(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/indexer' );
		$request->set_header( 'X-OneSearch-Token', Settings::get_api_key() );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://governing.example.com/' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ Utils::get_current_site_url() ], array_keys( $response->get_data()['sites'] ) );
		$this->assertSame( Progress::STATUS_QUEUED, $response->get_data()['sites'][ Utils::get_current_site_url() ]['status'] );
	}

	/**
	 * The governing site can get this site's progress with this site's key, and nobody else can.
	 */
	public function test_governing_site_gets_the_progress(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		wp_set_current_user( 0 );
		$run = ( new Progress( Indexer::OPTION_PROGRESS ) )->start();

		$get = function ( string $token ): \WP_REST_Response {
			$request = new WP_REST_Request( 'GET', '/onesearch/v1/indexer' );
			$request->set_header( 'X-OneSearch-Token', $token );
			$request->set_header( 'X-OneSearch-Site-URL', 'https://governing.example.com/' );

			return $this->server->dispatch( $request );
		};

		$response = $get( Settings::get_api_key() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $run['id'], $response->get_data()['sites'][ Utils::get_current_site_url() ]['id'] );

		$this->assertSame( 401, $get( 'wrong-key' )->get_status() );
	}

	/**
	 * Indexing without a governing site fails with the reason.
	 */
	public function test_indexing_fails_without_parent_url(): void {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Remote_Client::TRANSIENT_KEY );

		$this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/indexer' ) );
		$this->run_jobs();

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertSame( Progress::STATUS_FAILED, $progress['status'] );
		$this->assertSame( [ 'No governing site is configured.' ], $progress['errors'] );
	}

	/**
	 * Indexing fails when the governing site doesn't give this site its config.
	 */
	public function test_indexing_fails_when_brand_config_fails(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_transient( Remote_Client::TRANSIENT_KEY );
		Settings::regenerate_api_key();

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				if ( false === strpos( $url, '/brand-site/config' ) ) {
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
			},
			10,
			3
		);

		$this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/indexer' ) );
		$this->run_jobs();

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertSame( Progress::STATUS_FAILED, $progress['status'] );
		$this->assertSame( [ 'Failed to connect to the governing site.' ], $progress['errors'] );
	}

	/**
	 * Indexing fails when the governing site's config has no Algolia credentials.
	 */
	public function test_indexing_fails_when_parent_has_no_algolia_credentials(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_transient( Remote_Client::TRANSIENT_KEY );
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

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $payload ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				if ( false === strpos( $url, '/brand-site/config' ) ) {
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
			},
			10,
			3
		);

		$this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/indexer' ) );
		$this->run_jobs();

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertSame( Progress::STATUS_FAILED, $progress['status'] );
		$this->assertSame( [ 'Algolia admin credentials missing.' ], $progress['errors'] );
	}
}
