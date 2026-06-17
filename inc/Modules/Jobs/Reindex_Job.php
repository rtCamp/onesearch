<?php
/**
 * Parent job that orchestrates a full re-index by chunking posts into Sync_Job children.
 *
 * @package OneSearch\Modules\Jobs
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Jobs;

use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Modules\Search\Index;
use OneSearch\Utils;

/**
 * Class - Reindex_Job
 *
 * Resolves all post IDs for the given post types, clears existing
 * Algolia records for the site, chunks the IDs into batches, and
 * schedules a Sync_Job for each batch. Progress is tracked as
 * children complete via Job_Scheduler::notify_parent().
 */
final class Reindex_Job extends Abstract_Job {
	/**
	 * The default group for reindex jobs.
	 *
	 * @var string
	 */
	protected string $group = 'reindex';

	/**
	 * The default batch size.
	 *
	 * @var int
	 */
	protected int $progress_total = 1;

	/**
	 * The default number of retries.
	 *
	 * @var int
	 */
	protected int $max_retries = 2;

	/**
	 * The default delay between retries in seconds.
	 *
	 * @var int
	 */
	protected int $retry_delay_seconds = 60;

	/**
	 * Get the job type name for registry lookups.
	 */
	public static function get_type(): string {
		return 'reindex';
	}

	/**
	 * Execute the reindex job.
	 *
	 * Streams posts page-by-page directly into Sync_Job children, keeping peak
	 * memory at O(batch_size) regardless of total post count. Each WP_Query
	 * page becomes exactly one child job — no full ID list is accumulated.
	 *
	 * @throws \InvalidArgumentException If post_types is missing or empty.
	 * @throws \RuntimeException         If scheduling a child fails.
	 */
	public function handle(): void {
		$post_types = $this->data['post_types'] ?? [];

		if ( empty( $post_types ) ) {
			throw new \InvalidArgumentException( 'Reindex_Job requires post_types in data payload.' );
		}

		$allowed_statuses = \OneSearch\Modules\Search\Post_Record::get_allowed_statuses( $post_types );

		// Clear existing records before reindexing.
		$indexer = new Index();
		$indexer->delete_by(
			[
				'filters' => sprintf( 'site_url:"%s"', Utils::normalize_url( get_site_url() ) ),
			]
		);

		$batch_size = $this->data['batch_size'] ?? 100;
		$batch_size = max( 1, min( $batch_size, 500 ) );
		$scheduler  = new Job_Scheduler();
		$group      = 'reindex_' . $this->get_id();
		$scheduled  = 0;
		$page       = 1;

		while ( true ) {
			$query = new \WP_Query(
				[
					'post_type'              => $post_types,
					'post_status'            => $allowed_statuses,
					'posts_per_page'         => $batch_size,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);

			$posts = $query->posts;
			if ( ! is_array( $posts ) || empty( $posts ) ) {
				break;
			}

			$post_ids = array_map( static fn ( $p ) => is_object( $p ) ? (int) $p->ID : (int) $p, $posts );

			$child = new Sync_Job();
			$child->set_data(
				[
					'post_ids'   => $post_ids,
					'post_types' => $post_types,
				]
			);
			$child->set_parent_id( $this->get_id() );
			$child->set_group( $group );
			$child->set_max_retries( 2 );
			$child->set_retry_delay_seconds( 30 );

			try {
				$scheduler->schedule( $child );
				$this->add_child_id( $child->get_id() );
				++$scheduled;
			} catch ( \Throwable $e ) {
				// If scheduling fails, cancel all already-scheduled children.
				foreach ( $this->get_child_ids() as $child_id ) {
					$scheduler->cancel( $child_id );
				}
				throw new \RuntimeException(
					sprintf( 'Failed to schedule child Sync_Job: %s', esc_html( $e->getMessage() ) )
				);
			}

			if ( count( $posts ) < $batch_size ) {
				break;
			}

			++$page;
		}

		if ( 0 === $scheduled ) {
			$this->mark_completed();
			return;
		}

		$this->set_progress_total( $scheduled );
		$this->update_progress( 0 );

		// Persist the parent job with child IDs so we can track completion.
		$scheduler->persist_job( $this );

		// REST-triggered reindexes do not run in wp-admin, so Action Scheduler's
		// normal shutdown-based async dispatch may not fire. Dispatch the async
		// runner directly when available, and fall back to cron nudging otherwise.
		if (
			class_exists( '\\ActionScheduler_AsyncRequest_QueueRunner' )
			&& class_exists( '\\ActionScheduler_Store' )
		) {
			$runner = new \ActionScheduler_AsyncRequest_QueueRunner( \ActionScheduler_Store::instance() );
			$runner->maybe_dispatch();
			return;
		}

		spawn_cron();
	}
}
