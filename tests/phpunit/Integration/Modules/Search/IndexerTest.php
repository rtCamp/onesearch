<?php
/**
 * Indexer tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Post_Record;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia as AlgoliaSDK;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Indexer class.
 */
#[CoversClass( Indexer::class )]
final class IndexerTest extends TestCase {
	/**
	 * The path of the index named after the governing test site.
	 */
	private const INDEX_PATH = '/1/indexes/onesearch_governing_example_org_wp_posts';

	/**
	 * The paths of the intercepted Algolia requests.
	 *
	 * @var array<int, string>
	 */
	private array $paths = [];

	/**
	 * The intercepted Algolia requests.
	 *
	 * @var array<int, array{path: string, body: string}>
	 */
	private array $requests = [];

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		AlgoliaSDK::resetHttpClient();

		parent::tearDown();
	}

	/**
	 * Credentials are only valid when they grant every permission the indexer uses.
	 */
	public function test_validate_credentials(): void {
		$this->mock_api_key_acl( [ 'addObject', 'deleteObject' ] );
		$this->assertFalse( Indexer::validate_credentials( 'test-app', 'test-key' ) );

		// Reading the settings needs its own permission.
		$this->mock_api_key_acl( [ 'search', 'addObject', 'deleteIndex', 'editSettings' ] );
		$this->assertFalse( Indexer::validate_credentials( 'test-app', 'test-key' ) );

		$this->mock_api_key_acl( [ 'search', 'addObject', 'deleteIndex', 'settings', 'editSettings' ] );
		$this->assertTrue( Indexer::validate_credentials( 'test-app', 'test-key' ) );
	}

	/**
	 * Governing sites read their indexable post types from their own settings.
	 */
	public function test_get_indexable_post_types_on_governing_site(): void {
		$this->set_up_governing_site( [ 'post', 'page', 'post' ] );

		$this->assertSame( [ 'post', 'page' ], Indexer::get_indexable_post_types() );
	}

	/**
	 * Brand sites get their indexable post types from the governing site.
	 */
	public function test_get_indexable_post_types_on_brand_site(): void {
		$this->set_up_brand_site( [ 'page' ] );

		$this->assertSame( [ 'page' ], Indexer::get_indexable_post_types() );
	}

	/**
	 * Brand sites that can't reach the governing site get an error.
	 */
	public function test_get_indexable_post_types_on_brand_site_without_parent(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$this->assertWPError( Indexer::get_indexable_post_types() );
	}

	/**
	 * Values are quoted, with quotes and backslashes escaped.
	 */
	public function test_filter_any_of(): void {
		$this->assertSame(
			'post_type:"post" OR post_type:"say \"hi\"" OR post_type:"back\\\\slash"',
			Indexer::filter_any_of( 'post_type', [ 'post', 'say "hi"', 'back\\slash' ] )
		);
	}

	/**
	 * Operations fail with a known error code when there are no credentials.
	 */
	public function test_returns_not_configured_error_without_credentials(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->mock_algolia_http_client( $this->paths );

		$result = ( new Indexer() )->search( 'hello' );

		$this->assertWPError( $result );
		$this->assertSame( Indexer::ERROR_NOT_CONFIGURED, $result->get_error_code() );
		$this->assertSame( [], $this->paths );
	}

	/**
	 * Operations fail with a known error code when the governing site is unknown.
	 */
	public function test_returns_not_configured_error_without_governing_site(): void {
		$this->mock_algolia_http_client( $this->paths );

		$result = ( new Indexer() )->delete_post( 1 );

		$this->assertWPError( $result );
		$this->assertSame( Indexer::ERROR_NOT_CONFIGURED, $result->get_error_code() );
		$this->assertSame( [], $this->paths );
	}

	/**
	 * Brand sites use the governing site's index and credentials.
	 */
	public function test_brand_site_uses_governing_index(): void {
		$this->set_up_brand_site();
		$this->mock_algolia_http_client( $this->paths );

		( new Indexer() )->search( 'hello' );

		$this->assertSame( [ '/1/indexes/onesearch_governing_example_com_wp_posts/query' ], $this->paths );
	}

	/**
	 * Searching doesn't touch the index settings.
	 */
	public function test_search_does_not_push_settings(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths );

		( new Indexer() )->search( 'hello', [ 'hitsPerPage' => 5 ] );

		$this->assertSame( [ self::INDEX_PATH . '/query' ], $this->paths );
	}

	/**
	 * Outdated index settings are pushed once, before the first write.
	 */
	public function test_pushes_settings_once_before_writing(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$post    = self::factory()->post->create_and_get();
		$indexer = new Indexer();
		$indexer->save_post( $post );
		$indexer->delete_post( $post->ID );

		$calls = array_values( array_filter( $this->paths, static fn ( string $path ) => ! str_contains( $path, '/task/' ) ) );

		$this->assertSame(
			[
				// Read, and then pushed, since the mock's settings don't match.
				self::INDEX_PATH . '/settings',
				self::INDEX_PATH . '/settings',
				self::INDEX_PATH . '/batch',
				// Checked for stale chunks.
				self::INDEX_PATH . '/query',
				self::INDEX_PATH . '/deleteByQuery',
			],
			$calls
		);
		$this->assertSame( Post_Record::get_index_settings(), json_decode( $this->requests[1]['body'], true ) );
	}

	/**
	 * Unchanged index settings aren't pushed again, since that can rebuild the whole index.
	 */
	public function test_skips_pushing_unchanged_settings(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client(
			$this->paths,
			// Algolia returns every setting, and may normalize the values we sent.
			static fn ( string $path ): string => str_ends_with( $path, '/settings' )
				? (string) wp_json_encode( [ 'distinct' => 1 ] + Post_Record::get_index_settings() + [ 'hitsPerPage' => 20 ] )
				: '{"taskID":1,"status":"published"}'
		);

		( new Indexer() )->save_post( self::factory()->post->create_and_get() );

		$this->assertSame(
			[ self::INDEX_PATH . '/settings', self::INDEX_PATH . '/batch', self::INDEX_PATH . '/query' ],
			array_values( array_filter( $this->paths, static fn ( string $path ) => ! str_contains( $path, '/task/' ) ) )
		);
	}

	/**
	 * Chunks left over from when a post was longer are deleted when it's saved.
	 */
	public function test_save_post_deletes_stale_chunks(): void {
		$this->set_up_governing_site();

		$post         = self::factory()->post->create_and_get( [ 'post_content' => 'Short.' ] );
		$site_post_id = ( new Post_Record() )->get_site_post_id( $post->ID );

		$this->mock_algolia_http_client(
			$this->paths,
			static fn ( string $path ): string => str_ends_with( $path, '/query' )
				? (string) wp_json_encode(
					[
						'hits' => array_map(
							static fn ( int $chunk_index ): array => [
								'site_post_id' => $site_post_id,
								'chunk_index'  => $chunk_index,
							],
							[ 0, 1, 2 ]
						),
					]
				)
				: '{"taskID":1,"status":"published"}',
			null,
			$this->requests
		);

		$this->assertTrue( ( new Indexer() )->save_post( $post ) );
		$this->assertSame( [ sprintf( 'site_post_id:"%s" AND chunk_index >= 1', $site_post_id ) ], $this->get_delete_filters() );
	}

	/**
	 * Saving a post that didn't shrink skips the slow delete.
	 */
	public function test_save_post_skips_deleting_without_stale_chunks(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$this->assertTrue( ( new Indexer() )->save_post( self::factory()->post->create_and_get() ) );
		$this->assertSame( [], $this->get_delete_filters() );
	}

	/**
	 * Writes wait for tasks that aren't published on the first check.
	 */
	public function test_waits_for_pending_tasks(): void {
		$this->set_up_governing_site();

		$task_checks = 0;
		$this->mock_algolia_http_client(
			$this->paths,
			static function ( string $path ) use ( &$task_checks ): string {
				if ( ! str_contains( $path, '/task/' ) ) {
					return '{"taskID":1}';
				}

				// Every task is published on its second check.
				++$task_checks;
				return 1 === $task_checks % 2 ? '{"status":"notPublished"}' : '{"status":"published"}';
			}
		);

		$indexer = new Indexer();

		// Settings, batch, and delete tasks.
		$this->assertTrue( $indexer->save_post( self::factory()->post->create_and_get() ) );
		$this->assertTrue( $indexer->delete_post( 42 ) );
		$this->assertSame( 6, $task_checks );
	}

	/**
	 * A post's records are deleted by the `site_post_id` they were saved with.
	 */
	public function test_delete_post(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$this->assertTrue( ( new Indexer() )->delete_post( 42 ) );
		$this->assertSame(
			[ sprintf( 'site_post_id:"%s"', ( new Post_Record() )->get_site_post_id( 42 ) ) ],
			$this->get_delete_filters()
		);
	}

	/**
	 * Sites' records are deleted by their normalized URLs.
	 */
	public function test_delete_site_records(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$this->assertTrue( ( new Indexer() )->delete_site_records( [ 'https://a.example.com', 'https://b.example.com/' ] ) );
		$this->assertSame(
			[ 'site_url:"https://a.example.com/" OR site_url:"https://b.example.com/"' ],
			$this->get_delete_filters()
		);
	}

	/**
	 * Deleting doesn't touch the index settings, which would create the index if it's missing.
	 */
	public function test_deleting_does_not_push_settings(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths );

		$indexer = new Indexer();
		$indexer->delete_post( 42 );
		$indexer->delete_site_records( [ 'https://a.example.com/' ] );

		$this->assertSame( [], array_filter( $this->paths, static fn ( string $path ): bool => str_ends_with( $path, '/settings' ) ) );
	}

	/**
	 * Deleting no sites' records doesn't call Algolia.
	 */
	public function test_delete_site_records_without_sites(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths );

		$this->assertTrue( ( new Indexer() )->delete_site_records( [] ) );
		$this->assertSame( [], $this->paths );
	}

	/**
	 * Deletes the whole index.
	 */
	public function test_delete_index(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths );

		$this->assertTrue( ( new Indexer() )->delete_index() );
		$this->assertSame( [ self::INDEX_PATH, self::INDEX_PATH . '/task/1' ], $this->paths );
	}

	/**
	 * Fetches every record of the posts, in batches, grouped by post.
	 */
	public function test_get_post_records(): void {
		$this->set_up_governing_site();

		$hits = [
			[
				'site_post_id' => 'site_1',
				'chunk_index'  => 1,
			],
			[
				'site_post_id' => 'site_2',
				'chunk_index'  => 0,
			],
			[
				'site_post_id' => 'site_1',
				'chunk_index'  => 0,
			],
		];
		$this->mock_algolia_http_client(
			$this->paths,
			static fn (): string => (string) wp_json_encode( [ 'hits' => $hits ] ),
			null,
			$this->requests
		);

		$site_post_ids = array_map( static fn ( int $i ): string => 'site_' . $i, range( 1, 21 ) );
		$records       = ( new Indexer() )->get_post_records( $site_post_ids );

		$this->assertSame(
			[
				'site_1' => [ $hits[0], $hits[2], $hits[0], $hits[2] ],
				'site_2' => [ $hits[1], $hits[1] ],
			],
			$records
		);

		$queries = array_map( static fn ( array $request ): array => json_decode( $request['body'], true ), $this->requests );

		$this->assertCount( 2, $queries, 'The posts should be fetched in batches of 20.' );
		$this->assertSame( Indexer::filter_any_of( 'site_post_id', array_slice( $site_post_ids, 0, 20 ) ), $queries[0]['filters'] );
		$this->assertSame( 'site_post_id:"site_21"', $queries[1]['filters'] );
		$this->assertFalse( $queries[0]['distinct'] );
	}

	/**
	 * Failing to fetch the records returns an error.
	 */
	public function test_get_post_records_returns_error_when_request_fails(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths, null, '/query' );

		$this->assertWPError( ( new Indexer() )->get_post_records( [ 'site_1' ] ) );
	}

	/**
	 * Reindexing replaces the site's records with its indexable posts.
	 */
	public function test_reindex(): void {
		$this->set_up_governing_site( [ 'page' ] );
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		self::factory()->post->create( [ 'post_type' => 'post' ] );

		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$this->assertTrue( ( new Indexer() )->reindex() );
		$this->assertSame( self::INDEX_PATH . '/settings', $this->paths[0], 'The index should be set up before anything else.' );
		$this->assertSame(
			[ sprintf( 'site_url:"%s"', Utils::normalize_url( get_site_url() ) ) ],
			$this->get_delete_filters()
		);
		$this->assertSame( [ ( new Post_Record() )->get_site_post_id( $page_id ) ], $this->get_saved_site_post_ids() );
	}

	/**
	 * Reindexing reports batches that failed to save.
	 */
	public function test_reindex_returns_error_when_a_batch_fails(): void {
		$this->set_up_governing_site();
		self::factory()->post->create();

		$this->mock_algolia_http_client( $this->paths, null, '/batch' );

		$result = ( new Indexer() )->reindex();

		$this->assertWPError( $result );
		$this->assertSame( 'onesearch_algolia_save_records_failed', $result->get_error_code() );
	}

	/**
	 * Mocks Algolia to grant the API key the given permissions.
	 *
	 * @param string[] $acl The granted permissions.
	 */
	private function mock_api_key_acl( array $acl ): void {
		$this->mock_algolia_http_client(
			$this->paths,
			static fn (): string => (string) wp_json_encode( [ 'acl' => $acl ] )
		);
	}

	/**
	 * Collects the `filters` of every deleteByQuery request.
	 *
	 * @return string[]
	 */
	private function get_delete_filters(): array {
		$filters = [];

		foreach ( $this->requests as $request ) {
			if ( str_contains( $request['path'], '/deleteByQuery' ) ) {
				$filters[] = (string) json_decode( $request['body'], true )['filters'];
			}
		}

		return $filters;
	}

	/**
	 * Collects the unique `site_post_id` of every saved record.
	 *
	 * @return string[]
	 */
	private function get_saved_site_post_ids(): array {
		$ids = [];

		foreach ( $this->requests as $request ) {
			if ( ! str_contains( $request['path'], '/batch' ) ) {
				continue;
			}

			foreach ( json_decode( $request['body'], true )['requests'] as $operation ) {
				$ids[] = (string) $operation['body']['site_post_id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Configures the current site as a governing site with credentials and indexable entities.
	 *
	 * @param string[] $entities The indexable post types.
	 */
	private function set_up_governing_site( array $entities = [ 'post' ] ): void {
		// Name the index after a known host, since the test site's URL depends on the environment.
		add_filter( 'option_siteurl', static fn (): string => 'https://governing.example.org', 11 );
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'test-app',
				'write_key' => 'test-key',
			]
		);
		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					Utils::normalize_url( get_site_url() ) => $entities,
				],
			]
		);
	}

	/**
	 * Configures the current site as a brand site with a cached brand config.
	 *
	 * @param string[] $entities The indexable post types.
	 */
	private function set_up_brand_site( array $entities = [ 'post' ] ): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

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
				'indexable_entities'  => $entities,
				'available_sites'     => [],
			]
		);
	}
}
