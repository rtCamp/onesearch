<?php
/**
 * Parent job that orchestrates a full re-index by chunking posts into Sync_Job children.
 *
 * @package OneSearch\Modules\Jobs
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Jobs;

use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Modules\Search\Indexer;

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
	 * The number of posts per batch, unless the job's data sets its own.
	 */
	public const DEFAULT_BATCH_SIZE = 100;

	/**
	 * The most posts a batch may hold.
	 */
	private const MAX_BATCH_SIZE = 500;

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
	 * @throws \InvalidArgumentException If post_types is missing.
	 * @throws \RuntimeException         If clearing the site's records or scheduling a child fails.
	 */
	public function handle(): void {
		$post_types = $this->data['post_types'] ?? null;

		if ( ! is_array( $post_types ) ) {
			throw new \InvalidArgumentException( 'Reindex_Job requires post_types in data payload.' );
		}

		// Every run rebuilds all batches; cancelled ones left by a failed earlier attempt never report back and would stall completion.
		$this->set_child_ids( [] );

		// Clearing up front means a failed reindex leaves the site unsearchable until the next successful run.
		$is_cleared = ( new Indexer() )->clear_site_records();
		if ( is_wp_error( $is_cleared ) ) {
			throw new \RuntimeException(
				sprintf( 'Failed to clear the site\'s Algolia records: %s', esc_html( self::describe_error( $is_cleared ) ) )
			);
		}

		// A site with nothing to index is done once its old records are gone.
		if ( empty( $post_types ) ) {
			$this->mark_completed();
			return;
		}

		$batch_size = self::get_batch_size( $this->data['batch_size'] ?? self::DEFAULT_BATCH_SIZE );
		$scheduler  = new Job_Scheduler();
		$group      = 'reindex_' . $this->get_id();
		$scheduled  = 0;
		$page       = 1;

		while ( true ) {
			$query = new \WP_Query( self::get_batch_query_args( $post_types, $batch_size, $page ) );

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

		Job_Scheduler::dispatch_queue();
	}

	/**
	 * Estimate how many batches a reindex of the given post types will create.
	 *
	 * Counted with the same query the batches are paged with, so it matches unless posts change in between.
	 *
	 * @param string[] $post_types The post types to index.
	 * @param int      $batch_size Posts per batch.
	 */
	public static function count_batches( array $post_types, int $batch_size = self::DEFAULT_BATCH_SIZE ): int {
		if ( empty( $post_types ) ) {
			return 0;
		}

		$batch_size = self::get_batch_size( $batch_size );
		$query      = new \WP_Query(
			array_merge(
				self::get_batch_query_args( $post_types, 1, 1 ),
				[ 'no_found_rows' => false ]
			)
		);

		return (int) ceil( $query->found_posts / $batch_size );
	}

	/**
	 * Clamp a batch size to the allowed range.
	 *
	 * @param mixed $batch_size The requested batch size.
	 */
	private static function get_batch_size( $batch_size ): int {
		return max( 1, min( (int) $batch_size, self::MAX_BATCH_SIZE ) );
	}

	/**
	 * Get the query for one page of the posts to index.
	 *
	 * @param string[] $post_types The post types to index.
	 * @param int      $batch_size Posts per page.
	 * @param int      $page       The page number.
	 * @return array<string, mixed>
	 */
	private static function get_batch_query_args( array $post_types, int $batch_size, int $page ): array {
		return [
			'post_type'              => $post_types,
			'post_status'            => \OneSearch\Modules\Search\Post_Record::get_allowed_statuses( $post_types ),
			'posts_per_page'         => $batch_size,
			'paged'                  => $page,
			// Paging needs a unique order; posts sharing a date could otherwise repeat across batches or be skipped.
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];
	}
}
