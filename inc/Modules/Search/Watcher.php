<?php
/**
 * Watches for object changes to reindex in Algolia.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Utils;

/**
 * Class - Watcher
 */
final class Watcher implements Registrable {
	/**
	 * The indexer, once instantiated.
	 */
	private ?Indexer $indexer = null;

	/**
	 * The media to sync at the end of the request, keyed by ID.
	 *
	 * @var array<int, true>
	 */
	private array $pending_media = [];

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
		add_action( 'shutdown', [ $this, 'sync_pending_media' ] );
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
	public function on_after_insert_post( $post_id, $post, $update = false, $post_before = null ): void {
		// The passed post is stale when a save_post callback updated it again.
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// New media is synced at the end of the request, once its image sizes are generated.
		if ( ! $update && 'attachment' === $post->post_type ) {
			$this->pending_media[ $post->ID ] = true;
			return;
		}

		$post_types  = $this->get_indexed_post_types();
		$post_before = $post_before instanceof \WP_Post ? $post_before : null;

		$this->sync( $post, $post_types, null !== $post_before && $this->is_indexable( $post_before, $post_types ) );

		$is_public = Post_Record::is_public( $post );

		// Attached media is only indexed while its parent is public.
		if ( null === $post_before || ! in_array( 'attachment', $post_types, true ) || Post_Record::is_public( $post_before ) === $is_public ) {
			return;
		}

		$attachment_ids = $this->get_attached_media_ids( $post->ID );

		if ( $is_public ) {
			$this->pending_media += array_fill_keys( $attachment_ids, true );
		} else {
			$this->log_error( $post->ID, $this->get_indexer()->delete_posts( $attachment_ids ) );
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
		// Core saves the metadata again after each image size, so the media is synced once, at the end of the request.
		if ( '_wp_attachment_metadata' === $meta_key ) {
			$this->pending_media[ (int) $object_id ] = true;
		}
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

		foreach ( $meta_ids as $meta_id ) {
			$meta = get_metadata_by_mid( 'post', (int) $meta_id );

			if ( $meta ) {
				$this->pending_media[ (int) $meta->post_id ] = true;
			}
		}
	}

	/**
	 * Syncs the posts queued during the request.
	 *
	 * @internal Hook callback
	 */
	public function sync_pending_media(): void {
		$post_ids            = array_keys( $this->pending_media );
		$this->pending_media = [];

		if ( [] === $post_ids ) {
			return;
		}

		$post_types = $this->get_indexed_post_types();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post ) {
				$this->sync( $post, $post_types, false );
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
			$attachment = get_post( $attachment_id );

			if ( $attachment instanceof \WP_Post ) {
				$this->sync( $attachment, $post_types, true );
			}
		}

		// Bail early if there's nothing left that's indexable (e.g. a draft or trashed post).
		if ( ! $post instanceof \WP_Post || ! $this->is_indexable( $post, $post_types ) ) {
			return;
		}

		$this->log_error( (int) $post_id, $this->get_indexer()->delete_posts( [ (int) $post_id ] ) );
	}

	/**
	 * Saves a post's records if it belongs in the index, or deletes them if it doesn't.
	 *
	 * @param \WP_Post $post        The post.
	 * @param string[] $post_types  The indexed post types.
	 * @param bool     $was_indexed Whether the post may have records to delete.
	 */
	private function sync( \WP_Post $post, array $post_types, bool $was_indexed ): void {
		if ( $this->is_indexable( $post, $post_types ) ) {
			$this->log_error( (int) $post->ID, $this->get_indexer()->save_post( $post ) );
		} elseif ( $was_indexed ) {
			$this->log_error( (int) $post->ID, $this->get_indexer()->delete_posts( [ (int) $post->ID ] ) );
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

		Utils::log(
			sprintf(
				'Failed to update records for post %d: %s %s',
				$post_id,
				$result->get_error_message(),
				is_array( $data ) ? (string) ( $data['message'] ?? '' ) : ''
			)
		);
	}
}
