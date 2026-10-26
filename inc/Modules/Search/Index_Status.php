<?php
/**
 * The record of which posts are in the index, and why the others aren't.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

/**
 * Class - Index_Status
 *
 * Each post keeps when it was last indexed, or why it couldn't be, in its meta.
 *
 * @phpstan-type FailedPost array{ id: int, title: string, edit_url: string, error: string }
 * @phpstan-type IndexSummary array{ total: int, indexed: int, failed: list<FailedPost> }
 */
final class Index_Status {
	/**
	 * The post meta holding when the post was last indexed.
	 */
	public const META_INDEXED_AT = '_onesearch_indexed_at';

	/**
	 * The post meta holding why the post's latest version couldn't be indexed.
	 */
	public const META_ERROR = '_onesearch_index_error';

	/**
	 * Records that posts were indexed.
	 *
	 * @param int[] $post_ids The post IDs.
	 */
	public static function record_saved( array $post_ids ): void {
		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, self::META_INDEXED_AT, time() );
			delete_post_meta( $post_id, self::META_ERROR );
		}
	}

	/**
	 * Records that posts left the index.
	 *
	 * @param int[] $post_ids The post IDs.
	 */
	public static function record_removed( array $post_ids ): void {
		foreach ( $post_ids as $post_id ) {
			delete_post_meta( $post_id, self::META_INDEXED_AT );
			delete_post_meta( $post_id, self::META_ERROR );
		}
	}

	/**
	 * Records why posts' latest versions couldn't be indexed.
	 *
	 * When they were last indexed stays, since their older records are still there.
	 *
	 * @param array<int, string> $errors Why each post couldn't be indexed, keyed by post ID.
	 */
	public static function record_errors( array $errors ): void {
		foreach ( $errors as $post_id => $error ) {
			update_post_meta( $post_id, self::META_ERROR, $error );
		}
	}

	/**
	 * Forgets when posts were indexed before an indexing run, once it deleted the records it didn't save again.
	 *
	 * @param int $before When the run started, as a Unix timestamp.
	 */
	public static function delete_stale( int $before ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API finds meta by a range of values.
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST( meta_value AS UNSIGNED ) < %d", self::META_INDEXED_AT, $before ) );

		foreach ( $post_ids as $post_id ) {
			delete_post_meta( (int) $post_id, self::META_INDEXED_AT );
		}
	}

	/**
	 * Forgets when every post was indexed and why any couldn't be, once all of the site's records were deleted, e.g. when it left its governing site.
	 */
	public static function clear(): void {
		delete_post_meta_by_key( self::META_INDEXED_AT );
		delete_post_meta_by_key( self::META_ERROR );
	}

	/**
	 * Whether a post's latest version couldn't be indexed.
	 *
	 * @param int $post_id The post ID.
	 */
	public static function has_error( int $post_id ): bool {
		return metadata_exists( 'post', $post_id, self::META_ERROR );
	}

	/**
	 * Gets how many of the site's indexable posts are indexed, and every post that failed.
	 *
	 * @param string[] $post_types The post types the site indexes.
	 *
	 * @return IndexSummary
	 */
	public static function get_summary( array $post_types ): array {
		$summary = [
			'total'   => 0,
			'indexed' => 0,
			'failed'  => [],
		];

		// WP_Query falls back to posts without a post type.
		if ( [] !== $post_types ) {
			$count = [
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			];

			$summary['total']   = Post_Record::query_indexable_posts( $post_types, $count )->found_posts;
			$summary['indexed'] = Post_Record::query_indexable_posts(
				$post_types,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only counts them.
				[ 'meta_key' => self::META_INDEXED_AT ] + $count
			)->found_posts;
		}

		$errors = self::get_errors();
		_prime_post_caches( array_keys( $errors ), false, false );

		foreach ( $errors as $post_id => $error ) {
			$post = get_post( $post_id );

			$summary['failed'][] = [
				'id'       => $post_id,
				'title'    => $post instanceof \WP_Post ? $post->post_title : __( 'Deleted item', 'onesearch' ),
				// Not get_edit_post_link(), since the governing site asks without a logged-in user.
				'edit_url' => $post instanceof \WP_Post ? admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) ) : '',
				'error'    => $error,
			];
		}

		return $summary;
	}

	/**
	 * Gets the error of each post that has one, including the deleted ones.
	 *
	 * @return array<int, string> Keyed by post ID, in order.
	 */
	public static function get_errors(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A deleted post's meta can't be read through its post.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC", self::META_ERROR ) );

		$errors = [];
		foreach ( $rows as $row ) {
			$errors[ (int) $row->post_id ] = (string) $row->meta_value;
		}

		return $errors;
	}
}
