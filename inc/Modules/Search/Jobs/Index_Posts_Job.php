<?php
/**
 * The job that saves or deletes the records of some of this site's posts.
 *
 * @package OneSearch\Modules\Search\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search\Jobs;

use OneSearch\Jobs\Abstract_Job;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Post_Record;

/**
 * Class - Index_Posts_Job
 */
final class Index_Posts_Job extends Abstract_Job {
	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, mixed> $args The post IDs, and the record's key.
	 */
	public function dispatch( array $args = [], array $data = [] ): true|\WP_Error {
		return parent::dispatch( $args, [ 'post_ids' => $args[0] ] + $data );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, mixed> $args The post IDs, and the record's key.
	 */
	protected function get_key( array $args ): string {
		return (string) $args[1];
	}

	/**
	 * {@inheritDoc}
	 *
	 * Saves the records of the posts that belong in the index and deletes the others, as they are when it runs, and records each in the index status.
	 *
	 * @param array<int, mixed> $args The post IDs, and the record's key.
	 *
	 * @throws \RuntimeException When the post types can't be fetched, or the posts can't be saved or deleted.
	 */
	protected function run( array $args ): bool {
		$post_ids   = array_map( 'intval', (array) $args[0] );
		$post_types = Indexer::get_indexable_post_types();

		// The records stay, since the posts may still belong in the index.
		if ( is_wp_error( $post_types ) ) {
			throw new \RuntimeException( esc_html( $post_types->get_error_message() ) );
		}

		// Action Scheduler runs many actions per request, so an earlier one may have cached a post before another request changed it.
		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );
		}

		$posts = [];

		if ( [] !== $post_types ) {
			$query = Post_Record::query_indexable_posts(
				$post_types,
				[
					'post__in'               => $post_ids,
					'posts_per_page'         => count( $post_ids ),
					'no_found_rows'          => true,
					// Records need the meta and terms of every post, so load them with one query.
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
				]
			);

			// Records include the featured images too.
			update_post_thumbnail_cache( $query );

			/** @var \WP_Post[] $posts */
			$posts = $query->posts;
		}

		$indexer = new Indexer();
		$errors  = $indexer->save_posts( $posts );

		// The posts' older records, and when they were indexed, are still true.
		if ( is_wp_error( $errors ) ) {
			throw new \RuntimeException( esc_html( Indexer::get_error_message( $errors ) ) );
		}

		$removed    = array_values( array_diff( $post_ids, array_map( static fn ( \WP_Post $post ): int => $post->ID, $posts ) ) );
		$is_deleted = $indexer->delete_posts( $removed );

		// The records stay, so the posts stay in search until a later run deletes them.
		if ( is_wp_error( $is_deleted ) ) {
			throw new \RuntimeException( esc_html( Indexer::get_error_message( $is_deleted ) ) );
		}

		$this->save_data(
			$this->get_key( $args ),
			[
				'done'   => count( $post_ids ),
				'failed' => count( $errors ),
			]
		);

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * It reports none, since Index_Site_Job's status shows the posts' counts.
	 */
	public function get_statuses(): array {
		return [];
	}
}
