<?php
/**
 * Index tests.
 *
 * @package OneSearch\Tests\Integration\Algolia
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Algolia;

use OneSearch\Algolia\Connection;
use OneSearch\Algolia\Index;
use OneSearch\Tests\TestCase;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia as AlgoliaSDK;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the Index class.
 */
#[CoversClass( Index::class )]
final class IndexTest extends TestCase {
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
	 * Sends the query along with the search params.
	 */
	public function test_search(): void {
		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$results = $this->get_index()->search( 'hello', [ 'hitsPerPage' => 5 ] );

		$this->assertIsArray( $results );
		$this->assertSame( [ [ 'objectID' => '1' ] ], $results['hits'] );
		$this->assertSame( '/1/indexes/test_index/query', $this->requests[0]['path'] );
		$this->assertSame(
			[
				'hitsPerPage' => 5,
				'query'       => 'hello',
			],
			json_decode( $this->requests[0]['body'], true )
		);
	}

	/**
	 * Saves the records, and waits for Algolia to apply them.
	 */
	public function test_save_records(): void {
		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$this->assertTrue( $this->get_index()->save_records( [ [ 'objectID' => 'a' ] ] ) );
		$this->assertSame( [ '/1/indexes/test_index/batch', '/1/indexes/test_index/task/1' ], $this->paths );
		$this->assertSame(
			[
				[
					'action' => 'addObject',
					'body'   => [ 'objectID' => 'a' ],
				],
			],
			json_decode( $this->requests[0]['body'], true )['requests']
		);
	}

	/**
	 * Deletes by the given params, and waits for Algolia to apply it.
	 */
	public function test_delete_by(): void {
		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$this->assertTrue( $this->get_index()->delete_by( [ 'filters' => 'site_url:"https://example.com/"' ] ) );
		$this->assertSame( [ '/1/indexes/test_index/deleteByQuery', '/1/indexes/test_index/task/1' ], $this->paths );
		$this->assertSame( [ 'filters' => 'site_url:"https://example.com/"' ], json_decode( $this->requests[0]['body'], true ) );
	}

	/**
	 * Sets the settings, and waits for Algolia to apply them.
	 */
	public function test_set_settings(): void {
		$this->mock_algolia_http_client( $this->paths, null, null, $this->requests );

		$this->assertTrue( $this->get_index()->set_settings( [ 'distinct' => true ] ) );
		$this->assertSame( [ '/1/indexes/test_index/settings', '/1/indexes/test_index/task/1' ], $this->paths );
		$this->assertSame( [ 'distinct' => true ], json_decode( $this->requests[0]['body'], true ) );
	}

	/**
	 * Deletes the index, and waits for Algolia to apply it.
	 */
	public function test_delete(): void {
		$this->mock_algolia_http_client( $this->paths );

		$this->assertTrue( $this->get_index()->delete() );
		$this->assertSame( [ '/1/indexes/test_index', '/1/indexes/test_index/task/1' ], $this->paths );
	}

	/**
	 * Failed requests are returned as errors.
	 *
	 * @param callable(\OneSearch\Algolia\Index): mixed $operation  The operation to run.
	 * @param string                                    $error_code The expected error code.
	 */
	#[DataProvider( 'provide_failing_operations' )]
	public function test_returns_error_when_request_fails( callable $operation, string $error_code ): void {
		$this->mock_algolia_http_client( $this->paths, null, '/1/indexes/test_index' );

		$result = $operation( $this->get_index() );

		$this->assertWPError( $result );
		$this->assertSame( $error_code, $result->get_error_code() );
	}

	/**
	 * Provides each operation along with the error code it fails with.
	 *
	 * @return array<string, array{callable(\OneSearch\Algolia\Index): mixed, string}>
	 */
	public static function provide_failing_operations(): array {
		return [
			'search'       => [ static fn ( Index $index ) => $index->search( 'hello' ), 'onesearch_algolia_search_failed' ],
			'save_records' => [ static fn ( Index $index ) => $index->save_records( [ [ 'objectID' => 'a' ] ] ), 'onesearch_algolia_save_records_failed' ],
			'delete_by'    => [ static fn ( Index $index ) => $index->delete_by( [ 'filters' => 'a:"b"' ] ), 'onesearch_algolia_delete_by_failed' ],
			'set_settings' => [ static fn ( Index $index ) => $index->set_settings( [] ), 'onesearch_algolia_set_settings_failed' ],
			'delete'       => [ static fn ( Index $index ) => $index->delete(), 'onesearch_algolia_delete_index_failed' ],
		];
	}

	/**
	 * Gets the index under test.
	 */
	private function get_index(): Index {
		return ( new Connection( 'test-app', 'test-key' ) )->get_index( 'test_index' );
	}
}
