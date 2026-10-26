<?php
/**
 * Tests for Indexer_Controller on a governing site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Indexer_Controller;
use OneSearch\Modules\Search\Jobs\Index_Site_Job;
use OneSearch\Modules\Search\Jobs\Jobs;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Governing-site scenarios for {@see Indexer_Controller}.
 */
#[CoversClass( Indexer_Controller::class )]
class Indexer_Controller_GoverningSiteTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		$this->set_up_governing_site();
		$this->fake_algolia_index();

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
	 * A re-index queues the indexing of this site and of each brand site, and the job of a brand site asks it to index itself in the background.
	 */
	public function test_an_admin_reindexes_the_network(): void {
		$post_id = self::factory()->post->create();
		$this->share_brand_sites( [ 'brand-a' ] );

		$sent = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$sent ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$sent[] = $args['method'] . ' ' . $url;

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '{}',
				];
			},
			10,
			3
		);

		$response = $this->reindex();

		$this->assertSame( [ 'started' => true ], $response->get_data() );
		$this->assertSame(
			[
				Utils::get_current_site_url()  => 'queued',
				'https://brand-a.example.com/' => 'queued',
			],
			array_column( $this->get_statuses_of( 'index_site' ), 'status', 'key' )
		);
		$this->assertSame( [], $sent, 'The brand site is asked in the background.' );

		$this->run_jobs();

		$this->assertSame( [ 'POST https://brand-a.example.com/wp-json/onesearch/v1/indexer' ], $sent );
		$this->assertSame( [ Utils::get_current_site_url() => 'completed' ], array_column( $this->get_statuses_of( 'index_site' ), 'status', 'key' ), 'The brand site reports its own run once it has been asked.' );
		$this->assertSame( [ $post_id ], $this->get_algolia_post_ids() );
	}

	/**
	 * A brand site that can't be reached fails its job with why, and the other sites still index.
	 */
	public function test_a_brand_site_that_cannot_be_reached_fails_its_job(): void {
		$post_id = self::factory()->post->create();
		$this->share_brand_sites( [ 'brand-a', 'brand-b' ] );

		$sent = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$sent ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				if ( str_contains( $url, 'brand-a' ) ) {
					return new \WP_Error( 'http_request_failed', 'cURL error 7' );
				}

				$sent[] = $args['method'] . ' ' . $url;

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '{}',
				];
			},
			10,
			3
		);

		$this->assertSame( [ 'started' => true ], $this->reindex()->get_data() );

		$this->run_jobs();

		$this->assertSame( [ 'POST https://brand-b.example.com/wp-json/onesearch/v1/indexer' ], $sent );
		$this->assertSame( [ $post_id ], $this->get_algolia_post_ids() );
		$this->assertSame(
			[
				[
					'job'      => 'index_site',
					'site'     => Utils::get_current_site_url(),
					'status'   => 'completed',
					'error'    => null,
					'progress' => [
						'total'  => 1,
						'done'   => 1,
						'failed' => 0,
					],
				],
				[
					'job'    => 'index_site',
					'site'   => 'https://brand-a.example.com/',
					'status' => 'failed',
					'error'  => 'cURL error 7',
				],
			],
			$this->get_status()['jobs']
		);
	}

	/**
	 * A brand site's row shows this site's job about it until the brand site has been asked, and then the brand site's own.
	 */
	public function test_the_status_shows_the_brand_sites_own_run_once_it_has_been_asked(): void {
		$this->share_brand_sites( [ 'brand-a' ] );

		$report = [
			'sites' => [ 'https://brand-a.example.com/' => [ 'index' => null ] ],
			'jobs'  => [
				[
					'job'      => 'index_site',
					'site'     => 'https://brand-a.example.com/',
					'status'   => 'running',
					'error'    => null,
					'progress' => [
						'total'  => 3,
						'done'   => 1,
						'failed' => 0,
					],
				],
			],
		];

		add_filter(
			'pre_http_request',
			static fn (): array => [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode( $report ),
			]
		);

		$this->reindex();

		$brand_jobs = fn (): array => array_values( array_filter( $this->get_status()['jobs'], static fn ( array $job ): bool => 'https://brand-a.example.com/' === $job['site'] ) );

		$this->assertSame(
			[
				[
					'job'    => 'index_site',
					'site'   => 'https://brand-a.example.com/',
					'status' => 'queued',
					'error'  => null,
				],
			],
			$brand_jobs()
		);

		$this->run_jobs();

		$this->assertSame( $report['jobs'], $brand_jobs() );
	}

	/**
	 * A re-index indexes the posts of a run that failed.
	 */
	public function test_reindex_indexes_the_posts_of_a_failed_run(): void {
		$post_id = self::factory()->post->create();

		// Algolia isn't set up yet.
		delete_option( \OneSearch\Modules\Search\Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ Utils::get_current_site_url(), [ $post_id ] ] );
		$this->run_jobs();
		$this->assertSame( [], $this->get_algolia_post_ids() );

		$this->set_up_governing_site();
		$this->reindex();
		$this->run_jobs();

		$this->assertSame( 'completed', $this->get_statuses_of( 'index_site' )[0]['status'] );
		$this->assertSame( [ $post_id ], $this->get_algolia_post_ids() );
	}

	/**
	 * A re-index starts Action Scheduler's runner at the end of the request, so the run doesn't wait for WP-Cron.
	 */
	public function test_reindex_starts_the_runner(): void {
		remove_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
		// Ending the output buffers would end PHPUnit's too.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		$sent = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$sent ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$sent[] = $args['method'] . ' ' . $url;

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '',
				];
			},
			10,
			3
		);

		$this->assertSame( 200, $this->reindex()->get_status() );
		$this->assertSame( [], $sent );

		do_action( 'shutdown' );

		$this->assertNotEmpty(
			array_filter( $sent, static fn ( string $request ): bool => str_starts_with( $request, 'POST ' . admin_url( 'admin-ajax.php?action=as_async_request_queue_runner' ) ) ),
			'The runner was started.'
		);
	}

	/**
	 * The status reports this site's index, with the posts that failed, and how its last re-index went.
	 */
	public function test_the_status_reports_this_sites_index_and_jobs(): void {
		$post_id = self::factory()->post->create();
		$too_big = self::factory()->post->create( [ 'post_title' => 'Too Big' ] );
		$this->make_too_big_to_index( $too_big );

		$this->reindex();
		$this->run_jobs();

		$this->assertSame(
			[
				'sites' => [
					Utils::get_current_site_url() => [
						'index' => [
							'total'   => 2,
							'indexed' => 1,
							'failed'  => [
								[
									'id'       => $too_big,
									'title'    => 'Too Big',
									'edit_url' => admin_url( sprintf( 'post.php?post=%d&action=edit', $too_big ) ),
									'error'    => 'The post is too big to index.',
								],
							],
						],
					],
				],
				'jobs'  => [
					[
						'job'      => 'index_site',
						'site'     => Utils::get_current_site_url(),
						'status'   => 'completed',
						'error'    => null,
						'progress' => [
							'total'  => 2,
							'done'   => 2,
							'failed' => 1,
						],
					],
				],
			],
			$this->get_status()
		);
		$this->assertSame( [ $post_id ], $this->get_algolia_post_ids() );
	}

	/**
	 * The status adds each brand site's report, cleaned up and about the brand's stored URL, or why it couldn't get it.
	 */
	public function test_the_status_adds_the_brand_reports(): void {
		$this->share_brand_sites( [ 'brand-a', 'brand-b', 'brand-c' ] );

		$reports = [
			'brand-a' => [
				'sites' => [
					'https://a.internal/' => [
						'index' => [
							'total'   => '3',
							'indexed' => 2,
							'failed'  => [
								[
									'id'       => '7',
									'title'    => '<b>Big</b> post',
									'edit_url' => 'https://brand-a.example.com/wp-admin/post.php?post=7&action=edit',
									'error'    => 'The post is too big to index.',
									'extra'    => true,
								],
								'not a post',
							],
						],
					],
				],
				'jobs'  => [
					[
						'job'      => 'index_site',
						'site'     => 'https://a.internal/',
						'status'   => 'running',
						'error'    => null,
						'progress' => [
							'total'  => 3,
							'done'   => 1,
							'failed' => 1,
						],
					],
				],
			],
			'brand-b' => [ 'sites' => [ 'https://b.internal/' => [ 'index' => [ 'total' => 'lots' ] ] ] ],
		];

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( $reports ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$brand = (string) preg_replace( '#^https://([^.]+)\..*$#', '$1', $url );

				return isset( $reports[ $brand ] ) ? [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => wp_json_encode( $reports[ $brand ] ),
				] : new \WP_Error( 'http_request_failed', 'cURL error 7' );
			},
			10,
			3
		);

		$status = $this->get_status();

		$this->assertSame(
			[
				'total'   => 3,
				'indexed' => 2,
				'failed'  => [
					[
						'id'       => 7,
						'title'    => 'Big post',
						'edit_url' => 'https://brand-a.example.com/wp-admin/post.php?post=7&action=edit',
						'error'    => 'The post is too big to index.',
					],
				],
			],
			$status['sites']['https://brand-a.example.com/']['index']
		);
		$this->assertSame( [ 'error' => 'The site returned an invalid response.' ], $status['sites']['https://brand-b.example.com/'] );
		$this->assertSame( [ 'error' => 'cURL error 7' ], $status['sites']['https://brand-c.example.com/'] );
		$this->assertSame(
			[
				[
					'job'      => 'index_site',
					'site'     => 'https://brand-a.example.com/',
					'status'   => 'running',
					'error'    => null,
					'progress' => [
						'total'  => 3,
						'done'   => 1,
						'failed' => 1,
					],
				],
			],
			$status['jobs']
		);
	}

	/**
	 * A job in a brand site's report keeps only the shape the plugin knows, about the brand's stored URL.
	 */
	public function test_the_status_cleans_up_the_brand_jobs(): void {
		$this->share_brand_sites( [ 'brand-a' ] );

		$report = [
			'sites' => [ 'https://a.internal/' => [ 'index' => null ] ],
			'jobs'  => [
				'nope',
				[
					'job'    => 'unknown',
					'status' => 'queued',
				],
				[
					'job'    => 'index_site',
					'status' => 'paused',
				],
				[
					'job'      => 'index_site',
					'site'     => 'https://elsewhere.example.com/',
					'status'   => 'running',
					'error'    => '<script>x</script><b>Bad</b> thing',
					'progress' => [
						'total' => 'many',
						'done'  => '3',
					],
					'extra'    => true,
				],
			],
		];

		add_filter(
			'pre_http_request',
			static fn (): array => [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode( $report ),
			]
		);

		$this->assertSame(
			[
				[
					'job'      => 'index_site',
					'site'     => 'https://brand-a.example.com/',
					'status'   => 'running',
					'error'    => 'Bad thing',
					'progress' => [
						'total'  => 0,
						'done'   => 3,
						'failed' => 0,
					],
				],
			],
			$this->get_status()['jobs']
		);
	}

	/**
	 * Only admins can re-index, or see how it's going.
	 */
	public function test_requires_an_admin(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/indexer' ) )->get_status() );
		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/indexer' ) )->get_status() );
		$this->assertSame( [], Jobs::registry()->get_statuses() );
	}

	/**
	 * Re-indexes as an admin.
	 */
	private function reindex(): \WP_REST_Response {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		return $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/indexer' ) );
	}

	/**
	 * Gets the status as an admin.
	 *
	 * @return array<string, mixed>
	 */
	private function get_status(): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		return (array) $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/indexer' ) )->get_data();
	}

	/**
	 * Shares brand sites at https://{name}.example.com, each with its own key.
	 *
	 * @param string[] $names The brand sites' names.
	 */
	private function share_brand_sites( array $names ): void {
		Settings::set_shared_sites(
			array_map(
				static fn ( string $name ): array => [
					'name'    => $name,
					'url'     => 'https://' . $name . '.example.com',
					'api_key' => 'key-' . $name,
				],
				$names
			)
		);
	}
}
