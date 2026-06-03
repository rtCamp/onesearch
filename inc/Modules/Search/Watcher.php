<?php
/**
 * Watches for object changes to reindex in Algolia.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Modules\Jobs\SyncJob;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Scheduler\JobScheduler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - Watcher
 */
final class Watcher implements Registrable {
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
	 * Schedules an async SyncJob to update the post in Algolia instead of
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

		$job = new SyncJob();
		$job->set_data(
			[
				'post_ids' => [ (int) $post->ID ],
			]
		);
		$job->set_group( 'watcher' );
		$job->set_max_retries( 2 );
		$job->set_retry_delay_seconds( 30 );

		$scheduler = new JobScheduler();

		try {
			$scheduler->schedule( $job );
		} catch ( \Throwable $e ) {
			// Fallback to synchronous indexing if scheduling fails.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[OneSearch] Failed to schedule SyncJob: %s', $e->getMessage() ) );

			$this->sync_post_inline( $post );
		}
	}

	/**
	 * Synchronously sync a post to Algolia (fallback when async scheduling fails).
	 *
	 * @param \WP_Post $post The post to sync.
	 */
	private function sync_post_inline( \WP_Post $post ): void {
		$indexer = new Index();

		$this->delete_post_records( $indexer, (int) $post->ID );

		if ( ! in_array( $post->post_status, Post_Record::get_allowed_statuses( [ $post->post_type ] ), true ) ) {
			return;
		}

		$records = ( new Post_Record() )->to_records( $post );

		$indexer->save_records( $records );
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

		$this->delete_post_records( new Index(), (int) $post_id );
	}

	/**
	 * Deletes every record belonging to a post.
	 *
	 * The filter has to use the very same `site_post_id` the records were written with,
	 * otherwise Algolia matches nothing and reports success.
	 *
	 * @see Post_Record::get_site_post_id()
	 *
	 * @param \OneSearch\Modules\Search\Index $indexer The index to delete the records from.
	 * @param int                             $post_id The post ID.
	 */
	private function delete_post_records( Index $indexer, int $post_id ): bool|\WP_Error {
		$deleted = $indexer->delete_by(
			[
				'filters' => sprintf( 'site_post_id:"%s"', ( new Post_Record() )->get_site_post_id( $post_id ) ),
			]
		);

		// @todo this class shouldn't run if the Algolia config isn't good.
		if ( is_wp_error( $deleted ) && ! in_array( $deleted->get_error_code(), [ 'algolia_credentials_missing', 'algolia_index_name_invalid' ], true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- @todo Surface this better with a Logger class.
			error_log( sprintf( 'OneSearch: failed to remove records for post %d: %s', $post_id, $deleted->get_error_message() ) );
		}

		return $deleted;
	}

	/**
	 * Checks whether the post type is indexable.
	 *
	 * @param string $post_type The post type.
	 */
	private function is_post_type_indexable( string $post_type ): bool {
		$allowed_post_types = $this->get_allowed_post_types();

		return ! is_wp_error( $allowed_post_types ) && in_array( $post_type, $allowed_post_types, true );
	}

	/**
	 * Gets the allowed post types.
	 *
	 * Uses the indexable entities settings on governing site, or fetches from governing site if on child.
	 *
	 * @return string[]|\WP_Error
	 */
	private function get_allowed_post_types(): array|\WP_Error {
		if ( Settings::is_governing_site() ) {
			$entities = Search_Settings::get_indexable_entities();

			return $entities['entities'][ Utils::normalize_url( get_site_url() ) ] ?? [];
		}

		// For brand sites, fetch from the consolidated config.
		$config = Governing_Data_Handler::get_brand_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return $config['indexable_entities'] ?? [];
	}
}
