<?php
/**
 * Leaf job that syncs a batch of post IDs to Algolia.
 *
 * @package OneSearch\Modules\Jobs
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Jobs;

use OneSearch\Modules\Search\Index;
use OneSearch\Modules\Search\Post_Record;
use OneSearch\Utils;
use function esc_html;
use function get_post;
use function get_site_url;
use function is_wp_error;

/**
 * Class - SyncJob
 *
 * Processes a batch of post IDs: transforms each post into Algolia
 * records via Post_Record::to_records() and saves them via Index::save_records().
 * For posts that are not indexable (e.g. trashed/draft), it deletes
 * the corresponding records from Algolia instead.
 */
final class SyncJob extends AbstractJob {
	/**
	 * The default group for watcher-spawned sync jobs.
	 *
	 * @var string
	 */
	protected string $group = 'sync';

	/**
	 * The default batch size (used as progress_total for single-post syncs).
	 *
	 * @var int
	 */
	protected int $progress_total = 1;

	/**
	 * The default number of retries.
	 *
	 * @var int
	 */
	protected int $max_retries = 3;

	/**
	 * The default delay between retries in seconds.
	 *
	 * @var int
	 */
	protected int $retry_delay_seconds = 30;

	/**
	 * Get the job type name for registry lookups.
	 */
	public static function get_type(): string {
		return 'sync';
	}

	/**
	 * Execute the sync job.
	 *
	 * Iterates over post_ids from the data payload, transforms each
	 * post into Algolia records, and saves or deletes them as appropriate.
	 *
	 * @throws \InvalidArgumentException If post_ids is missing or empty.
	 * @throws \RuntimeException         If any Algolia API operation fails.
	 */
	public function handle(): void {
		$post_ids = $this->data['post_ids'] ?? [];

		if ( empty( $post_ids ) ) {
			throw new \InvalidArgumentException( 'SyncJob requires post_ids in data payload.' );
		}

		$this->progress_total = count( $post_ids );
		$this->update_progress( 0 );

		$index          = new Index();
		$post_record    = new Post_Record();
		$errors         = [];
		$batch_records  = [];
		$delete_filters = [];
		$site_url       = Utils::normalize_url( get_site_url() );

		foreach ( $post_ids as $i => $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				$this->update_progress( $i + 1 );
				continue;
			}

			$post_types   = $this->data['post_types'] ?? [ $post->post_type ];
			$should_index = in_array( $post->post_status, Post_Record::get_allowed_statuses( $post_types ), true );

			if ( ! $should_index ) {
				$delete_filters[] = sprintf( 'site_post_id:"%s_%d"', $site_url, $post_id );
			} else {
				$records = $post_record->to_records( $post );

				if ( ! empty( $records ) ) {
					foreach ( $records as $record ) {
						$batch_records[] = $record;
					}
				}
			}

			$this->update_progress( $i + 1 );
		}

		// Batch all deletions into a single Algolia call.
		if ( ! empty( $delete_filters ) ) {
			$result = $index->delete_by(
				[
					'filters' => implode( ' OR ', $delete_filters ),
				]
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					'Failed to delete %d posts from Algolia: %s',
					count( $delete_filters ),
					esc_html( $result->get_error_message() )
				);
			}
		}

		if ( ! empty( $batch_records ) ) {
			$result = $index->save_records( $batch_records );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					'Failed to save %d Algolia records for batch: %s',
					count( $batch_records ),
					esc_html( $result->get_error_message() )
				);
			}
		}

		if ( ! empty( $errors ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( implode( '; ', $errors ) );
		}

		$this->mark_completed();
	}
}
