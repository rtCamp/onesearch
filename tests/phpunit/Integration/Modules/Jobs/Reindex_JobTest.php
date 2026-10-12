<?php
/**
 * Tests for the Reindex_Job class.
 *
 * @package OneSearch\Tests\Integration\Modules\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Jobs;

use OneSearch\Modules\Jobs\Reindex_Job;
use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Reindex_Job.
 */
#[CoversClass( Reindex_Job::class )]
class Reindex_JobTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		as_unschedule_all_actions( Job_Scheduler::HOOK );

		$this->tear_down_governing_site();

		parent::tearDown();
	}

	/**
	 * The site's old records are cleared before batches are queued, so posts that are gone don't linger, and a re-run
	 * drops batches from an earlier attempt, which were cancelled when it failed and would keep the job from completing.
	 */
	public function test_handle_clears_the_site_records_and_queues_fresh_batches(): void {
		$this->set_up_governing_site();
		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$j = new Reindex_Job();
		$j->set_data( [ 'post_types' => [ 'post' ] ] );
		$j->set_child_ids( [ 'batch-from-an-earlier-attempt' ] );

		$j->handle();

		$this->assertSame(
			[ sprintf( 'site_url:"%s"', Utils::normalize_url( get_site_url() ) ) ],
			$this->get_delete_filters( $requests )
		);
		$this->assertNotContains( 'batch-from-an-earlier-attempt', $j->get_child_ids() );
		$this->assertCount( 1, $j->get_child_ids() );
	}

	/**
	 * A reindex that can't clear the site's records fails before queueing any batches.
	 */
	public function test_handle_throws_when_records_cannot_be_cleared(): void {
		$this->set_up_governing_site();
		$paths = [];
		$this->mock_algolia_http_client( $paths, null, '/deleteByQuery' );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$j = new Reindex_Job();
		$j->set_data( [ 'post_types' => [ 'post' ] ] );

		try {
			$j->handle();
			$this->fail( 'Expected the reindex to fail.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Failed to clear the site\'s Algolia records', $e->getMessage() );
			$this->assertStringContainsString( 'forced test exception', $e->getMessage(), 'Algolia\'s own message explains the failure.' );
		}

		$this->assertSame( [], $j->get_child_ids() );
	}
}
