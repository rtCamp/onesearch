<?php
/**
 * Watches for object changes to reindex in Algolia.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;

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
	 * @internal Hook callback
	 *
	 * @param string   $new_status The new post status.
	 * @param string   $old_status The previous post status.
	 * @param \WP_Post $post       The post object.
	 */
	public function on_post_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->is_post_type_indexable( (string) $post->post_type ) ) {
			return;
		}

		$allowed_statuses = Post_Record::get_allowed_statuses( [ $post->post_type ] );

		// Check if the new status is allowed before reindexing.
		if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
			// Only clean up if the post was indexed under its previous status.
			if ( in_array( $old_status, $allowed_statuses, true ) ) {
				$this->log_error( (int) $post->ID, $this->get_indexer()->delete_post( (int) $post->ID ) );
			}

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
