<?php
/**
 * Connection to an Algolia application.
 *
 * @package OneSearch\Algolia
 */

declare(strict_types = 1);

namespace OneSearch\Algolia;

use OneSearch\Vendor\Algolia\AlgoliaSearch\Api\SearchClient;

/**
 * Class - Connection
 */
final class Connection {
	/**
	 * The Algolia API client.
	 */
	private SearchClient $client;

	/**
	 * The API key the client authenticates with.
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $app_id  The Algolia Application ID.
	 * @param string $api_key The Algolia API key.
	 */
	public function __construct( string $app_id, string $api_key ) {
		$this->client  = SearchClient::create( $app_id, $api_key );
		$this->api_key = $api_key;
	}

	/**
	 * Gets an index by name.
	 *
	 * @param string $name The index name.
	 */
	public function get_index( string $name ): Index {
		return new Index( $this->client, $name );
	}

	/**
	 * Whether the API key grants all the given permissions.
	 *
	 * Returns false if the key can't be looked up, e.g. because the credentials are invalid.
	 *
	 * @see https://www.algolia.com/doc/guides/security/api-keys/#access-control-list-acl
	 *
	 * @param string[] $acl The required ACL permissions.
	 */
	public function has_permissions( array $acl ): bool {
		try {
			$key_info = $this->client->getApiKey( $this->api_key );
		} catch ( \Throwable $e ) {
			return false;
		}

		$granted = is_array( $key_info ) ? ( $key_info['acl'] ?? [] ) : [];

		return empty( array_diff( $acl, $granted ) );
	}
}
