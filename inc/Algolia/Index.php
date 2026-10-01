<?php
/**
 * An Algolia index.
 *
 * @package OneSearch\Algolia
 */

declare(strict_types = 1);

namespace OneSearch\Algolia;

use OneSearch\Vendor\Algolia\AlgoliaSearch\Api\SearchClient;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Support\Helpers;

/**
 * Class - Index
 *
 * Write operations wait until Algolia has applied them before returning.
 */
final class Index {
	/**
	 * The maximum number of times to poll a task before giving up, matching the client's default.
	 */
	private const WAIT_MAX_RETRIES = 100;

	/**
	 * The maximum delay between polls of a task, in milliseconds, matching the client's default.
	 */
	private const WAIT_MAX_DELAY_MS = 5000;

	/**
	 * The Algolia API client.
	 */
	private SearchClient $client;

	/**
	 * The index name.
	 */
	private string $name;

	/**
	 * Constructor.
	 *
	 * @param \OneSearch\Vendor\Algolia\AlgoliaSearch\Api\SearchClient $client The Algolia API client.
	 * @param string                                                   $name   The index name.
	 */
	public function __construct( SearchClient $client, string $name ) {
		$this->client = $client;
		$this->name   = $name;
	}

	/**
	 * Searches the index.
	 *
	 * @see https://www.algolia.com/doc/rest-api/search/search-single-index
	 *
	 * @param string              $query  The search query.
	 * @param array<string,mixed> $params The search params.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function search( string $query, array $params = [] ): array|\WP_Error {
		try {
			return (array) $this->client->searchSingleIndex( $this->name, array_merge( $params, [ 'query' => $query ] ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_algolia_search_failed',
				__( 'Failed to search Algolia index.', 'onesearch' ),
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Adds or replaces records.
	 *
	 * @param array<array<string,mixed>> $records The records. Each must have an `objectID`.
	 *
	 * @return true|\WP_Error
	 */
	public function save_records( array $records ): bool|\WP_Error {
		try {
			// Wait ourselves, since the client's own wait is broken.
			foreach ( $this->client->saveObjects( $this->name, $records ) as $response ) {
				$this->wait_for_task( $response );
			}

			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_algolia_save_records_failed',
				__( 'Failed to save records to Algolia index.', 'onesearch' ),
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Deletes the records matching the given params.
	 *
	 * @see https://www.algolia.com/doc/rest-api/search/delete-by
	 *
	 * @param array<string,mixed> $params The delete by params, e.g. `filters`.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_by( array $params ): bool|\WP_Error {
		try {
			$this->wait_for_task( $this->client->deleteBy( $this->name, $params ) );
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_algolia_delete_by_failed',
				__( 'Failed to delete Algolia records by given args.', 'onesearch' ),
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Gets the index settings.
	 *
	 * @see https://www.algolia.com/doc/rest-api/search/get-settings
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_settings(): array|\WP_Error {
		try {
			return (array) $this->client->getSettings( $this->name );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_algolia_get_settings_failed',
				__( 'Failed to get Algolia index settings.', 'onesearch' ),
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Updates the index settings.
	 *
	 * @see https://www.algolia.com/doc/rest-api/search/set-settings
	 *
	 * @param array<string,mixed> $settings The index settings.
	 *
	 * @return true|\WP_Error
	 */
	public function set_settings( array $settings ): bool|\WP_Error {
		try {
			$this->wait_for_task( $this->client->setSettings( $this->name, $settings ) );
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_algolia_set_settings_failed',
				__( 'Failed to set Algolia index settings.', 'onesearch' ),
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Deletes the index, along with its records and settings.
	 *
	 * Deleting an index that doesn't exist succeeds.
	 *
	 * @return true|\WP_Error
	 */
	public function delete(): bool|\WP_Error {
		try {
			$this->wait_for_task( $this->client->deleteIndex( $this->name ) );
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_algolia_delete_index_failed',
				__( 'Failed to delete Algolia index.', 'onesearch' ),
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Waits until Algolia has applied the task from a write response.
	 *
	 * Replaces `SearchClient::waitForTask()`, since strauss doesn't correctly namespace the backoff callback.
	 *
	 * @param mixed $response The write response.
	 *
	 * @throws \UnexpectedValueException If the response has no task ID.
	 * @throws \RuntimeException If polling the task fails.
	 */
	private function wait_for_task( $response ): void {
		$task_id = is_array( $response ) ? ( $response['taskID'] ?? null ) : null;

		if ( ! is_int( $task_id ) ) {
			throw new \UnexpectedValueException( 'Algolia response is missing a task ID.' );
		}

		$task = Helpers::retryUntil(
			$this->client,
			'getTask',
			[ $this->name, $task_id ],
			static fn ( $task ): bool => 'published' === ( $task['status'] ?? null ),
			self::WAIT_MAX_RETRIES,
			self::WAIT_MAX_DELAY_MS,
			Helpers::class . '::linearTimeout'
		);

		// retryUntil() swallows getTask() exceptions and returns null.
		if ( null === $task ) {
			throw new \RuntimeException( sprintf( 'Failed to confirm Algolia task %d was applied.', $task_id ) );
		}
	}
}
