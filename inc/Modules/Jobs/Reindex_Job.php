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
use OneSearch\Utils;

/**
 * Class - Reindex_Job
 *
 * Clears the site's Algolia records, then pages through its posts and queues a Sync_Job per batch. It finishes once
 * every batch has reported back through Job_Scheduler::notify_parent().
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
	 * Placeholder until handle() knows how many batches there are.
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
	 * Each page of posts becomes one Sync_Job child. All of them are stored, then the parent with every child recorded,
	 * before any is queued: a child that ran sooner would report back to a parent that can't count it.
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
		$page       = 1;

		try {
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

				$scheduler->persist_job( $child );
				$this->add_child_id( $child->get_id() );

				if ( count( $posts ) < $batch_size ) {
					break;
				}

				++$page;
			}

			$batch_count = count( $this->get_child_ids() );
			if ( 0 === $batch_count ) {
				$this->mark_completed();
				return;
			}

			$this->set_progress_total( $batch_count );
			$this->update_progress( 0 );
			$this->record_batch_count( $batch_count );

			// The last write of this copy: once the batches are queued, they update the stored parent instead.
			$scheduler->persist_job( $this );

			foreach ( $this->get_child_ids() as $child_id ) {
				$scheduler->enqueue( Sync_Job::class, $child_id, $group );
			}
		} catch ( \Throwable $e ) {
			foreach ( $this->get_child_ids() as $child_id ) {
				$scheduler->cancel( $child_id );
			}
			throw new \RuntimeException(
				sprintf( 'Failed to schedule child Sync_Job: %s', esc_html( $e->getMessage() ) )
			);
		}

		Job_Scheduler::dispatch_queue();
	}

	/**
	 * Record this site's batch count among the per-site totals a governing site keeps for the run.
	 *
	 * Done before the batches are queued, since the parent isn't written from here once they are.
	 *
	 * @param int $batch_count The batches this site queued.
	 */
	private function record_batch_count( int $batch_count ): void {
		if ( empty( $this->data['sites'] ) || ! is_array( $this->data['sites'] ) ) {
			return;
		}

		$current_site = Utils::normalize_url( get_site_url() );
		foreach ( $this->data['sites'] as $i => $site ) {
			if ( Utils::normalize_url( $site['site_url'] ?? '' ) === $current_site ) {
				$this->data['sites'][ $i ]['batch_count'] = $batch_count;
			}
		}

		$this->data['total_batches'] = (int) array_sum( array_column( $this->data['sites'], 'batch_count' ) );
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
