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
	 */
	public function handle(): void {
		$post_ids = $this->data['post_ids'] ?? [];

		if ( empty( $post_ids ) ) {
			throw new \InvalidArgumentException( 'SyncJob requires post_ids in data payload.' );
		}

		$this->progress_total = count( $post_ids );
		$this->update_progress( 0 );

		$index       = new Index();
		$post_record = new Post_Record();

		foreach ( $post_ids as $i => $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				$this->update_progress( $i + 1 );
				continue;
			}

			$post_types   = $this->data['post_types'] ?? [ $post->post_type ];
			$should_index = in_array( $post->post_status, Post_Record::get_allowed_statuses( $post_types ), true );

			if ( ! $should_index ) {
				$site_post_id = sprintf( '%s_%d', Utils::normalize_url( get_site_url() ), $post_id );
				$index->delete_by(
					[
						'filters' => sprintf( 'site_post_id:"%s"', $site_post_id ),
					]
				);
			} else {
				$records = $post_record->to_records( $post );

				if ( ! empty( $records ) ) {
					$index->save_records( $records );
				}
			}

			$this->update_progress( $i + 1 );
		}

		$this->mark_completed();
	}
}
