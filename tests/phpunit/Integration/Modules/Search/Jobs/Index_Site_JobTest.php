<?php
/**
 * Index_Site_Job tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search\Jobs;

use OneSearch\Modules\Search\Index_Status;
use OneSearch\Modules\Search\Jobs\Index_Posts_Job;
use OneSearch\Modules\Search\Jobs\Index_Site_Job;
use OneSearch\Modules\Search\Jobs\Jobs;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the Index_Site_Job class.
 */
#[CoversClass( Index_Site_Job::class )]
#[CoversClass( Index_Posts_Job::class )]
final class Index_Site_JobTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		( new Jobs() )->register_hooks();
	}

	/**
	 * Each post is saved if it belongs in the index and deleted if it doesn't, as it is when the job runs, and the index records it. The site's other records stay.
	 */
	public function test_indexes_each_post_as_it_is_when_the_job_runs(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$this->add_algolia_record( 'other_post', Utils::get_current_site_url() );

		$published   = self::factory()->post->create();
		$unpublished = self::factory()->post->create();

		$this->index( [ $published, $unpublished ] );
		$this->run_jobs();
		$this->assertSame( [ $published, $unpublished ], $this->get_algolia_post_ids() );

		$this->index( [ $unpublished ] );
		wp_update_post(
			[
				'ID'          => $unpublished,
				'post_status' => 'draft',
			]
		);
		$this->run_jobs();

		$this->assertSame( [ $published ], $this->get_algolia_post_ids() );
		$this->assertArrayHasKey( 'other_post', $this->algolia_records );
		$this->assertNotEmpty( get_post_meta( $published, Index_Status::META_INDEXED_AT, true ) );
		$this->assertSame( '', get_post_meta( $unpublished, Index_Status::META_INDEXED_AT, true ) );
	}

	/**
	 * Indexing all of the site's posts replaces its records in children, and records when each post was indexed.
	 *
	 * The old records stay searchable until the last child, after which the ones it didn't save again are deleted.
	 */
	public function test_indexing_all_posts_replaces_the_site_records(): void {
		$this->set_up_governing_site( [ 'page' ] );
		$page_ids = self::factory()->post->create_many( 101, [ 'post_type' => 'page' ] );
		$post_id  = self::factory()->post->create();

		$this->fake_algolia_index();
		$this->add_algolia_record( 'gone_post', Utils::get_current_site_url() );
		$this->add_algolia_record( 'other_site_post', 'https://other.example.com/' );
		update_post_meta( $post_id, Index_Status::META_INDEXED_AT, time() - HOUR_IN_SECONDS );

		$this->index( null );
		$this->run_oldest_action();
		$this->assertSame( $this->progress( 101, 0, 0 ), $this->get_status()['progress'] );

		$this->run_oldest_action();
		$this->assertArrayHasKey( 'gone_post', $this->algolia_records, 'The old records stay while the run goes.' );
		$this->assertSame( $this->progress( 101, 100, 0 ), $this->get_status()['progress'] );

		$this->run_jobs();

		$this->assertSame( $page_ids, $this->get_algolia_post_ids() );
		$this->assertArrayNotHasKey( 'gone_post', $this->algolia_records );
		$this->assertArrayHasKey( 'other_site_post', $this->algolia_records, "Other sites' records are left alone." );

		$this->assertSame( 'completed', $this->get_status()['status'] );
		$this->assertSame( $this->progress( 101, 101, 0 ), $this->get_status()['progress'] );
		$this->assertNotEmpty( get_post_meta( $page_ids[100], Index_Status::META_INDEXED_AT, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Index_Status::META_INDEXED_AT, true ), 'A post that left the index is no longer indexed.' );
	}

	/**
	 * A post too big to index is counted as failed, keeps its error, and its old records go with the others the run didn't save.
	 */
	public function test_a_post_too_big_to_index_fails_on_its_own(): void {
		$this->set_up_governing_site();
		$post_id = self::factory()->post->create();
		$too_big = self::factory()->post->create();
		$this->make_too_big_to_index( $too_big );

		$this->fake_algolia_index();
		$this->algolia_records['old_chunk'] = [
			'objectID'   => 'old_chunk',
			'post_id'    => $too_big,
			'site_url'   => Utils::get_current_site_url(),
			'indexed_at' => time() - HOUR_IN_SECONDS,
		];
		update_post_meta( $too_big, Index_Status::META_INDEXED_AT, time() - HOUR_IN_SECONDS );

		$this->index( null );
		$this->run_jobs();

		$status = $this->get_status();
		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( $this->progress( 2, 2, 1 ), $status['progress'] );
		$this->assertSame( [ $post_id ], $this->get_algolia_post_ids() );
		$this->assertSame( 'The post is too big to index.', get_post_meta( $too_big, Index_Status::META_ERROR, true ) );
		$this->assertSame( '', get_post_meta( $too_big, Index_Status::META_INDEXED_AT, true ) );
	}

	/**
	 * A failed save fails the site's run with Algolia's reason, whether it was a run of all posts or of a list of them. It isn't about the posts, so they keep their old records and index times, which are still true, and get no error. A re-index tries again.
	 */
	public function test_a_failed_save_fails_the_sites_run(): void {
		$this->set_up_governing_site();
		$post_id    = self::factory()->post->create();
		$indexed_at = time() - HOUR_IN_SECONDS;
		update_post_meta( $post_id, Index_Status::META_INDEXED_AT, $indexed_at );

		$this->fake_algolia_index( '/batch' );
		$this->add_algolia_record( 'old_post', Utils::get_current_site_url() );
		$error = 'Failed to save records to Algolia index. forced test exception';

		foreach ( [ null, [ $post_id ] ] as $post_ids ) {
			$this->index( $post_ids );
			$this->run_jobs();

			$this->assertSame( [ 'failed', $error ], [ $this->get_status()['status'], $this->get_status()['error'] ] );
			$this->assertSame( [], Index_Status::get_errors() );
			$this->assertArrayHasKey( 'old_post', $this->algolia_records );
			$this->assertSame( (string) $indexed_at, get_post_meta( $post_id, Index_Status::META_INDEXED_AT, true ) );
		}

		$this->fake_algolia_index();
		$this->index( null );
		$this->run_jobs();

		$this->assertSame( 'completed', $this->get_status()['status'] );
		$this->assertSame( [ $post_id ], $this->get_algolia_post_ids() );
	}

	/**
	 * Indexing all posts fails when the stale records can't be deleted, and they stay.
	 */
	public function test_indexing_all_posts_fails_when_the_stale_records_cannot_be_deleted(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index( '/deleteByQuery' );
		$this->add_algolia_record( 'old_post', Utils::get_current_site_url() );

		$this->index( null );
		$this->run_jobs();

		$this->assertSame( 'failed', $this->get_status()['status'] );
		$this->assertArrayHasKey( 'old_post', $this->algolia_records );
	}

	/**
	 * Indexing all posts saves each post as it is when its child runs.
	 */
	public function test_indexing_all_posts_saves_each_post_as_it_is_when_its_child_runs(): void {
		$this->set_up_governing_site();
		$post_ids = self::factory()->post->create_many( 110 );
		$this->fake_algolia_index();

		$this->index( null );
		$this->run_oldest_action();

		foreach ( array_slice( $post_ids, 0, 10 ) as $post_id ) {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => 'draft',
				]
			);
		}

		$this->run_jobs();

		$this->assertSame( array_slice( $post_ids, 10 ), $this->get_algolia_post_ids() );
	}

	/**
	 * Posts changed while all posts are being indexed are indexed in a run of their own, which leaves the other run going and its counts alone.
	 */
	public function test_posts_changed_while_all_posts_are_indexed_have_their_own_run(): void {
		$this->set_up_governing_site();
		$post_ids = self::factory()->post->create_many( 101 );
		$this->fake_algolia_index();

		$this->index( null );
		$this->run_oldest_action();

		$new_post = self::factory()->post->create();
		$this->index( [ $new_post ] );
		$this->run_jobs();

		$this->assertSame( 'completed', $this->get_status()['status'] );
		$this->assertSame( $this->progress( 101, 101, 0 ), $this->get_status()['progress'] );
		$this->assertSame( [ ...$post_ids, $new_post ], $this->get_algolia_post_ids() );
		$this->assertCount( 1, $this->get_statuses_of( 'index_site' ), 'Only the run of all posts shows, since the posts that changed show in the index summary.' );
	}

	/**
	 * Indexing all posts starts over. The children of the run it replaced report to nothing.
	 */
	public function test_indexing_all_posts_starts_over(): void {
		$this->set_up_governing_site();
		$post_ids = self::factory()->post->create_many( 101 );
		$this->fake_algolia_index();

		$this->index( null );
		$this->run_oldest_action();

		// It starts over while the first child builds its records.
		$restarted = false;
		add_filter(
			'onesearch_algolia_base_record_data',
			function ( array $record ) use ( &$restarted ): array {
				if ( ! $restarted ) {
					$restarted = true;
					$this->index( null );
				}

				return $record;
			}
		);
		$this->run_oldest_action();

		$this->assertSame( 'queued', $this->get_status()['status'] );
		$this->assertArrayNotHasKey( 'progress', $this->get_status() );

		// The replaced run's other child is next in the queue, and saves nothing.
		$this->run_oldest_action();
		$this->assertSame( array_slice( $post_ids, 0, 100 ), $this->get_algolia_post_ids() );

		$this->run_jobs();

		$this->assertSame( 'completed', $this->get_status()['status'] );
		$this->assertSame( $this->progress( 101, 101, 0 ), $this->get_status()['progress'] );
		$this->assertSame( $post_ids, $this->get_algolia_post_ids() );
	}

	/**
	 * Each list of posts gets a run of its own, so a newer one doesn't replace the one before it. Neither leaves a record behind.
	 */
	public function test_each_list_of_posts_gets_its_own_run(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$first  = self::factory()->post->create();
		$second = self::factory()->post->create();
		$before = $this->count_options();

		$this->index( [ $first ] );
		$this->index( [ $second ] );
		$this->run_jobs();

		$this->assertSame( [ $first, $second ], $this->get_algolia_post_ids() );
		$this->assertSame( $before, $this->count_options() );
	}

	/**
	 * A run waits for its other children after one fails, then fails with why. The failed child's posts get no error, and the site's old records stay, since the run didn't save every post again.
	 */
	public function test_a_run_waits_for_every_child_after_one_fails(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$this->add_algolia_record( 'old_post', Utils::get_current_site_url() );
		$post_ids = self::factory()->post->create_many( 201 );

		$this->index( null );
		$this->run_oldest_action();

		$children = as_get_scheduled_actions(
			[
				'group'   => 'onesearch',
				'status'  => \ActionScheduler_Store::STATUS_PENDING,
				'orderby' => 'action_id',
				'order'   => 'ASC',
			],
			'ids'
		);
		\ActionScheduler::store()->delete_action( (string) $children[1] );

		$this->assertSame( 'running', $this->get_status()['status'] );
		$this->assertSame( $this->progress( 201, 0, 0 ), $this->get_status()['progress'] );

		$this->run_jobs();

		$this->assertSame( [ 'failed', 'The job was deleted before it finished.' ], [ $this->get_status()['status'], $this->get_status()['error'] ] );
		$this->assertSame( $this->progress( 201, 101, 0 ), $this->get_status()['progress'] );
		$this->assertSame( array_values( array_diff( $post_ids, array_slice( $post_ids, 100, 100 ) ) ), $this->get_algolia_post_ids() );
		$this->assertSame( [], Index_Status::get_errors() );
		$this->assertArrayHasKey( 'old_post', $this->algolia_records );
	}

	/**
	 * A post that leaves search while all posts are being indexed, and whose records couldn't be deleted then, fails the site's run, and the next re-index deletes them.
	 *
	 * @param string $change How the post leaves search.
	 */
	#[DataProvider( 'provide_ways_to_leave_search' )]
	public function test_a_post_whose_records_could_not_be_deleted_during_the_run_fails_it( string $change ): void {
		$this->set_up_governing_site();
		$post_ids = self::factory()->post->create_many( 101 );
		$post_id  = min( $post_ids );
		$this->fake_algolia_index();

		$this->index( null );
		$this->run_oldest_action();
		$this->run_oldest_action();

		// Algolia is down when the post leaves search.
		$this->fake_algolia_index( '/deleteByQuery' );
		if ( 'deleted' === $change ) {
			wp_delete_post( $post_id, true );
		} else {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => 'draft',
				]
			);
		}

		// The run of the post and its child go before the rest of the run of all posts.
		$this->index( [ $post_id ] );
		$this->run_newest_action();
		$this->run_newest_action();
		$this->assertSame( 'failed', $this->get_status()['status'] );
		$this->assertStringStartsWith( 'Failed to delete', (string) $this->get_status()['error'] );
		$this->assertSame( [], Index_Status::get_errors() );

		$this->fake_algolia_index();
		$this->run_jobs();
		$this->assertContains( $post_id, $this->get_algolia_post_ids() );

		// Saved before the re-index below starts.
		foreach ( $this->algolia_records as &$record ) {
			$record['indexed_at'] -= HOUR_IN_SECONDS;
		}
		unset( $record );

		$this->index( null );
		$this->run_jobs();

		$this->assertSame( 'completed', $this->get_status()['status'] );
		$this->assertNotContains( $post_id, $this->get_algolia_post_ids() );
	}

	/**
	 * Provides the ways a post leaves search.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_ways_to_leave_search(): array {
		return [
			'unpublished' => [ 'unpublished' ],
			'deleted'     => [ 'deleted' ],
		];
	}

	/**
	 * A site that indexes no post types deletes its records when all its posts are indexed.
	 */
	public function test_a_site_that_indexes_nothing_deletes_its_records(): void {
		$this->set_up_governing_site( [] );
		self::factory()->post->create();
		$this->fake_algolia_index();
		$this->add_algolia_record( 'old_post', Utils::get_current_site_url() );

		$this->index( null );
		$this->run_jobs();

		$this->assertSame( 'completed', $this->get_status()['status'] );
		$this->assertSame( [], $this->algolia_records );
	}

	/**
	 * Records that couldn't be deleted, of posts that were unpublished or deleted, fail the site's run rather than the posts, and stay in search until a re-index deletes them.
	 */
	public function test_records_that_could_not_be_deleted_fail_the_sites_run(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$unpublished = self::factory()->post->create( [ 'post_title' => 'Unpublished' ] );
		$deleted     = self::factory()->post->create();

		$this->index( [ $unpublished, $deleted ] );
		$this->run_jobs();

		// Saved before the re-index below starts.
		foreach ( $this->algolia_records as &$record ) {
			$record['indexed_at'] -= HOUR_IN_SECONDS;
		}
		unset( $record );

		$this->fake_algolia_index( '/deleteByQuery' );
		wp_update_post(
			[
				'ID'          => $unpublished,
				'post_status' => 'draft',
			]
		);
		wp_delete_post( $deleted, true );
		$this->index( [ $unpublished, $deleted ] );
		$this->run_jobs();

		$this->assertSame( 'failed', $this->get_status()['status'] );
		$this->assertStringStartsWith( 'Failed to delete', (string) $this->get_status()['error'] );
		$this->assertSame( [], Index_Status::get_errors() );
		$this->assertSame( [ $unpublished, $deleted ], $this->get_algolia_post_ids() );

		$this->fake_algolia_index();
		$this->index( null );
		$this->run_jobs();

		$this->assertSame( 'completed', $this->get_status()['status'] );
		$this->assertSame( [], $this->get_algolia_post_ids() );
	}

	/**
	 * Permanently deleting a post that couldn't be indexed takes it out of the failed posts once its records are deleted.
	 */
	public function test_deleting_a_post_that_failed_clears_its_error(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$post_id = self::factory()->post->create();
		$this->make_too_big_to_index( $post_id );

		$this->index( [ $post_id ] );
		$this->run_jobs();
		$this->assertSame( [ $post_id ], array_column( Index_Status::get_summary( [ 'post' ] )['failed'], 'id' ) );

		wp_delete_post( $post_id, true );
		$this->index( [ $post_id ] );
		$this->run_jobs();

		$this->assertSame( [], Index_Status::get_summary( [ 'post' ] )['failed'] );
	}

	/**
	 * A run of a list of posts fails the site's run without an index to update, or when the post types can't be fetched, even before the site's posts were ever all indexed. The posts get no error.
	 */
	public function test_a_list_of_posts_that_cannot_reach_the_index_fails_the_sites_run(): void {
		$this->set_up_governing_site();
		delete_option( \OneSearch\Modules\Search\Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		$post_id = self::factory()->post->create();

		$this->index( [ $post_id ] );
		$this->run_jobs();

		$this->assertSame( [ 'failed', 'Algolia admin credentials missing.' ], [ $this->get_status()['status'], $this->get_status()['error'] ] );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$this->index( [ $post_id ] );
		$this->run_jobs();

		$this->assertSame( [ 'failed', 'No governing site is configured.' ], [ $this->get_status()['status'], $this->get_status()['error'] ] );
		$this->assertSame( [], Index_Status::get_errors() );
	}

	/**
	 * Queues this site's posts to index, or all of them.
	 *
	 * @param ?int[] $post_ids The post IDs, or null for all.
	 */
	private function index( ?array $post_ids ): void {
		Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ Utils::get_current_site_url(), $post_ids ] );
	}

	/**
	 * Gets the status the job reports for the run of all of this site's posts.
	 *
	 * @return array<string, mixed>
	 */
	private function get_status(): array {
		$reported = $this->get_statuses_of( 'index_site' );
		$this->assertCount( 1, $reported );

		$status = [
			'status' => $reported[0]['status'],
			'error'  => $reported[0]['error'],
		];

		$data = $reported[0]['data'];

		if ( isset( $data['done'] ) ) {
			$status['progress'] = $this->progress( (int) $data['total'], (int) $data['done'], (int) $data['failed'] );
		}

		return $status;
	}

	/**
	 * Builds an expected progress.
	 *
	 * @param int $total  The total.
	 * @param int $done   The posts done.
	 * @param int $failed The posts failed.
	 *
	 * @return array{ total: int, done: int, failed: int }
	 */
	private function progress( int $total, int $done, int $failed ): array {
		return [
			'total'  => $total,
			'done'   => $done,
			'failed' => $failed,
		];
	}

	/**
	 * Counts the site's options, which hold the job records.
	 */
	private function count_options(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts every option, including the ones not cached.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
	}
}
