<?php
/**
 * Search Index for Algolia
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - Index
 *
 * @phpstan-import-type PostRecord from \OneSearch\Modules\Search\Post_Record
 */
final class Index {
	/**
	 * The default batch size for indexing.
	 */
	private const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Flag to check whether settings were already set on this instance.
	 *
	 * @var bool
	 */
	private bool $index_settings_initialized = false;

	/**
	 * The instance of the AlgoliaClient SearchIndex
	 *
	 * @var \OneSearch\Vendor\Algolia\AlgoliaSearch\SearchIndex|null
	 */
	private ?\OneSearch\Vendor\Algolia\AlgoliaSearch\SearchIndex $index = null;

	/**
	 * Get the index, instantiating it if it doesn't exist.
	 */
	public function get_index(): \OneSearch\Vendor\Algolia\AlgoliaSearch\SearchIndex|\WP_Error {
		if ( ! $this->index instanceof \OneSearch\Vendor\Algolia\AlgoliaSearch\SearchIndex ) {
			$client = new Algolia();
			$index  = $client->get_index();
			if ( is_wp_error( $index ) ) {
				return $index;
			}
			$this->index = $index;
		}

		return $this->index;
	}

	/**
	 * Delete the index.
	 *
	 * If the governing site is deleted, delete the entire index.
	 * Otherwise, only delete the records that match our site_url.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_index(): bool|\WP_Error {
		$index = $this->get_index();

		if ( is_wp_error( $index ) ) {
			return $index;
		}

		try {
			if ( Settings::SITE_TYPE_GOVERNING === Settings::get_site_type() ) {
				$index->getSettings();
				$index->delete()->wait();
				return true;
			}

			// For non-governing sites, only delete this site's records.
			$index->deleteBy(
				[
					'filters' => sprintf( 'site_url:"%s"', Utils::normalize_url( get_site_url() ) ),
				]
			)->wait();

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
	 * Delete index by supported algolia args.
	 *
	 * @see https://www.algolia.com/doc/rest-api/search/delete-by
	 *
	 * @param array<string, mixed> $args The delete by args.
	 */
	public function delete_by( array $args ): bool|\WP_Error {
		$index = $this->get_index();

		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$settings_success = $this->set_settings();
		if ( is_wp_error( $settings_success ) ) {
			return $settings_success;
		}

		try {
			$index->deleteBy( $args )->wait();
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
	 * Save records to the index.
	 *
	 * @param PostRecord[] $records The records to save.
	 */
	public function save_records( array $records ): bool|\WP_Error {
		$index = $this->get_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$settings_success = $this->set_settings();
		if ( is_wp_error( $settings_success ) ) {
			return $settings_success;
		}

		try {
			$index->saveObjects( $records )->wait();
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
	 * Searches the index.
	 *
	 * @param string              $s The search query.
	 * @param array<string,mixed> $args The search args.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function search( string $s, array $args = [] ): array|\WP_Error {
		$index = $this->get_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$settings_success = $this->set_settings();
		if ( is_wp_error( $settings_success ) ) {
			return $settings_success;
		}

		try {
			$results = $index->search( $s, $args );
			return (array) $results;
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'onesearch_algolia_search_failed',
				__( 'Failed to search Algolia index.', 'onesearch' ),
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Index the post types objects into Algolia.
	 *
	 * @deprecated Use ReindexJob for async batch processing instead.
	 *
	 * @param string[] $post_types The post types to index.
	 *
	 * @return true|\WP_Error
	 */
	public function index_all_posts( array $post_types ) {
		// Clear existing records for this site.
		$site_url   = Utils::normalize_url( get_site_url() );
		$is_deleted = $this->delete_by(
			[
				'filters' => sprintf( 'site_url:"%s"', $site_url ),
			]
		);

		if ( is_wp_error( $is_deleted ) ) {
			return $is_deleted;
		}

		// Bail if there's no post types to index.
		if ( empty( $post_types ) ) {
			return true;
		}

		// @todo make this filterable.
		$batch_size = self::DEFAULT_BATCH_SIZE;

		foreach ( $this->generate_post_batches( $post_types, $batch_size ) as $records ) {
			// If there's no records in this batch, we're done.
			if ( empty( $records ) ) {
				break;
			}

			$is_saved = $this->save_records( $records );
			if ( is_wp_error( $is_saved ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- @todo Surface this better with a Logger class.
				error_log( 'Algolia indexing error: ' . $is_saved->get_error_message() );
				continue;
			}

			// Free memory after processing batch.
			unset( $records );
		}

		return true;
	}

	/**
	 * Ensure Algolia Index settings have been updated to reflect the latest.
	 *
	 * @return true|\WP_Error
	 */
	private function set_settings(): bool|\WP_Error {
		// Only initialize once per instance.
		if ( $this->index_settings_initialized ) {
			return true;
		}

		$index = $this->get_index();

		if ( is_wp_error( $index ) ) {
			return $index;
		}

		try {
			$index->setSettings( Post_Record::get_index_settings() )->wait();

			$this->index_settings_initialized = true;
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'algolia_set_settings_failed',
				__( 'Failed to set Algolia index settings.', 'onesearch' ),
				[ 'message' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Generator that yields batches of Algolia records for indexing.
	 *
	 * We `yield` to avoid loading all posts into memory at once.
	 *
	 * @param string[] $post_types      The post types to index.
	 * @param int      $batch_size      The number of posts to process per batch.
	 *
	 * @return \Generator<list<PostRecord>>
	 */
	private function generate_post_batches( array $post_types, int $batch_size ): \Generator {
		$page = 1;

		while ( true ) {
			$posts = Post_Record::get_indexable_posts( $post_types, $page, $batch_size );

			if ( empty( $posts ) ) {
				break;
			}

			$records = [];
			foreach ( $posts as $post ) {
				$post_records = ( new Post_Record() )->to_records( $post );
				$records      = array_merge( $records, $post_records );
			}
			yield $records;

			++$page;
		}
	}
}
