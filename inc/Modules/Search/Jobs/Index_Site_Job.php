<?php
/**
 * Async job for indexing a site's posts.
 *
 * @package OneSearch\Modules\Search\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search\Jobs;

use OneSearch\Jobs\Abstract_Job;
use OneSearch\Jobs\Parent_Job_Trait;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Post_Record;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - Index_Site_Job
 */
final class Index_Site_Job extends Abstract_Job {
	use Parent_Job_Trait;

	/**
	 * The number of posts per child.
	 */
	private const CHUNK_SIZE = 100;

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, mixed> $args The site's URL, and its post IDs or none for all.
	 */
	public function dispatch( array $args = [], array $data = [] ): true|\WP_Error {
		$this->delete_children( $this->get_key( $args ) );

		return parent::dispatch( $args, [ 'post_ids' => $args[1] ?? null ] + $data );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Only a run of all posts is reported, since the posts of the others show in the index summary.
	 */
	public function get_statuses(): array {
		return array_values( array_filter( parent::get_statuses(), static fn ( array $status ): bool => ! is_array( $status['data']['post_ids'] ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A run of all posts is keyed by the site's URL. Each list of posts gets its own record, so it doesn't replace another run.
	 *
	 * @param array<int, mixed> $args The site's URL, and its post IDs or none for all.
	 */
	protected function get_key( array $args ): string {
		return is_array( $args[1] ?? null ) ? md5( (string) wp_json_encode( $args[1] ) ) : (string) $args[0];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_child(): Index_Posts_Job {
		return Jobs::registry()->get( Index_Posts_Job::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A brand site indexes its own posts, so it's asked to index all of them.
	 *
	 * @param array<int, mixed> $args The site's URL, and its post IDs or none for all.
	 *
	 * @throws \RuntimeException When the post types can't be fetched, or the brand site can't be asked.
	 */
	protected function run( array $args ): bool {
		$site_url = (string) $args[0];
		$post_ids = is_array( $args[1] ?? null ) ? array_map( 'intval', $args[1] ) : null;
		$key      = $this->get_key( $args );

		if ( Utils::get_current_site_url() !== $site_url ) {
			$site_data = Settings::get_shared_site_by_url( $site_url );

			if ( null === $site_data ) {
				throw new \RuntimeException( esc_html__( 'The site is no longer a brand site.', 'onesearch' ) );
			}

			$asked = Remote_Client::reindex_brand_site( $site_data );

			if ( is_wp_error( $asked ) ) {
				throw new \RuntimeException( esc_html( $asked->get_error_message() ) );
			}

			return true;
		}

		if ( null === $post_ids ) {
			// The governing site starts this after changing what's indexed, so a brand site needs its latest config.
			if ( Settings::is_consumer_site() ) {
				Remote_Client::clear_brand_config_cache();
			}

			$post_types = Indexer::get_indexable_post_types();

			if ( is_wp_error( $post_types ) ) {
				throw new \RuntimeException( esc_html( $post_types->get_error_message() ) );
			}

			// WP_Query falls back to every post without post types.
			/** @var int[] $post_ids */
			$post_ids = [] === $post_types ? [] : Post_Record::query_indexable_posts(
				$post_types,
				[
					'fields'         => 'ids',
					// phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- Only their IDs, to split them.
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				]
			)->posts;

			$this->save_data( $key, [ 'started_at' => time() ] );
		}

		// Batch into multiple child jobs.
		return $this->dispatch_children(
			$key,
			array_map( static fn ( array $chunk ): array => [ $chunk ], array_chunk( $post_ids, self::CHUNK_SIZE ) ),
			count( $post_ids )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * After a run of all of this site's posts, deletes the records it didn't save again, of the posts that left the index and of the chunks a shorter post no longer has. The run only completes when every child saved its posts again.
	 *
	 * @throws \RuntimeException When the stale records can't be deleted.
	 */
	protected function on_complete( string $key, array $record ): void {
		// Nothing shows a run of a list of posts, and a brand site shows its own run once it's been asked.
		if ( is_array( $record['data']['post_ids'] ) || Utils::get_current_site_url() !== $key ) {
			$this->records->delete_record( $key );
			return;
		}

		$is_deleted = ( new Indexer() )->delete_stale_site_records( (int) $record['data']['started_at'] );

		if ( is_wp_error( $is_deleted ) ) {
			throw new \RuntimeException( esc_html( Indexer::get_error_message( $is_deleted ) ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * A run of a list of posts fails the site's run of all posts instead, since nothing shows its own run, and the error is about the site rather than its posts. A re-index then tries again.
	 */
	protected function on_failure( string $key, array $record ): void {
		if ( ! is_array( $record['data']['post_ids'] ) ) {
			return;
		}

		$site_url = Utils::get_current_site_url();

		$this->records->delete_record( $key );
		$this->records->save_record(
			$site_url,
			[
				'status' => self::STATUS_FAILED,
				'error'  => $record['error'],
			] + ( $this->records->get_record( $site_url ) ?? [
				'data'      => [ 'post_ids' => null ],
				'action_id' => null,
			] )
		);
	}
}
