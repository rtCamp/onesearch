<?php
/**
 * Indexes the site in the background, and keeps track of each site's progress.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Jobs\Progress;
use OneSearch\Jobs\Queue;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - Indexer
 *
 * @phpstan-import-type ProgressData from \OneSearch\Jobs\Progress
 */
final class Indexer implements Registrable {
	/**
	 * The job that indexes a batch of posts.
	 */
	public const BATCH_HOOK = 'onesearch_index_batch';

	/**
	 * The job that tells a brand site to re-index itself.
	 */
	public const BRAND_SITE_HOOK = 'onesearch_reindex_brand_site';

	/**
	 * The job that asks a brand site for its progress.
	 */
	public const BRAND_PROGRESS_HOOK = 'onesearch_check_brand_index_progress';

	/**
	 * The option holding the progress of this site's indexing run.
	 */
	public const OPTION_PROGRESS = 'onesearch_index_progress';

	/**
	 * The prefix of the options holding the progress of a governing site's brand sites. Each brand site has its own, so their updates don't overwrite each other.
	 */
	private const OPTION_BRAND_PROGRESS_PREFIX = 'onesearch_index_progress_';

	/**
	 * The number of posts per batch.
	 */
	private const BATCH_SIZE = 100;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( self::BATCH_HOOK, [ $this, 'run_batch' ], 10, 2 );
		add_action( self::BRAND_SITE_HOOK, [ $this, 'reindex_brand_site' ] );
		add_action( self::BRAND_PROGRESS_HOOK, [ $this, 'check_brand_site_progress' ] );
	}

	/**
	 * Starts indexing this site, and a governing site's brand sites too. A run that's still going starts over.
	 *
	 * @return true|\WP_Error
	 */
	public static function start(): bool|\WP_Error {
		$queue = new Queue();

		// The batches of the run this one replaces.
		$queue->cancel( self::BATCH_HOOK );

		$progress = self::get_progress();
		$run      = $progress->start();

		// The governing site starts this after changing what's indexed, so the brand site needs its new config.
		if ( Settings::is_consumer_site() ) {
			Remote_Client::clear_brand_config_cache();
		}

		if ( ! $queue->add( self::BATCH_HOOK, [ $run['id'], 0 ] ) ) {
			$message = __( "Indexing couldn't be started.", 'onesearch' );
			$progress->finish( $run['id'], [ $message ] );

			return new \WP_Error( 'onesearch_indexing_not_started', $message, [ 'status' => 500 ] );
		}

		if ( Settings::is_governing_site() ) {
			foreach ( array_keys( Settings::get_shared_sites() ) as $site_url ) {
				self::get_brand_progress( $site_url )->start();
				$queue->add( self::BRAND_SITE_HOOK, [ $site_url ], true );
			}
		}

		// Someone is waiting on it.
		add_action( 'shutdown', [ Queue::class, 'start_runner' ] );

		return true;
	}

	/**
	 * Gets the progress of the latest indexing run of this site, and of a governing site's brand sites, keyed by site URL.
	 *
	 * @return array<string, ?ProgressData> Null for a site that hasn't been indexed yet.
	 */
	public static function get_all_progress(): array {
		$all_progress = [ Utils::get_current_site_url() => self::get_site_progress() ];

		if ( Settings::is_governing_site() ) {
			foreach ( array_keys( Settings::get_shared_sites() ) as $site_url ) {
				$all_progress[ $site_url ] = self::get_brand_site_progress( $site_url );
			}
		}

		return $all_progress;
	}

	/**
	 * Gets the progress of the latest indexing run of this site.
	 *
	 * An indexing run that's still going without a batch left to run was cut short, so it's reported as failed. It says why when its last batch failed, e.g. with a fatal error, or was cancelled under Tools > Scheduled Actions.
	 *
	 * @return ?ProgressData
	 */
	private static function get_site_progress(): ?array {
		$progress = self::get_progress();
		$current  = $progress->get();

		if ( null === $current || ! Progress::is_active( $current ) || ( new Queue() )->has( self::BATCH_HOOK ) ) {
			return $current;
		}

		// The last batch may have finished it since.
		$current = $progress->get();

		if ( null === $current || ! Progress::is_active( $current ) ) {
			return $current;
		}

		// The batches run one after another, and a batch of an older run doesn't queue another, so the latest one is this run's last.
		$last = ( new Queue() )->get_latest( self::BATCH_HOOK );
		$last = null !== $last && ( $last['args'][0] ?? null ) === $current['id'] ? $last : null;

		$current['status'] = Progress::STATUS_FAILED;

		if ( \ActionScheduler_Store::STATUS_CANCELED === ( $last['status'] ?? null ) ) {
			$current['errors'][] = __( 'Indexing was cancelled.', 'onesearch' );
		} elseif ( '' !== ( $last['error'] ?? '' ) ) {
			$current['errors'][] = sprintf(
				/* translators: %s: error message. */
				__( 'Indexing stopped: %s', 'onesearch' ),
				$last['error']
			);
		}

		return $current;
	}

	/**
	 * Gets the jobs that failed, keyed by the URL of the site they're about.
	 *
	 * An indexing run fixes them, so a site's post syncs only count from its latest indexing run on.
	 *
	 * @param array<string, ?ProgressData> $progress The progress of each site's latest indexing run, keyed by site URL.
	 *
	 * @return array<string, list<array{job: string, args: array<int, mixed>, error: string}>>
	 */
	public static function get_failed_jobs( array $progress ): array {
		$site_url = Utils::get_current_site_url();
		$failed   = [];

		foreach ( ( new Queue() )->get_failures( Watcher::SYNC_HOOK, (int) ( $progress[ $site_url ]['started_at'] ?? 0 ) ) as $failure ) {
			$failed[ $site_url ][] = [
				'job'   => Watcher::SYNC_HOOK,
				'args'  => $failure['args'],
				'error' => $failure['error'],
			];
		}

		return $failed;
	}

	/**
	 * Gets the progress of a brand site's latest indexing run, as this site last heard it.
	 *
	 * While the run is going, this queues a job that asks the brand site for its progress.
	 *
	 * @param string $site_url The brand site's URL.
	 *
	 * @return ?ProgressData
	 */
	private static function get_brand_site_progress( string $site_url ): ?array {
		$current = self::get_brand_progress( $site_url )->get();

		if ( self::is_brand_site_running( $site_url, $current ) && ( new Queue() )->add( self::BRAND_PROGRESS_HOOK, [ $site_url ], true ) ) {
			// Someone is waiting on it.
			add_action( 'shutdown', [ Queue::class, 'start_runner' ] );
		}

		return $current;
	}

	/**
	 * Deletes the stored progress of a brand site, e.g. once it's removed.
	 *
	 * @param string $site_url The brand site's URL.
	 */
	public static function delete_brand_progress( string $site_url ): void {
		self::get_brand_progress( $site_url )->delete();
	}

	/**
	 * Runs a batch of an indexing run.
	 *
	 * @internal Hook callback
	 *
	 * @param string $run_id   The ID of the indexing run.
	 * @param int    $after_id The ID of the last post of the batch before, or 0 for the first batch.
	 *
	 * @throws \RuntimeException With the reason the batch failed.
	 */
	public function run_batch( $run_id, $after_id ): void {
		$errors = $this->index_batch( (string) $run_id, (int) $after_id );

		if ( [] !== $errors ) {
			throw new \RuntimeException( esc_html( implode( ' ', $errors ) ) );
		}
	}

	/**
	 * Tells a brand site to re-index itself. If it can't, its indexing run fails with the reason.
	 *
	 * @internal Hook callback
	 *
	 * @param string $site_url The brand site's URL.
	 *
	 * @throws \RuntimeException With the reason, so Action Scheduler marks the job as failed.
	 */
	public function reindex_brand_site( $site_url ): void {
		$site = Settings::get_shared_site_by_url( (string) $site_url );

		// Removed since.
		if ( null === $site ) {
			return;
		}

		$progress = self::get_brand_progress( $site['url'] );
		$current  = $progress->get();
		$started  = Remote_Client::reindex_brand_site( $site );

		if ( ! is_wp_error( $started ) ) {
			return;
		}

		if ( null !== $current ) {
			$progress->finish( $current['id'], [ $started->get_error_message() ] );
		}

		throw new \RuntimeException( esc_html( $started->get_error_message() ) );
	}

	/**
	 * Asks a brand site for its progress, and stores it. If the brand site can't say, the next check asks again.
	 *
	 * @internal Hook callback
	 *
	 * @param string $site_url The brand site's URL.
	 *
	 * @throws \RuntimeException With the reason the brand site couldn't say, so Action Scheduler logs it.
	 */
	public function check_brand_site_progress( $site_url ): void {
		$site = Settings::get_shared_site_by_url( (string) $site_url );

		// Removed since.
		if ( null === $site ) {
			return;
		}

		$progress = self::get_brand_progress( $site['url'] );
		$current  = $progress->get();

		// Finished, or started over, since the job was queued.
		if ( null === $current || ! self::is_brand_site_running( (string) $site_url, $current ) ) {
			return;
		}

		$response = Remote_Client::get_brand_index_progress( $site );
		$fetched  = ! is_wp_error( $response ) ? Progress::sanitize( $response ) : null;

		if ( null === $fetched ) {
			throw new \RuntimeException( esc_html( is_wp_error( $response ) ? $response->get_error_message() : 'The brand site sent its progress in the wrong shape.' ) );
		}

		// Started over, or removed, while the brand site was asked.
		if ( $progress->get() !== $current ) {
			return;
		}

		$progress->set( $fetched );
	}

	/**
	 * Indexes a batch of posts, and queues the next batch, or finishes the indexing run after the last one.
	 *
	 * @param string $run_id   The ID of the indexing run.
	 * @param int    $after_id The ID of the last post of the batch before, or 0 for the first batch.
	 *
	 * @return string[] The batch's errors.
	 */
	private function index_batch( string $run_id, int $after_id ): array {
		$progress = self::get_progress();
		$current  = $progress->get();

		// The indexing run was started over, or has finished.
		if ( null === $current || $current['id'] !== $run_id || ! Progress::is_active( $current ) ) {
			return [];
		}

		$post_types = Search_Index::get_indexable_post_types();
		if ( is_wp_error( $post_types ) ) {
			return $this->finish( $run_id, [ Search_Index::get_error_message( $post_types ) ] );
		}

		if ( 0 === $after_id ) {
			$progress->advance( $run_id, 0, 0, [], [] !== $post_types ? Post_Record::count_indexable_posts( $post_types ) : 0 );
		}

		// One more than a batch, to tell whether it's the last one.
		$posts   = [] !== $post_types ? Post_Record::get_indexable_posts( $post_types, self::BATCH_SIZE + 1, $after_id ) : [];
		$is_last = ! isset( $posts[ self::BATCH_SIZE ] );
		$posts   = array_slice( $posts, 0, self::BATCH_SIZE );
		$errors  = [];

		// Each batch has its own, since the Algolia client skips the hosts that failed for a few minutes, which would fail the batches after it too.
		$search_index = new Search_Index();

		if ( [] !== $posts ) {
			$is_saved = $search_index->save_posts( $posts );

			if ( is_wp_error( $is_saved ) ) {
				$errors[] = sprintf(
					/* translators: 1: ID of the batch's first post. 2: ID of its last post. 3: error message. */
					__( 'Post IDs %1$d to %2$d: %3$s', 'onesearch' ),
					$posts[0]->ID,
					end( $posts )->ID,
					Search_Index::get_error_message( $is_saved )
				);
				Utils::log( sprintf( 'Algolia indexing error: %s', $errors[0] ) );
			}
		}

		$current = $progress->advance( $run_id, count( $posts ), [] !== $errors ? count( $posts ) : 0, $errors );

		// Started over while the batch was saved.
		if ( null === $current ) {
			return $errors;
		}

		if ( $is_last ) {
			// The records of posts that left the index, or of chunks a shorter post no longer has. A failed run keeps them, since they may still be the latest records of the posts it couldn't save.
			$is_deleted = [] === $current['errors'] ? $search_index->delete_stale_site_records( $current['started_at'] ) : true;

			return $this->finish( $run_id, is_wp_error( $is_deleted ) ? [ ...$errors, Search_Index::get_error_message( $is_deleted ) ] : $errors );
		}

		$last_post = $posts[ self::BATCH_SIZE - 1 ];

		if ( ! ( new Queue() )->add( self::BATCH_HOOK, [ $run_id, $last_post->ID ] ) ) {
			return $this->finish( $run_id, [ ...$errors, __( 'The next batch could not be queued.', 'onesearch' ) ] );
		}

		return $errors;
	}

	/**
	 * Finishes an indexing run.
	 *
	 * @param string   $run_id The ID of the indexing run.
	 * @param string[] $errors The errors of the batch that finishes it.
	 *
	 * @return string[] The errors.
	 */
	private function finish( string $run_id, array $errors = [] ): array {
		self::get_progress()->finish( $run_id, $errors );

		return $errors;
	}

	/**
	 * Whether a brand site's indexing run is going, and this site has told the brand site to start it.
	 *
	 * @param string                   $site_url The brand site's URL.
	 * @param array<string,mixed>|null $progress The brand site's progress, as this site last heard it.
	 * @phpstan-param ?ProgressData $progress
	 */
	private static function is_brand_site_running( string $site_url, ?array $progress ): bool {
		return null !== $progress && Progress::is_active( $progress ) && ! ( new Queue() )->has( self::BRAND_SITE_HOOK, [ $site_url ] );
	}

	/**
	 * Gets the progress of this site's indexing run.
	 */
	private static function get_progress(): Progress {
		return new Progress( self::OPTION_PROGRESS );
	}

	/**
	 * Gets the progress of a brand site's indexing run, as the governing site last heard it.
	 *
	 * @param string $site_url The brand site's URL.
	 */
	private static function get_brand_progress( string $site_url ): Progress {
		return new Progress( self::OPTION_BRAND_PROGRESS_PREFIX . md5( Utils::normalize_url( $site_url ) ) );
	}
}
