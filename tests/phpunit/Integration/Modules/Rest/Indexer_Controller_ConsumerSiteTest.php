<?php
/**
 * Tests for Indexer_Controller on a consumer site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Indexer_Controller;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Index_Status;
use OneSearch\Modules\Search\Jobs\Index_Site_Job;
use OneSearch\Modules\Search\Jobs\Jobs;
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
	public function setUp(): void {
		parent::setUp();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		( new Indexer_Controller() )->register_hooks();
		( new Jobs() )->register_hooks();
		do_action( 'rest_api_init' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * The governing site starts indexing with this site's key. The brand site answers right away, then indexes with the governing site's latest config, and deletes only its own old records.
	 */
	public function test_governing_site_starts_indexing(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		$this->fake_algolia_index();
		$this->add_algolia_record( 'old_brand_post', Utils::get_current_site_url() );
		$this->add_algolia_record( 'governing_post', 'https://governing.example.com/' );
		$post_id = self::factory()->post->create();

		// An outdated config, from before the governing site chose to index posts.
		set_transient( Remote_Client::TRANSIENT_KEY, [ 'indexable_entities' => [] ], HOUR_IN_SECONDS );
		$this->answer_brand_config(
			200,
			[
				'algolia_credentials' => [
					'app_id'    => 'test-app',
					'write_key' => 'test-key',
				],
				'indexable_entities'  => [ 'post' ],
			]
		);

		$response = $this->server->dispatch( $this->governing_request( 'POST', Settings::get_api_key() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'started' => true ], $response->get_data() );
		$this->assertSame( 'queued', $this->get_statuses_of( 'index_site' )[0]['status'] );

		$this->run_jobs();

		$this->assertSame( 'completed', $this->get_statuses_of( 'index_site' )[0]['status'] );
		$this->assertSame( [ $post_id ], $this->get_algolia_post_ids() );
		$this->assertArrayNotHasKey( 'old_brand_post', $this->algolia_records );
		$this->assertArrayHasKey( 'governing_post', $this->algolia_records );
	}

	/**
	 * The governing site gets this site's report with this site's key, and nobody else can.
	 */
	public function test_governing_site_gets_the_report(): void {
		$this->set_up_brand_site();
		self::factory()->post->create();
		Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ Utils::get_current_site_url() ] );

		$response = $this->server->dispatch( $this->governing_request( 'GET', Settings::get_api_key() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				Utils::get_current_site_url() => [
					'index' => [
						'total'   => 1,
						'indexed' => 0,
						'failed'  => [],
					],
				],
			],
			$response->get_data()['sites']
		);
		$this->assertSame( [ 'index_site' ], array_column( $response->get_data()['jobs'], 'job' ) );

		$this->assertSame( 401, $this->server->dispatch( $this->governing_request( 'GET', 'wrong-key' ) )->get_status() );
	}

	/**
	 * A site that can't tell which post types it indexes has no index summary.
	 */
	public function test_the_status_without_post_types(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/indexer' ) );

		$this->assertSame( [ Utils::get_current_site_url() => [ 'index' => null ] ], $response->get_data()['sites'] );
	}

	/**
	 * Indexing fails with the reason when the governing site doesn't give this site its config, or when the config has no Algolia credentials. The posts get no error.
	 */
	public function test_indexing_fails_without_the_governing_sites_config(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create();

		$this->answer_brand_config( 500, [] );
		$this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/indexer' ) );
		$this->run_jobs();

		$this->assertSame( 'failed', $this->get_statuses_of( 'index_site' )[0]['status'] );
		$this->assertSame( 'Failed to connect to the governing site.', $this->get_statuses_of( 'index_site' )[0]['error'] );

		$this->answer_brand_config( 200, [ 'indexable_entities' => [ 'post' ] ] );
		$this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/indexer' ) );
		$this->run_jobs();

		$this->assertSame( 'failed', $this->get_statuses_of( 'index_site' )[0]['status'] );
		$this->assertSame( 'Algolia admin credentials missing.', $this->get_statuses_of( 'index_site' )[0]['error'] );
		$this->assertSame( '', get_post_meta( $post_id, Index_Status::META_ERROR, true ) );
	}

	/**
	 * Builds a request signed the way the governing site signs them.
	 *
	 * @param string $method The method.
	 * @param string $token  The key it's signed with.
	 *
	 * @return \WP_REST_Request<array<string,mixed>>
	 */
	private function governing_request( string $method, string $token ): WP_REST_Request {
		Settings::set_parent_site_url( 'https://governing.example.com' );
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( $method, '/onesearch/v1/indexer' );
		$request->set_header( 'X-OneSearch-Token', $token );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://governing.example.com/' );

		return $request;
	}

	/**
	 * Makes the governing site answer requests for the brand config, replacing the answer before.
	 *
	 * @param int                 $code   The status code.
	 * @param array<string,mixed> $config The config.
	 */
	private function answer_brand_config( int $code, array $config ): void {
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			static fn ( $preempt, $args, $url ) => str_contains( $url, '/brand-site/config' ) ? [ // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				'response' => [
					'code'    => $code,
					'message' => '',
				],
				'body'     => wp_json_encode( $config ),
			] : $preempt,
			10,
			3
		);
	}
}
