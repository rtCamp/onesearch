<?php
/**
 * Watches for object changes to index in Algolia.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Modules\Search\Jobs\Index_Site_Job;
use OneSearch\Modules\Search\Jobs\Jobs;
use OneSearch\Utils;

/**
 * Class - Watcher
 */
final class Watcher implements Registrable {
	/**
	 * The posts to sync at the end of the request, keyed by ID.
	 *
	 * @var array<int, true>
	 */
	private array $pending = [];

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
		add_action( 'wp_media_attach_action', [ $this, 'on_media_attach_action' ], 10, 2 );
		add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ] );
		// WordPress deletes media without firing before_delete_post.
		add_action( 'delete_attachment', [ $this, 'on_before_delete_post' ] );
		add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 10, 2 );
		add_action( 'shutdown', [ $this, 'sync_pending' ] );
	}

	/**
	 * Queues a post to sync once it and its terms and meta are saved.
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

		// A post that was indexed may have records to delete, and one that failed is tried again.
		if (
			Post_Record::is_indexable( $post, $post_types ) ||
			( null !== $post_before && Post_Record::is_indexable( $post_before, $post_types ) ) ||
			Index_Status::has_error( $post->ID )
		) {
			$this->pending[ $post->ID ] = true;
		}

		// Attached media is only indexed while its parent is public.
		if ( null === $post_before || ! in_array( 'attachment', $post_types, true ) || Post_Record::is_public( $post_before ) === Post_Record::is_public( $post ) ) {
			return;
		}

		$this->pending += array_fill_keys( $this->get_attached_media_ids( $post->ID ), true );
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

		// Bail if the post is not indexable.
		if ( ! $post instanceof \WP_Post || ! Post_Record::is_indexable( $post, $this->get_indexed_post_types() ) ) {
			return;
		}

		$this->pending[ $post->ID ] = true;
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

			if ( $post instanceof \WP_Post && Post_Record::is_indexable( $post, $post_types ) ) {
				$this->pending[ $post->ID ] = true;
			}
		}
	}

	/**
	 * Queues media attached to a post or detached from one in the Media Library, which changes its parent without saving it.
	 *
	 * @internal Hook callback
	 *
	 * @param string $action        Whether the media was attached or detached.
	 * @param int    $attachment_id The media ID.
	 */
	public function on_media_attach_action( $action, $attachment_id ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		if ( in_array( 'attachment', $this->get_indexed_post_types(), true ) ) {
			$this->pending[ (int) $attachment_id ] = true;
		}
	}

	/**
	 * Notes the media attached to a post before it's deleted, since deleting the post moves the media to the post's parent.
	 *
	 * @internal Hook callback
	 *
	 * @param int $post_id The ID of the post or media being deleted.
	 */
	public function on_before_delete_post( $post_id ): void {
		$this->deleted_posts_media[ (int) $post_id ] = $this->get_attached_media_ids( (int) $post_id );

		if ( Index_Status::has_error( (int) $post_id ) ) {
			$this->pending[ (int) $post_id ] = true;
		}
	}

	/**
	 * Queues a post that was permanently deleted, along with the media it had.
	 *
	 * @internal Hook callback
	 *
	 * @param int       $post_id The ID of the deleted post.
	 * @param ?\WP_Post $post    The post that was deleted.
	 */
	public function on_deleted_post( $post_id, $post = null ): void {
		$post_types = $this->get_indexed_post_types();

		if ( in_array( 'attachment', $post_types, true ) ) {
			$this->pending += array_fill_keys( $this->deleted_posts_media[ (int) $post_id ] ?? [], true );
		}

		unset( $this->deleted_posts_media[ (int) $post_id ] );

		// Skip what wasn't indexed, e.g. a draft or a trashed post.
		if ( $post instanceof \WP_Post && Post_Record::is_indexable( $post, $post_types ) ) {
			$this->pending[ (int) $post_id ] = true;
		}
	}

	/**
	 * Queues the posts that changed during the request to sync.
	 *
	 * @internal Hook callback
	 */
	public function sync_pending(): void {
		$post_ids      = array_keys( $this->pending );
		$this->pending = [];

		if ( [] !== $post_ids ) {
			Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ Utils::get_current_site_url(), $post_ids ] );
		}
	}

	/**
	 * Gets the post types the site indexes, or none if they can't be fetched.
	 *
	 * @return string[]
	 */
	private function get_indexed_post_types(): array {
		$post_types = Indexer::get_indexable_post_types();

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
				 // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Additional will be caught on reindex.
				'posts_per_page' => 999,
				'no_found_rows'  => true,
			]
		);

		/** @var int[] $post_ids */
		$post_ids = $query->get_posts();

		return $post_ids;
	}
}
