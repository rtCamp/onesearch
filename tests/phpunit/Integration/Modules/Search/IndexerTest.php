<?php
/**
 * Indexer tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Post_Record;
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

		$result = ( new Indexer() )->delete_posts( [ 1 ] );

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
		$indexer->save_posts( [ $post ] );
		$indexer->delete_posts( [ $post->ID ] );

		$this->assertSame(
			[
				// Read, and then pushed, since the mock's settings don't match.
				self::INDEX_PATH . '/settings',
				self::INDEX_PATH . '/settings',
				self::INDEX_PATH . '/batch',
				// Chunks beyond the new count.
				self::INDEX_PATH . '/deleteByQuery',
				self::INDEX_PATH . '/deleteByQuery',
			],
			$this->paths,
			'Writes should not poll tasks or read the saved chunks back.'
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

		( new Indexer() )->save_posts( [ self::factory()->post->create_and_get() ] );

		$this->assertSame(
			[ self::INDEX_PATH . '/settings', self::INDEX_PATH . '/batch', self::INDEX_PATH . '/deleteByQuery' ],
			$this->paths
		);
	}

	/**
	 * Chunks left over from when a post was longer are deleted after it's saved.
	 */
	public function test_save_posts_deletes_stale_chunks(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();

		$posts = self::factory()->post->create_many( 2, [ 'post_content' => 'Short.' ] );

		foreach ( $posts as $post_id ) {
			$this->algolia_records[ 'old_chunk_' . $post_id ] = [
				'objectID'     => 'old_chunk_' . $post_id,
				'post_id'      => $post_id,
				'site_post_id' => ( new Post_Record() )->get_site_post_id( $post_id ),
				'chunk_index'  => 1,
			];
		}

		$this->assertSame( [], ( new Indexer() )->save_posts( array_map( 'get_post', $posts ) ) );
		$this->assertSame( [ 0, 0 ], array_column( $this->algolia_records, 'chunk_index' ) );
	}

	/**
	 * Posts' records are deleted by the `site_post_id` they were saved with.
	 */
	public function test_delete_posts(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$post_record = new Post_Record();

		$this->assertTrue( ( new Indexer() )->delete_posts( [ 42, 43 ] ) );
		$this->assertSame(
			[ sprintf( 'site_post_id:"%s" OR site_post_id:"%s"', $post_record->get_site_post_id( 42 ), $post_record->get_site_post_id( 43 ) ) ],
			$this->get_delete_filters()
		);
	}

	/**
	 * Deleting no posts sends nothing to Algolia.
	 */
	public function test_delete_posts_without_posts(): void {
		$this->set_up_governing_site();
		$this->mock_algolia_http_client( $this->paths );

		$this->assertTrue( ( new Indexer() )->delete_posts( [] ) );
		$this->assertSame( [], $this->paths );
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
		$indexer->delete_posts( [ 42 ] );
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
	 * Saving posts reports the posts too big to index without saving them, and leaves their existing records alone.
	 */
	public function test_save_posts_reports_the_posts_too_big_to_index(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();

		$post    = self::factory()->post->create_and_get();
		$too_big = self::factory()->post->create_and_get();
		$this->make_too_big_to_index( $too_big->ID );
		$this->algolia_records['old_record'] = [
			'objectID'     => 'old_record',
			'post_id'      => $too_big->ID,
			'site_post_id' => ( new Post_Record() )->get_site_post_id( $too_big->ID ),
			'chunk_index'  => 1,
		];

		$errors = ( new Indexer() )->save_posts( [ $post, $too_big ] );

		$this->assertIsArray( $errors );
		$this->assertSame( [ $too_big->ID ], array_keys( $errors ) );
		$this->assertSame( 'onesearch_post_too_big', $errors[ $too_big->ID ]->get_error_code() );
		$this->assertSame( [ $post->ID, $too_big->ID ], $this->get_algolia_post_ids() );
		$this->assertArrayHasKey( 'old_record', $this->algolia_records );
	}

	/**
	 * A failed request fails the whole save.
	 */
	public function test_save_posts_returns_the_error_of_a_failed_request(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index( '/batch' );

		$result = ( new Indexer() )->save_posts( [ self::factory()->post->create_and_get() ] );

		$this->assertWPError( $result );
		$this->assertSame( 'Failed to save records to Algolia index. forced test exception', Indexer::get_error_message( $result ) );
	}

	/**
	 * Only this site's records saved before the given time are deleted.
	 */
	public function test_delete_stale_site_records(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();

		$this->add_algolia_record( 'stale', Utils::get_current_site_url() );
		$this->add_algolia_record( 'other_site', 'https://other.example.com/' );
		$this->algolia_records['fresh'] = [
			'objectID'   => 'fresh',
			'site_url'   => Utils::get_current_site_url(),
			'indexed_at' => time(),
		];

		$this->assertTrue( ( new Indexer() )->delete_stale_site_records( time() - MINUTE_IN_SECONDS ) );
		$this->assertSame( [ 'other_site', 'fresh' ], array_keys( $this->algolia_records ) );
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
}
