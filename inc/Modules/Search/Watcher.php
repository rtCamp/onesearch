<?php
/**
 * Watches for object changes to reindex in Algolia.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Modules\Jobs\Sync_Job;
use OneSearch\Modules\Scheduler\Job_Scheduler;

/**
 * Class - Watcher
 */
final class Watcher implements Registrable {
	/**
	 * The indexer, once instantiated.
	 */
	private ?Indexer $indexer = null;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'transition_post_status', [ $this, 'on_post_transition' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 10, 2 );
	}

	/**
	 * Triggered when a post's status changes (e.g., publish, update, trash, etc.)
	 *
	 * Schedules an async Sync_Job to update the post in Algolia instead of
	 * performing the sync inline, keeping the request fast.
	 *
	 * @internal Hook callback
	 *
	 * @param string   $new_status The new post status.
	 * @param string   $old_status The previous post status.
	 * @param \WP_Post $post       The post object.
	 */
	public function on_post_transition( $new_status, $old_status, $post ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		if ( ! $post instanceof \WP_Post || ! $this->is_post_type_indexable( (string) $post->post_type ) ) {
			return;
		}

		$job = new Sync_Job();
		$job->set_data(
			[
				'post_ids' => [ (int) $post->ID ],
			]
		);
		$job->set_group( 'watcher' );
		$job->set_max_retries( 2 );
		$job->set_retry_delay_seconds( 30 );

		$scheduler = new Job_Scheduler();

		try {
			$scheduler->schedule( $job );

			// AS never starts its runner after a REST request, such as a block editor save; once per request is enough.
			add_action( 'shutdown', [ Job_Scheduler::class, 'dispatch_queue' ] );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[OneSearch] Failed to schedule Sync_Job: %s', $e->getMessage() ) );

			// The edit still has to reach the index, so it's synced in this request instead.
			$this->sync_post_inline( $post );
		}
	}

	/**
	 * Synchronously sync a post to Algolia (fallback when async scheduling fails).
	 *
	 * @param \WP_Post $post The post to sync.
	 */
	private function sync_post_inline( \WP_Post $post ): void {
		if ( ! in_array( $post->post_status, Post_Record::get_allowed_statuses( [ $post->post_type ] ), true ) ) {
			$this->log_error( (int) $post->ID, $this->get_indexer()->delete_post( (int) $post->ID ) );
			return;
		}

		$this->log_error( (int) $post->ID, $this->get_indexer()->save_post( $post ) );
	}

	/**
	 * Removes a post's records once it has been permanently deleted.
	 *
	 * @internal Hook callback
	 *
	 * @param int       $post_id The ID of the deleted post.
	 * @param ?\WP_Post $post    The post that was deleted.
	 */
	public function on_deleted_post( $post_id, $post = null ): void {
		// The post is gone, so its type can only come from the passed object.
		if ( ! $post instanceof \WP_Post || ! $this->is_post_type_indexable( (string) $post->post_type ) ) {
			return;
		}

		$this->log_error( (int) $post_id, $this->get_indexer()->delete_post( (int) $post_id ) );
	}

	/**
	 * Gets the indexer, instantiating it if needed.
	 */
	private function get_indexer(): Indexer {
		if ( ! $this->indexer instanceof Indexer ) {
			$this->indexer = new Indexer();
		}

		return $this->indexer;
	}

	/**
	 * Logs a failed index update.
	 *
	 * @param int            $post_id The post ID.
	 * @param true|\WP_Error $result  The result of the index update.
	 */
	private function log_error( int $post_id, bool|\WP_Error $result ): void {
		// @todo this class shouldn't run if the Algolia config isn't good.
		if ( ! is_wp_error( $result ) || Indexer::ERROR_NOT_CONFIGURED === $result->get_error_code() ) {
			return;
		}

		$data = $result->get_error_data();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- @todo Surface this better with a Logger class.
		error_log( sprintf( 'OneSearch: failed to update records for post %d: %s %s', $post_id, $result->get_error_message(), $data['message'] ?? '' ) );
	}

	/**
	 * Checks whether the post type is indexable.
	 *
	 * @param string $post_type The post type.
	 */
	private function is_post_type_indexable( string $post_type ): bool {
		$allowed_post_types = Indexer::get_indexable_post_types();

		return ! is_wp_error( $allowed_post_types ) && in_array( $post_type, $allowed_post_types, true );
	}
}
