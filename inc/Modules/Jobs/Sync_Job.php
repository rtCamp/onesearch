<?php
/**
 * Leaf job that syncs a batch of post IDs to Algolia.
 *
 * @package OneSearch\Modules\Jobs
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Jobs;

use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Post_Record;
use function esc_html;
use function get_post;
use function is_wp_error;

/**
 * Class - Sync_Job
 *
 * Processes a batch of post IDs: saves the indexable posts' records via
 * Indexer::save_posts(). For posts that are not indexable (e.g. trashed/draft),
 * it deletes the corresponding records via Indexer::delete_posts() instead.
 */
final class Sync_Job extends Abstract_Job {
	/**
	 * The default Action Scheduler group; the Watcher and Reindex_Job each set their own.
	 *
	 * @var string
	 */
	protected string $group = 'sync';

	/**
	 * Placeholder until handle() counts the batch's posts.
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
	 * Indexable posts are saved in one write and the others' records deleted in one request; posts that no longer
	 * exist are skipped.
	 *
	 * @throws \InvalidArgumentException If post_ids is missing or empty.
	 * @throws \RuntimeException         If any Algolia API operation fails.
	 */
	public function handle(): void {
		$post_ids = $this->data['post_ids'] ?? [];

		if ( empty( $post_ids ) ) {
			throw new \InvalidArgumentException( 'Sync_Job requires post_ids in data payload.' );
		}

		$this->progress_total = count( $post_ids );
		$this->update_progress( 0 );

		$errors     = [];
		$save_posts = [];
		$delete_ids = [];

		foreach ( $post_ids as $i => $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				$this->update_progress( $i + 1 );
				continue;
			}

			$post_types   = $this->data['post_types'] ?? [ $post->post_type ];
			$should_index = in_array( $post->post_status, Post_Record::get_allowed_statuses( $post_types ), true );

			if ( ! $should_index ) {
				$delete_ids[] = (int) $post_id;
			} else {
				$save_posts[] = $post;
			}

			$this->update_progress( $i + 1 );
		}

		$indexer = new Indexer();

		if ( ! empty( $delete_ids ) ) {
			$result = $indexer->delete_posts( $delete_ids );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					'Failed to delete %d posts from Algolia: %s',
					count( $delete_ids ),
					esc_html( self::describe_error( $result ) )
				);
			}
		}

		if ( ! empty( $save_posts ) ) {
			$result = $indexer->save_posts( $save_posts );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					'Failed to save %d posts to Algolia: %s',
					count( $save_posts ),
					esc_html( self::describe_error( $result ) )
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
