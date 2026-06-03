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
		$site_post_id = sprintf( '%s_%d', Utils::normalize_url( get_site_url() ), (int) $post->ID );
		$indexer      = new Index();

		$indexer->delete_by(
			[
				'filters' => sprintf( 'site_post_id:"%s"', $site_post_id ),
			]
		);

		if ( ! in_array( $post->post_status, Post_Record::get_allowed_statuses( [ $post->post_type ] ), true ) ) {
			return;
		}

		$records = ( new Post_Record() )->to_records( $post );
		$indexer->save_records( $records );
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
