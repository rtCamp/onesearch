<?php
/**
 * Indexer tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Jobs\Progress;
use OneSearch\Jobs\Queue;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Post_Record;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Indexer class.
 */
#[CoversClass( Indexer::class )]
final class IndexerTest extends TestCase {
	/**
	 * The paths of the intercepted Algolia requests.
	 *
	 * @var array<int, string>
	 */
	private array $paths = [];

	/**
	 * The records in the fake index, keyed by object ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $records = [];

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		$this->paths   = [];
		$this->records = [];

		( new Indexer() )->register_hooks();
	}

	/**
	 * Indexing runs in the background, replaces the site's records with its posts, and counts them as it goes.
	 *
	 * The old records stay searchable until the last batch, which deletes the ones it didn't save again.
	 */
	public function test_indexing_replaces_the_site_records_in_the_background(): void {
		$this->set_up_governing_site( [ 'page' ] );
		$page_ids = self::factory()->post->create_many( 101, [ 'post_type' => 'page' ] );
		self::factory()->post->create( [ 'post_type' => 'post' ] );

		$this->fake_algolia_index();
		$this->add_old_records();

		$this->assertTrue( Indexer::start() );
		$this->assertSame( [], $this->paths, 'Nothing is sent to Algolia until the jobs run.' );
		$this->assertSame( Progress::STATUS_QUEUED, Indexer::get_all_progress()[ Utils::get_current_site_url() ]['status'] );

		$this->run_next_job();

		$this->assertArrayHasKey( 'gone_post_0', $this->records, 'The old records are still there while indexing runs.' );
		$this->assertCount( 100, $this->get_fresh_site_post_ids() );

		$this->run_jobs();

		$this->assertEqualsCanonicalizing( $this->site_post_ids( ...$page_ids ), $this->get_fresh_site_post_ids() );
		$this->assertArrayNotHasKey( 'gone_post_0', $this->records );
		$this->assertArrayHasKey( 'other_site_post_0', $this->records, "Other sites' records are left alone." );

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertSame( Progress::STATUS_COMPLETED, $progress['status'] );
		$this->assertSame( 101, $progress['total'] );
		$this->assertSame( 101, $progress['done'] );
		$this->assertSame( [], $progress['errors'] );
	}

	/**
	 * Posts that leave the index while indexing runs don't make it skip the posts after them.
	 */
	public function test_posts_removed_during_indexing_dont_skip_others(): void {
		$this->set_up_governing_site();
		$post_ids = self::factory()->post->create_many( 110 );

		$this->fake_algolia_index();

		Indexer::start();
		$this->run_next_job();

		// Unpublish posts the first batch already indexed.
		foreach ( array_slice( $post_ids, 0, 10 ) as $post_id ) {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => 'draft',
				]
			);
		}

		$this->run_jobs();

		$this->assertEmpty( array_diff( $this->site_post_ids( ...array_slice( $post_ids, 100 ) ), $this->get_fresh_site_post_ids() ) );
	}

	/**
	 * A batch that fails to save doesn't stop the others, and the indexing run fails with Algolia's reason.
	 */
	public function test_a_failed_batch_fails_the_indexing_run(): void {
		$this->set_up_governing_site();
		$post_id = self::factory()->post->create();

		$this->fake_algolia_index( '/batch' );
		$this->add_old_records();

		Indexer::start();
		$this->run_jobs();

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertSame( Progress::STATUS_FAILED, $progress['status'] );
		$this->assertSame( 1, $progress['done'] );
		$this->assertSame( 1, $progress['failed'] );
		$this->assertSame( [ sprintf( 'Post IDs %1$d to %1$d: Failed to save records to Algolia index. forced test exception', $post_id ) ], $progress['errors'] );
		$this->assertArrayHasKey( 'gone_post_0', $this->records, 'A failed run deletes nothing.' );
	}

	/**
	 * A batch that fails on every Algolia host doesn't fail the batches after it.
	 */
	public function test_a_failed_batch_does_not_fail_the_next_ones(): void {
		$this->set_up_governing_site();
		self::factory()->post->create_many( 201 );

		$algolia = (object) [ 'fails' => false ];
		$this->mock_algolia_http_client(
			$this->paths,
			static function ( string $path ) use ( $algolia ): string {
				// What the client throws when it can't reach a host, which it then skips.
				if ( $algolia->fails && str_contains( $path, '/batch' ) ) {
					throw new \OneSearch\Vendor\Algolia\AlgoliaSearch\Exceptions\RetriableException( 'Unreachable Host' );
				}

				return str_contains( $path, '/task/' ) ? '{"status":"published","pendingTask":false}' : '{"taskID":1,"updatedAt":"2024-01-01T00:00:00.000Z"}';
			}
		);

		// Only the second batch fails.
		add_action(
			'action_scheduler_after_execute',
			static function () use ( $algolia ): void {
				$algolia->fails = true;
			}
		);
		add_action(
			'action_scheduler_failed_execution',
			static function () use ( $algolia ): void {
				$algolia->fails = false;
			}
		);

		Indexer::start();
		$this->run_jobs();

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertSame( 201, $progress['done'] );
		$this->assertSame( 100, $progress['failed'] );
		$this->assertCount( 1, $progress['errors'] );
	}

	/**
	 * An indexing run fails when its stale records can't be deleted, and keeps the records it saved.
	 */
	public function test_indexing_fails_when_the_stale_records_cannot_be_deleted(): void {
		$this->set_up_governing_site();
		$post_id = self::factory()->post->create();

		$this->fake_algolia_index( '/deleteByQuery' );

		Indexer::start();
		$this->run_jobs();

		$this->assertSame( $this->site_post_ids( $post_id ), $this->get_fresh_site_post_ids() );
		$this->assertSame( Progress::STATUS_FAILED, Indexer::get_all_progress()[ Utils::get_current_site_url() ]['status'] );
	}

	/**
	 * Indexing a governing site retries a failed delete of removed sites' records. Once it goes through, it stops showing and isn't retried, even after a failed run.
	 */
	public function test_governing_indexing_retries_a_failed_delete_of_removed_sites_records(): void {
		$this->set_up_governing_site();
		self::factory()->post->create();

		$this->fail_delete_job( 'https://old.example.com/' );

		$this->fake_algolia_index();
		$this->add_old_records();
		$this->add_site_record( 'old_site_post_0', 'https://old.example.com/' );

		$this->assertArrayHasKey( Utils::get_current_site_url(), Indexer::get_failed_jobs( Indexer::get_all_progress() ) );

		Indexer::start();
		$this->run_jobs();

		$this->assertSame( Progress::STATUS_COMPLETED, Indexer::get_all_progress()[ Utils::get_current_site_url() ]['status'] );
		$this->assertArrayNotHasKey( 'old_site_post_0', $this->records );
		$this->assertArrayHasKey( 'other_site_post_0', $this->records, 'Other sites keep their records.' );
		$this->assertSame( [], Indexer::get_failed_jobs( Indexer::get_all_progress() ) );

		// Records of that URL saved since, e.g. by another network on the same index.
		$this->add_site_record( 'old_site_post_1', 'https://old.example.com/' );

		Indexer::start();
		$this->run_jobs();

		$this->assertArrayHasKey( 'old_site_post_1', $this->records, 'A delete that went through is not tried again.' );

		$this->fake_algolia_index( '/batch' );
		Indexer::start();
		$this->run_jobs();

		$this->assertSame( Progress::STATUS_FAILED, Indexer::get_all_progress()[ Utils::get_current_site_url() ]['status'] );
		$this->assertSame( [], Indexer::get_failed_jobs( Indexer::get_all_progress() ), 'A failed run does not bring it back.' );
	}

	/**
	 * A removed site that's added back before its records are deleted keeps them, whatever the case of its URL.
	 */
	public function test_a_site_added_back_keeps_its_records(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$this->add_site_record( 'brand_a_post_0', 'https://brand-a.example.com/' );

		( new Search_Settings() )->register_hooks();
		$this->add_brand_site_a();
		Settings::remove_shared_site( 'https://brand-a.example.com/' );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand A',
					'url'     => 'https://Brand-A.example.com/',
					'api_key' => 'key-a',
				],
			]
		);

		$this->run_jobs();

		$this->assertArrayHasKey( 'brand_a_post_0', $this->records );
	}

	/**
	 * A retried delete that fails again shows the new reason.
	 */
	public function test_a_retried_delete_that_fails_again_shows_the_new_reason(): void {
		$this->set_up_governing_site();

		$this->fail_delete_job( 'https://old.example.com/' );
		$this->fake_algolia_index( '/deleteByQuery' );

		Indexer::start();
		$this->run_jobs();

		$this->assertSame(
			[
				Utils::get_current_site_url() => [
					[
						'job'   => Indexer::DELETE_SITE_RECORDS_HOOK,
						'args'  => [ [ 'https://old.example.com/' ] ],
						'error' => 'Failed to delete Algolia records by given args. forced test exception',
					],
				],
			],
			Indexer::get_failed_jobs( Indexer::get_all_progress() )
		);
	}

	/**
	 * Failed config purges show with their brand site until a later purge or an indexing run of that site, and failed deletes show with this site.
	 */
	public function test_failed_jobs_show_with_the_site_they_are_about(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'api_key' => 'brand-key',
					'url'     => 'https://brand.example.com',
				],
			]
		);
		( new Search_Settings() )->register_hooks();

		$brand = (object) [ 'is_reachable' => false ];
		add_filter(
			'pre_http_request',
			static fn () => $brand->is_reachable
				? [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '{}',
				]
				: new \WP_Error( 'http_request_failed', 'cURL error 7' )
		);

		update_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, [ 'entities' => [ 'https://brand.example.com/' => [ 'post' ] ] ] );

		$queue = new Queue();
		$queue->add( Indexer::DELETE_SITE_RECORDS_HOOK, [ [ 'https://old.example.com/' ] ] );
		$this->fail_job_with_fatal_error( (int) \ActionScheduler::store()->find_action( Indexer::DELETE_SITE_RECORDS_HOOK ), 'Allowed memory size exhausted' );

		$this->run_jobs();

		$this->assertSame(
			[
				'https://brand.example.com/'  => [
					[
						'job'   => Indexer::PURGE_BRAND_CONFIG_HOOK,
						'args'  => [ 'https://brand.example.com/' ],
						'error' => 'cURL error 7',
					],
				],
				Utils::get_current_site_url() => [
					[
						'job'   => Indexer::DELETE_SITE_RECORDS_HOOK,
						'args'  => [ [ 'https://old.example.com/' ] ],
						'error' => 'unexpected shutdown: PHP Fatal error Allowed memory size exhausted in render.php on line 3',
					],
				],
			],
			Indexer::get_failed_jobs( Indexer::get_all_progress() )
		);

		// An indexing run of the brand site that started later gets the latest config too.
		$indexed = [ 'https://brand.example.com/' => [ 'started_at' => time() + 1 ] ];
		$this->assertSame( [ Utils::get_current_site_url() ], array_keys( Indexer::get_failed_jobs( $indexed ) ) );

		$brand->is_reachable = true;
		update_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, [ 'entities' => [ 'https://brand.example.com/' => [ 'page' ] ] ] );
		$this->run_jobs();

		$this->assertSame( [ Utils::get_current_site_url() ], array_keys( Indexer::get_failed_jobs( Indexer::get_all_progress() ) ) );
	}

	/**
	 * Starting indexing again replaces the run that's going, whose batches then stop.
	 */
	public function test_starting_again_replaces_the_run_that_is_going(): void {
		$this->set_up_governing_site();
		self::factory()->post->create();

		$this->fake_algolia_index();

		Indexer::start();
		$replaced = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		Indexer::start();

		// A batch of the replaced run that was already running.
		( new Indexer() )->run_batch( $replaced['id'], 0 );
		$this->assertSame( [], $this->paths );

		$this->run_jobs();

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertNotSame( $replaced['id'], $progress['id'] );
		$this->assertSame( Progress::STATUS_COMPLETED, $progress['status'] );
		$this->assertSame( 1, $progress['done'] );
	}

	/**
	 * An indexing run whose next batch was cancelled under Tools > Scheduled Actions fails, and says so. A batch that failed before it isn't taken for the reason.
	 */
	public function test_a_run_whose_batch_was_cancelled_fails(): void {
		$this->set_up_governing_site();
		$post_ids = self::factory()->post->create_many( 101 );
		$this->fake_algolia_index( '/batch' );

		Indexer::start();
		$this->run_next_job();
		( new Queue() )->cancel( Indexer::BATCH_HOOK );

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertSame( Progress::STATUS_FAILED, $progress['status'] );
		$this->assertSame(
			[
				sprintf( 'Post IDs %d to %d: Failed to save records to Algolia index. forced test exception', $post_ids[0], $post_ids[99] ),
				'Indexing was cancelled.',
			],
			$progress['errors']
		);
	}

	/**
	 * An indexing run whose next batch is gone without a trace, e.g. deleted under Tools > Scheduled Actions, fails.
	 */
	public function test_a_run_without_a_batch_left_is_reported_as_failed(): void {
		$this->set_up_governing_site();

		Indexer::start();
		\ActionScheduler::store()->delete_action( (string) \ActionScheduler::store()->find_action( Indexer::BATCH_HOOK ) );

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertSame( Progress::STATUS_FAILED, $progress['status'] );
		$this->assertSame( [], $progress['errors'] );
	}

	/**
	 * An indexing run whose batch PHP stopped with a fatal error fails with that error.
	 */
	public function test_a_run_stopped_by_a_fatal_error_fails_with_it(): void {
		$this->set_up_governing_site();
		self::factory()->post->create_many( 101 );
		$this->fake_algolia_index();

		Indexer::start();
		$this->run_next_job();

		$batch_id = (int) \ActionScheduler::store()->find_action( Indexer::BATCH_HOOK, [ 'status' => \ActionScheduler_Store::STATUS_PENDING ] );
		$this->fail_job_with_fatal_error( $batch_id, 'Allowed memory size exhausted' );

		$progress = Indexer::get_all_progress()[ Utils::get_current_site_url() ];
		$this->assertSame( Progress::STATUS_FAILED, $progress['status'] );
		$this->assertSame( 100, $progress['done'] );
		$this->assertSame( [ 'Indexing stopped: unexpected shutdown: PHP Fatal error Allowed memory size exhausted in render.php on line 3' ], $progress['errors'] );
	}

	/**
	 * A governing site tells each brand site to re-index itself in the background, and a brand site it can't reach fails with the reason.
	 */
	public function test_governing_site_reindexes_its_brand_sites(): void {
		$this->set_up_governing_site();
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand A',
					'url'     => 'https://brand-a.example.com/',
					'api_key' => 'key-a',
				],
				[
					'name'    => 'Brand B',
					'url'     => 'https://brand-b.example.com/',
					'api_key' => 'key-b',
				],
			]
		);

		$this->fake_algolia_index();

		$sent = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$sent ) {
				if ( ! str_ends_with( $url, '/indexer' ) ) {
					return $preempt;
				}

				$sent[] = [ $args['method'], $url, $args['headers']['X-OneSearch-Token'] ];

				return str_contains( $url, 'brand-b' )
					? new \WP_Error( 'http_request_failed', 'cURL error 7' )
					: [
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

		Indexer::start();

		$all_progress = Indexer::get_all_progress();
		$this->assertSame( [ Utils::get_current_site_url(), 'https://brand-a.example.com/', 'https://brand-b.example.com/' ], array_keys( $all_progress ) );
		$this->assertSame( Progress::STATUS_QUEUED, $all_progress['https://brand-a.example.com/']['status'] );
		$this->assertSame( [], $sent, 'The brand sites are told in the background.' );

		$this->run_jobs();

		$this->assertEqualsCanonicalizing(
			[
				[ 'POST', 'https://brand-a.example.com/wp-json/onesearch/v1/indexer', 'key-a' ],
				[ 'POST', 'https://brand-b.example.com/wp-json/onesearch/v1/indexer', 'key-b' ],
			],
			$sent
		);

		$all_progress = Indexer::get_all_progress();
		$this->assertSame( Progress::STATUS_COMPLETED, $all_progress[ Utils::get_current_site_url() ]['status'] );
		// Brand A has started, and is asked for its progress as it runs.
		$this->assertTrue( Progress::is_active( $all_progress['https://brand-a.example.com/'] ) );
		$this->assertSame( [], $all_progress['https://brand-a.example.com/']['errors'] );
		$this->assertSame( Progress::STATUS_FAILED, $all_progress['https://brand-b.example.com/']['status'] );
		$this->assertSame( [ 'cURL error 7' ], $all_progress['https://brand-b.example.com/']['errors'] );
	}

	/**
	 * A brand site indexes with the governing site's latest config.
	 */
	public function test_brand_site_indexes_with_the_governing_sites_latest_config(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );

		// An outdated config, from before the governing site chose to index posts.
		set_transient( Remote_Client::TRANSIENT_KEY, [ 'indexable_entities' => [] ], HOUR_IN_SECONDS );

		$post_id = self::factory()->post->create();
		$this->fake_algolia_index();

		add_filter(
			'pre_http_request',
			static fn () => [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode(
					[
						'algolia_credentials' => [
							'app_id'    => 'test-app',
							'write_key' => 'test-key',
						],
						'indexable_entities'  => [ 'post' ],
					]
				),
			]
		);

		Indexer::start();
		$this->run_jobs();

		$this->assertSame( $this->site_post_ids( $post_id ), $this->get_fresh_site_post_ids() );
		$this->assertSame( Progress::STATUS_COMPLETED, Indexer::get_all_progress()[ Utils::get_current_site_url() ]['status'] );
	}

	/**
	 * While a brand site indexes, the governing site shows the progress the brand site gives when asked.
	 */
	public function test_governing_site_shows_the_progress_of_a_brand_site_that_runs(): void {
		$brand = $this->add_running_brand_site_a();

		Indexer::start();
		$this->run_jobs();

		Indexer::get_all_progress();
		$this->run_jobs();

		$this->assertSame( $brand->progress, Indexer::get_all_progress()['https://brand-a.example.com/'] );

		$brand->progress['status'] = Progress::STATUS_COMPLETED;
		$brand->progress['done']   = 10;

		Indexer::get_all_progress();
		$this->run_jobs();

		$this->assertSame( $brand->progress, Indexer::get_all_progress()['https://brand-a.example.com/'] );
	}

	/**
	 * A brand site whose run was cut short shows as failed, with the reason the brand site gives.
	 */
	public function test_a_brand_site_whose_run_was_cut_short_shows_as_failed(): void {
		$brand = $this->add_running_brand_site_a();

		Indexer::start();
		$this->run_jobs();

		$brand->progress['status'] = Progress::STATUS_FAILED;
		$brand->progress['errors'] = [ 'Indexing was cancelled.' ];

		Indexer::get_all_progress();
		$this->run_jobs();

		$reported = Indexer::get_all_progress()['https://brand-a.example.com/'];
		$this->assertSame( Progress::STATUS_FAILED, $reported['status'] );
		$this->assertSame( [ 'Indexing was cancelled.' ], $reported['errors'] );
	}

	/**
	 * A brand site that couldn't be reached is asked again on the next check, and shows its progress once it answers.
	 */
	public function test_a_brand_site_that_could_not_be_reached_is_asked_again(): void {
		$brand = $this->add_running_brand_site_a();

		Indexer::start();
		$this->run_jobs();

		$progress        = $brand->progress;
		$brand->progress = null;

		Indexer::get_all_progress();
		$this->run_jobs();

		$brand->progress = $progress;

		Indexer::get_all_progress();
		$this->run_jobs();

		$this->assertSame( $progress, Indexer::get_all_progress()['https://brand-a.example.com/'] );
	}

	/**
	 * Indexing that starts again while a brand site is asked for its progress isn't replaced by the answer about the run before.
	 */
	public function test_starting_again_while_a_brand_site_is_asked_keeps_the_new_run(): void {
		$brand = $this->add_running_brand_site_a();

		Indexer::start();
		$this->run_jobs();

		// The admin starts indexing again while the brand site finishes the run before.
		$asked = (object) [ 'count' => 0 ];
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( $asked, $brand ) {
				if ( 'GET' === $args['method'] && str_starts_with( $url, 'https://brand-a.example.com/' ) && 0 === $asked->count++ ) {
					Indexer::start();
					$brand->progress['status'] = Progress::STATUS_COMPLETED;
				}

				return $preempt;
			},
			9,
			3
		);

		Indexer::get_all_progress();
		$this->run_jobs();

		$reported = Indexer::get_all_progress()['https://brand-a.example.com/'];
		$this->assertSame( Progress::STATUS_QUEUED, $reported['status'] );
		$this->assertNotSame( $brand->progress['id'], $reported['id'] );
	}

	/**
	 * A brand site that's removed from the governing site loses its stored progress.
	 */
	public function test_removing_a_brand_site_deletes_its_progress(): void {
		$this->add_brand_site_a();
		( new Search_Settings() )->register_hooks();

		Indexer::start();
		$this->assertNotNull( Indexer::get_all_progress()['https://brand-a.example.com/'] );

		Settings::remove_shared_site( 'https://brand-a.example.com/' );
		$this->add_brand_site_a();

		$this->assertNull( Indexer::get_all_progress()['https://brand-a.example.com/'] );
	}

	/**
	 * Queues a delete of a removed site's records that fails with a fatal error.
	 *
	 * @param string $site_url The removed site's URL.
	 */
	private function fail_delete_job( string $site_url ): void {
		( new Queue() )->add( Indexer::DELETE_SITE_RECORDS_HOOK, [ [ $site_url ] ] );
		$this->fail_job_with_fatal_error( (int) \ActionScheduler::store()->find_action( Indexer::DELETE_SITE_RECORDS_HOOK, [ 'args' => [ [ $site_url ] ] ] ), 'Allowed memory size exhausted' );
	}

	/**
	 * Adds a record of a site to the fake index, saved before indexing.
	 *
	 * @param string $object_id The record's object ID.
	 * @param string $site_url  The site's URL.
	 */
	private function add_site_record( string $object_id, string $site_url ): void {
		$this->records[ $object_id ] = [
			'objectID'   => $object_id,
			'site_url'   => $site_url,
			'indexed_at' => time() - HOUR_IN_SECONDS,
		];
	}

	/**
	 * Makes this site a governing site with one brand site.
	 */
	private function add_brand_site_a(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand A',
					'url'     => 'https://brand-a.example.com/',
					'api_key' => 'key-a',
				],
			]
		);
	}

	/**
	 * Makes this site a governing site with one brand site, which takes the request to start indexing, and answers with `$brand->progress` when asked for its progress, or fails when it's null.
	 *
	 * @return object{progress: ?array<string, mixed>}
	 */
	private function add_running_brand_site_a(): object {
		$this->set_up_governing_site();
		$this->add_brand_site_a();
		$this->fake_algolia_index();

		$brand = (object) [
			'progress' => [
				'id'         => 'brand-run',
				'status'     => Progress::STATUS_RUNNING,
				'total'      => 10,
				'done'       => 5,
				'failed'     => 0,
				'errors'     => [],
				'started_at' => time(),
			],
		];

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( $brand ) {
				if ( 'https://brand-a.example.com/wp-json/onesearch/v1/indexer' !== $url || 'key-a' !== $args['headers']['X-OneSearch-Token'] ) {
					return $preempt;
				}

				if ( 'GET' === $args['method'] && null === $brand->progress ) {
					return new \WP_Error( 'http_request_failed', 'cURL error 7' );
				}

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => 'GET' === $args['method'] ? wp_json_encode( [ 'sites' => [ 'https://brand-a.example.com/' => $brand->progress ] ] ) : '{}',
				];
			},
			10,
			3
		);

		return $brand;
	}

	/**
	 * Runs the job that's next in the queue.
	 */
	private function run_next_job(): void {
		$action_ids = as_get_scheduled_actions(
			[
				'group'    => 'onesearch',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			],
			'ids'
		);

		\ActionScheduler::runner()->process_action( (int) reset( $action_ids ), 'PHPUnit' );
	}

	/**
	 * Fakes an Algolia index that keeps its records in `$this->records`, and understands the filters the plugin deletes by.
	 *
	 * @param ?string $fail_path A path segment whose requests fail.
	 */
	private function fake_algolia_index( ?string $fail_path = null ): void {
		$records = &$this->records;
		$paths   = &$this->paths;

		$http_client = new class( $records, $paths, $fail_path ) implements \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\HttpClientInterface {
			/** @var array<string, array<string, mixed>> */
			private array $records;

			/** @var array<int, string> */
			private array $paths;

			/** @var ?string */
			private ?string $fail_path;

			/**
			 * @param array<string, array<string, mixed>> $records   The records.
			 * @param array<int, string>                  $paths     The request paths.
			 * @param ?string                             $fail_path A path segment whose requests fail.
			 */
			public function __construct( array &$records, array &$paths, ?string $fail_path ) {
				$this->records   = &$records;
				$this->paths     = &$paths;
				$this->fail_path = $fail_path;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param \OneSearch\Vendor\Psr\Http\Message\RequestInterface $request         The PSR-7 request.
			 * @param mixed                                               $timeout         Request timeout.
			 * @param mixed                                               $connect_timeout Connection timeout.
			 * @throws \RuntimeException When the request should fail.
			 */
			public function sendRequest( \OneSearch\Vendor\Psr\Http\Message\RequestInterface $request, mixed $timeout, mixed $connect_timeout ): \OneSearch\Vendor\Psr\Http\Message\ResponseInterface { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$path          = $request->getUri()->getPath();
				$body          = json_decode( (string) $request->getBody(), true );
				$this->paths[] = $path;

				if ( null !== $this->fail_path && str_contains( $path, $this->fail_path ) ) {
					throw new \RuntimeException( 'forced test exception' );
				}

				if ( str_ends_with( $path, '/batch' ) ) {
					foreach ( $body['requests'] as $operation ) {
						$this->records[ $operation['body']['objectID'] ] = $operation['body'];
					}
				} elseif ( str_ends_with( $path, '/deleteByQuery' ) ) {
					$this->records = array_filter( $this->records, fn ( array $record ): bool => ! $this->matches( $record, $body['filters'] ) );
				}

				$response = str_contains( $path, '/task/' ) ? '{"status":"published"}' : '{"taskID":1}';

				// @phpstan-ignore return.type
				return new \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\Psr7\Response( 200, [], $response );
			}

			/**
			 * Whether a record matches a filter of `attribute:"value"` and `attribute < number` terms, joined with OR inside AND. Values match case-insensitively, like Algolia's.
			 *
			 * @param array<string, mixed> $record  The record.
			 * @param string               $filters The filter.
			 */
			private function matches( array $record, string $filters ): bool {
				foreach ( explode( ' AND ', $filters ) as $clause ) {
					$any = false;

					foreach ( explode( ' OR ', $clause ) as $term ) {
						if ( preg_match( '/^(\w+):"(.*)"$/', $term, $m ) ) {
							$any = $any || 0 === strcasecmp( (string) ( $record[ $m[1] ] ?? '' ), stripslashes( $m[2] ) );
						} elseif ( preg_match( '/^(\w+) (<|>=) (\d+)$/', $term, $m ) && isset( $record[ $m[1] ] ) ) {
							$any = $any || ( '<' === $m[2] ? $record[ $m[1] ] < (int) $m[3] : $record[ $m[1] ] >= (int) $m[3] );
						}
					}

					if ( ! $any ) {
						return false;
					}
				}

				return true;
			}
		};

		add_filter( 'onesearch_algolia_http_client', static fn () => $http_client );
	}

	/**
	 * Adds records saved before indexing: one of a post that left the site's index, and one of another site.
	 */
	private function add_old_records(): void {
		$this->records['gone_post_0']       = [
			'objectID'   => 'gone_post_0',
			'site_url'   => Utils::get_current_site_url(),
			'indexed_at' => time() - HOUR_IN_SECONDS,
		];
		$this->records['other_site_post_0'] = [
			'objectID'   => 'other_site_post_0',
			'site_url'   => 'https://other.example.com/',
			'indexed_at' => time() - HOUR_IN_SECONDS,
		];
	}

	/**
	 * Gets the `site_post_id`s of the site's records in the fake index that the latest indexing run saved.
	 *
	 * @return string[]
	 */
	private function get_fresh_site_post_ids(): array {
		$started_at = Indexer::get_all_progress()[ Utils::get_current_site_url() ]['started_at'];
		$fresh      = array_filter( $this->records, static fn ( array $record ): bool => isset( $record['site_post_id'] ) && $record['indexed_at'] >= $started_at );

		return array_values( array_unique( array_column( $fresh, 'site_post_id' ) ) );
	}

	/**
	 * Gets the `site_post_id`s of the given posts.
	 *
	 * @param int ...$post_ids The post IDs.
	 *
	 * @return string[]
	 */
	private function site_post_ids( int ...$post_ids ): array {
		return array_map( [ new Post_Record(), 'get_site_post_id' ], $post_ids );
	}
}
