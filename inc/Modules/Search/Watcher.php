<?php
/**
 * Watches for content changes to sync to Algolia.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Jobs\Queue;

/**
 * Class - Watcher
 */
final class Watcher implements Registrable {
	/**
	 * The job that syncs a post's records.
	 */
	public const SYNC_HOOK = 'onesearch_sync_post';

	/**
	 * The media attached to the posts being deleted, keyed by post ID.
	 *
	 * @var array<int, int[]>
	 */
	private array $deleted_posts_media = [];

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'wp_after_insert_post', [ $this, 'on_after_insert_post' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'on_post_meta_saved' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'on_post_meta_saved' ], 10, 3 );
		add_action( 'delete_post_meta', [ $this, 'on_delete_post_meta' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ] );
		add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 10, 2 );
		add_action( self::SYNC_HOOK, [ $this, 'sync_post' ] );
	}

	/**
	 * Syncs a post's records once it and its terms and meta are saved.
	 *
	 * @internal Hook callback
	 *
	 * @param int       $post_id     The post ID.
	 * @param \WP_Post  $post        The post object.
	 * @param bool      $update      Whether the post is being updated.
	 * @param ?\WP_Post $post_before The post as it was before the update.
	 */
	public function on_after_insert_post( $post_id, $post, $update = false, $post_before = null ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		// The passed post is stale when a save_post callback updated it again.
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$post_types  = $this->get_indexed_post_types();
		$post_before = $post_before instanceof \WP_Post ? $post_before : null;

		// A post that was indexed may have records to delete.
		if ( $this->is_indexable( $post, $post_types ) || ( null !== $post_before && $this->is_indexable( $post_before, $post_types ) ) ) {
			$this->queue_sync( (int) $post->ID );
		}

		// Attached media is only indexed while its parent is public.
		if ( null === $post_before || ! in_array( 'attachment', $post_types, true ) || Post_Record::is_public( $post_before ) === Post_Record::is_public( $post ) ) {
			return;
		}

		foreach ( $this->get_attached_media_ids( (int) $post->ID ) as $attachment_id ) {
			$this->queue_sync( $attachment_id );
		}
	}

	/**
	 * Queues media to sync when its image sizes are saved.
	 *
	 * @internal Hook callback
	 *
	 * @param int    $meta_id   The meta ID.
	 * @param int    $object_id The post ID.
	 * @param string $meta_key  The meta key.
	 */
	public function on_post_meta_saved( $meta_id, $object_id, $meta_key ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		if ( '_wp_attachment_metadata' !== $meta_key ) {
			return;
		}

		$post = get_post( (int) $object_id );

		// Only queue the media if it's indexable.
		if ( ! $post instanceof \WP_Post || ! $this->is_indexable( $post, $this->get_indexed_post_types() ) ) {
			return;
		}

		$this->queue_sync( (int) $post->ID );
	}

	/**
	 * Queues the posts using a featured image that's being deleted.
	 *
	 * @internal Hook callback
	 *
	 * @param string[] $meta_ids  The meta IDs being deleted.
	 * @param int      $object_id The post ID, or 0 when the meta is deleted from every post.
	 * @param string   $meta_key  The meta key.
	 */
	public function on_delete_post_meta( $meta_ids, $object_id, $meta_key ): void {
		// A featured image removed from a single post is synced when that post is saved.
		if ( '_thumbnail_id' !== $meta_key || 0 !== (int) $object_id ) {
			return;
		}

		$post_types = $this->get_indexed_post_types();

		foreach ( $meta_ids as $meta_id ) {
			$meta = get_metadata_by_mid( 'post', (int) $meta_id );
			$post = $meta ? get_post( (int) $meta->post_id ) : null;

			// The job runs once the meta is gone.
			if ( $post instanceof \WP_Post && $this->is_indexable( $post, $post_types ) ) {
				$this->queue_sync( (int) $post->ID );
			}
		}
	}

	/**
	 * Notes the media attached to a post before it's deleted, since deleting the post moves the media to the post's parent.
	 *
	 * @internal Hook callback
	 *
	 * @param int $post_id The ID of the post being deleted.
	 */
	public function on_before_delete_post( $post_id ): void {
		$this->deleted_posts_media[ (int) $post_id ] = $this->get_attached_media_ids( (int) $post_id );
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
		$post_types     = $this->get_indexed_post_types();
		$attachment_ids = in_array( 'attachment', $post_types, true ) ? $this->deleted_posts_media[ (int) $post_id ] ?? [] : [];

		unset( $this->deleted_posts_media[ (int) $post_id ] );

		foreach ( $attachment_ids as $attachment_id ) {
			// Core moves the media to the post's parent with a direct query, so its cached post still has the old parent.
			clean_post_cache( $attachment_id );
			$this->queue_sync( $attachment_id );
		}

		// Bail early if there's nothing left that's indexable (e.g. a draft or trashed post).
		if ( ! $post instanceof \WP_Post || ! $this->is_indexable( $post, $post_types ) ) {
			return;
		}

		$this->queue_sync( (int) $post_id );
	}

	/**
	 * Saves a post's records if it belongs in the index, or deletes them if it doesn't.
	 *
	 * @internal Hook callback
	 *
	 * @param int $post_id The post ID.
	 *
	 * @throws \RuntimeException When the records can't be updated.
	 */
	public function sync_post( $post_id ): void {
		$post_types = Search_Index::get_indexable_post_types();

		if ( is_wp_error( $post_types ) ) {
			// Don't delete the records when the post types can't be fetched.
			$result = $post_types;
		} else {
			// Each sync gets its own client, since the client skips hosts that failed for a few minutes.
			$search_index = new Search_Index();
			$post         = get_post( (int) $post_id );
			$result       = $post instanceof \WP_Post && $this->is_indexable( $post, $post_types )
				? $search_index->save_post( $post )
				: $search_index->delete_posts( [ (int) $post_id ] );
		}

		// Without an Algolia config there's no index to update yet, e.g. before Algolia is set up.
		if ( is_wp_error( $result ) && Search_Index::ERROR_NOT_CONFIGURED !== $result->get_error_code() ) {
			throw new \RuntimeException( esc_html( Search_Index::get_error_message( $result ) ) );
		}
	}

	/**
	 * Checks whether a post belongs in the index.
	 *
	 * @param \WP_Post $post       The post.
	 * @param string[] $post_types The indexed post types.
	 */
	private function is_indexable( \WP_Post $post, array $post_types ): bool {
		return in_array( $post->post_type, $post_types, true )
			&& in_array( $post->post_status, Post_Record::get_allowed_statuses( [ $post->post_type ] ), true )
			&& ! Post_Record::is_protected( $post );
	}

	/**
	 * Gets the post types the site indexes, or none if they can't be fetched.
	 *
	 * @return string[]
	 */
	private function get_indexed_post_types(): array {
		$post_types = Search_Index::get_indexable_post_types();

		return is_wp_error( $post_types ) ? [] : $post_types;
	}

	/**
	 * Gets the IDs of the media attached to a post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return int[]
	 */
	private function get_attached_media_ids( int $post_id ): array {
		$query = new \WP_Query(
			[
				'post_parent'    => $post_id,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				 // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- The next indexing run catches the rest.
				'posts_per_page' => 999,
				'no_found_rows'  => true,
			]
		);

		/** @var int[] $post_ids */
		$post_ids = $query->get_posts();

		return $post_ids;
	}

	/**
	 * Queues the job that syncs a post.
	 *
	 * @param int $post_id The post ID.
	 */
	private function queue_sync( int $post_id ): void {
		( new Queue() )->add( self::SYNC_HOOK, [ $post_id ], true );
	}
}
