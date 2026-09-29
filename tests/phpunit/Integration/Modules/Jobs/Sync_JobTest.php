<?php
/**
 * Sync_Job integration tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Jobs;

use OneSearch\Modules\Jobs\Sync_Job;
use OneSearch\Modules\Search\Post_Record;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Sync_Job class.
 */
#[CoversClass( Sync_Job::class )]
final class Sync_JobTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		$this->tear_down_governing_site();

		parent::tearDown();
	}

	/**
	 * A batch that drops a post has to delete it by the very same `site_post_id`
	 * the records were written with, otherwise Algolia matches nothing and still
	 * reports success.
	 *
	 * @see Post_Record::get_site_post_id()
	 */
	public function test_delete_filter_uses_the_stored_site_post_id(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->run_sync_job( [ $post_id ] );
		$stored_id = $this->get_indexed_site_post_id( $requests );

		// Drop the publish traffic, so only the delete request is left to assert on.
		$requests = [];

		$this->unpublish( $post_id );
		$this->run_sync_job( [ $post_id ] );

		$filters = $this->get_delete_filters( $requests );

		$this->assertSame(
			[ sprintf( 'site_post_id:"%s"', $stored_id ) ],
			$filters,
			'Syncing an unpublished post must delete its records by the stored site_post_id.'
		);

		/*
		 * $stored_id is read back from the write traffic, so a regression that broke
		 * the write and the filter in the same way would slip past the assertion
		 * above. The raw site URL is the shape that regression takes.
		 */
		$this->assertStringNotContainsString(
			Utils::normalize_url( get_site_url() ),
			$filters[0],
			'The raw site URL never appears in a stored site_post_id, so it must not be filtered on.'
		);
	}

	/**
	 * Several unindexable posts in one batch collapse into a single OR-joined filter.
	 */
	public function test_multiple_unpublished_posts_share_one_delete_filter(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		$post_ids = [
			self::factory()->post->create( [ 'post_status' => 'draft' ] ),
			self::factory()->post->create( [ 'post_status' => 'draft' ] ),
		];

		$this->run_sync_job( $post_ids );

		$post_record = new Post_Record();
		$expected    = sprintf(
			'site_post_id:"%s" OR site_post_id:"%s"',
			$post_record->get_site_post_id( $post_ids[0] ),
			$post_record->get_site_post_id( $post_ids[1] )
		);

		$this->assertSame(
			[ $expected ],
			$this->get_delete_filters( $requests ),
			'A batch of unindexable posts must be deleted with one OR-joined filter.'
		);
	}

	/**
	 * Every post in the batch has to count towards progress, whether it was saved,
	 * deleted, or skipped because it no longer exists.
	 */
	public function test_progress_counts_every_post_in_the_batch(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		$saved_id   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$deleted_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$missing_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_delete_post( $missing_id, true );

		$job = ( new Sync_Job() )->set_data( [ 'post_ids' => [ $saved_id, $deleted_id, $missing_id ] ] );
		$job->handle();

		$this->assertSame( 3, $job->get_progress_total(), 'progress_total must match the batch size.' );
		$this->assertSame( 3, $job->get_progress(), 'Every post in the batch must advance progress.' );
	}

	/**
	 * Runs a Sync_Job over a batch of posts, the way the scheduler would.
	 *
	 * @param int[] $post_ids The posts to sync.
	 */
	private function run_sync_job( array $post_ids ): void {
		( new Sync_Job() )->set_data( [ 'post_ids' => $post_ids ] )->handle();
	}

	/**
	 * Moves a post out of the indexable statuses.
	 *
	 * @param int $post_id The post to unpublish.
	 */
	private function unpublish( int $post_id ): void {
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);
	}
}
