<?php
/**
 * Connection tests.
 *
 * @package OneSearch\Tests\Integration\Algolia
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Algolia;

use OneSearch\Algolia\Connection;
use OneSearch\Algolia\Index;
use OneSearch\Tests\TestCase;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia as AlgoliaSDK;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Http\CurlHttpClient;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Http\HttpClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Connection class.
 */
#[CoversClass( Connection::class )]
final class ConnectionTest extends TestCase {
	/**
	 * A new connection sets the SDK's cURL client, so the SDK never picks a transport (e.g. a global Guzzle) by itself.
	 */
	public function test_uses_the_curl_client(): void {
		// Stands in for a client the SDK picked by itself.
		AlgoliaSDK::setHttpClient( $this->createStub( HttpClientInterface::class ) );

		new Connection( 'test-app', 'test-key' );

		$this->assertInstanceOf( CurlHttpClient::class, AlgoliaSDK::getHttpClient() );
	}

	/**
	 * Returns an Index for the given name.
	 */
	public function test_get_index(): void {
		$this->assertInstanceOf( Index::class, ( new Connection( 'test-app', 'test-key' ) )->get_index( 'test_index' ) );
	}

	/**
	 * Checks the permissions of the connection's own API key.
	 */
	public function test_has_permissions(): void {
		$recorded_paths = [];
		$this->mock_algolia_http_client(
			$recorded_paths,
			static fn (): string => (string) wp_json_encode( [ 'acl' => [ 'search', 'addObject' ] ] )
		);

		$connection = new Connection( 'test-app', 'test-key' );

		$this->assertTrue( $connection->has_permissions( [ 'search', 'addObject' ] ) );
		$this->assertFalse( $connection->has_permissions( [ 'search', 'deleteIndex' ] ) );
		$this->assertSame( '/1/keys/test-key', $recorded_paths[0] );
	}

	/**
	 * A key that can't be looked up has no permissions.
	 */
	public function test_has_permissions_returns_false_when_lookup_fails(): void {
		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths, null, '/1/keys/' );

		$this->assertFalse( ( new Connection( 'test-app', 'test-key' ) )->has_permissions( [ 'search' ] ) );
	}
}
