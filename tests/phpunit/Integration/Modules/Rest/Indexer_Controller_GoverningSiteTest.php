<?php
/**
 * Tests for Indexer_Controller on a governing site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Jobs\Progress;
use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Indexer_Controller;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Governing-site scenarios for {@see Indexer_Controller}.
 */
#[CoversClass( Indexer_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Indexer_Controller_GoverningSiteTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		( new Indexer_Controller() )->register_hooks();
		( new Indexer() )->register_hooks();
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
	 * Governing site registers the /indexer route.
	 */
	public function test_registers_the_indexer_route(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Indexer_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/indexer', $routes );
	}

	/**
	 * POST /indexer queues the indexing of the site and its brand sites, and GET /indexer shows how it went.
	 */
	public function test_reindex_queues_the_sites_and_shows_their_progress(): void {
		$this->set_up_brand_sites();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/indexer' ) );
		$sites    = $response->get_data()['sites'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ Utils::get_current_site_url(), 'https://site-a.example.com/', 'https://site-b.example.com/' ], array_keys( $sites ) );
		$this->assertSame( [ Progress::STATUS_QUEUED ], array_unique( array_column( $sites, 'status' ) ) );

		$this->reply_to_brand_sites( 200, [] );
		$this->run_jobs();

		$sites = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/indexer' ) )->get_data()['sites'];

		// Without Algolia credentials, the site's own indexing run fails.
		$this->assertSame( Progress::STATUS_FAILED, $sites[ Utils::get_current_site_url() ]['status'] );
		// The brand site started, and is asked for its progress from here on.
		$this->assertTrue( Progress::is_active( $sites['https://site-a.example.com/'] ) );
		$this->assertSame( [], $sites['https://site-a.example.com/']['errors'] );
	}

	/**
	 * Brand sites that fail to start indexing fail with their own message, or the reason they couldn't be reached.
	 */
	public function test_reindex_records_why_a_brand_site_failed(): void {
		$this->set_up_brand_sites();

		$this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/indexer' ) );

		$this->reply_to_brand_sites(
			500,
			[
				'code'    => 'onesearch_algolia_not_configured',
				'message' => 'Algolia admin credentials missing.',
				'data'    => [ 'status' => 500 ],
			]
		);
		$this->run_jobs();

		$sites = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/indexer' ) )->get_data()['sites'];

		$this->assertSame( Progress::STATUS_FAILED, $sites['https://site-a.example.com/']['status'] );
		$this->assertSame( [ 'Algolia admin credentials missing.' ], $sites['https://site-a.example.com/']['errors'] );
	}

	/**
	 * Only admins can see the progress.
	 */
	public function test_get_progress_requires_an_admin(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/indexer' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Adds two brand sites, and makes sure the site itself has no Algolia credentials.
	 */
	private function set_up_brand_sites(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
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
	}

	/**
	 * Makes every brand site answer its /indexer requests with the given status and body.
	 *
	 * @param int                  $code The response status.
	 * @param array<string, mixed> $body The response body.
	 */
	private function reply_to_brand_sites( int $code, array $body ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $code, $body ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				if ( ! str_ends_with( $url, '/indexer' ) ) {
					return $preempt;
				}

				return [
					'response' => [
						'code'    => $code,
						'message' => '',
					],
					'body'     => wp_json_encode( $body ),
					'headers'  => [],
					'cookies'  => [],
				];
			},
			10,
			3
		);
	}
}
