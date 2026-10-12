<?php
/**
 * Tests for the Reindex_Job class.
 *
 * @package OneSearch\Tests\Integration\Modules\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Jobs;

use OneSearch\Modules\Jobs\Abstract_Job;
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
	 * Verifies get_type() returns 'reindex'.
	 */
	public function test_get_type_returns_reindex(): void {
		$this->assertSame( 'reindex', Reindex_Job::get_type() );
	}

	/**
	 * Verifies Reindex_Job extends Abstract_Job.
	 */
	public function test_extends_abstract_job(): void {
		$this->assertInstanceOf( Abstract_Job::class, new Reindex_Job() );
	}

	/**
	 * Verifies the default job group is 'reindex'.
	 */
	public function test_default_group(): void {
		$this->assertSame( 'reindex', ( new Reindex_Job() )->get_group() );
	}

	/**
	 * Verifies the default progress total is 1.
	 */
	public function test_default_progress_total(): void {
		$this->assertSame( 1, ( new Reindex_Job() )->get_progress_total() );
	}

	/**
	 * Verifies the default max retries is 2.
	 */
	public function test_default_max_retries(): void {
		$this->assertSame( 2, ( new Reindex_Job() )->get_max_retries() );
	}

	/**
	 * Verifies the default retry delay is 60 seconds.
	 */
	public function test_default_retry_delay(): void {
		$this->assertSame( 60, ( new Reindex_Job() )->get_retry_delay_seconds() );
	}

	/**
	 * Verifies the job ID is a non-empty UUID string.
	 */
	public function test_id_prefix(): void {
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
			( new Reindex_Job() )->get_id()
		);
	}

	/**
	 * Verifies set_data() stores post_types and batch_size correctly.
	 */
	public function test_set_data_post_types(): void {
		$j = new Reindex_Job();
		$j->set_data(
			[
				'post_types' => [ 'post', 'page' ],
				'batch_size' => 50,
			]
		);
		$d = $j->get_data();
		$this->assertSame( [ 'post', 'page' ], $d['post_types'] );
		$this->assertSame( 50, $d['batch_size'] );
	}

	/**
	 * Verifies a re-run drops batches from an earlier attempt, which were cancelled
	 * when it failed and would otherwise keep the job from ever completing.
	 */
	public function test_handle_starts_with_a_fresh_batch_list(): void {
		$this->set_up_governing_site();
		$paths = [];
		$this->mock_algolia_http_client( $paths );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$j = new Reindex_Job();
		$j->set_data( [ 'post_types' => [ 'post' ] ] );
		$j->set_child_ids( [ 'batch-from-an-earlier-attempt' ] );

		$j->handle();

		$this->assertNotContains( 'batch-from-an-earlier-attempt', $j->get_child_ids() );
		$this->assertCount( 1, $j->get_child_ids() );
	}

	/**
	 * The site's old records are cleared before any batch is queued, so posts that are gone don't linger.
	 */
	public function test_handle_clears_the_site_records(): void {
		$this->set_up_governing_site();
		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$j = new Reindex_Job();
		$j->set_data( [ 'post_types' => [ 'post' ] ] );
		$j->handle();

		$this->assertSame(
			[ sprintf( 'site_url:"%s"', Utils::normalize_url( get_site_url() ) ) ],
			$this->get_delete_filters( $requests )
		);
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
