<?php
/**
 * Tests for the Job_Scheduler class.
 *
 * @package OneSearch\Tests\Integration\Modules\Scheduler
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Scheduler;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Jobs\Reindex_Job;
use OneSearch\Modules\Jobs\Sync_Job;
use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Modules\Schema\Job_Repository;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Job_Scheduler.
 */
#[CoversClass( Job_Scheduler::class )]
class Job_SchedulerTest extends TestCase {
	/**
	 * Scheduler instance used across tests.
	 *
	 * @var \OneSearch\Modules\Scheduler\Job_Scheduler
	 */
	private Job_Scheduler $scheduler;

	/**
	 * Repository for direct table assertions.
	 *
	 * @var \OneSearch\Modules\Schema\Job_Repository
	 */
	private Job_Repository $repository;

	/**
	 * Sets up the test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->scheduler  = new Job_Scheduler();
		$this->repository = new Job_Repository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		$this->tear_down_governing_site();

		parent::tear_down();
	}

	/**
	 * Verifies persist_job() and get_status() for an active (running) job.
	 */
	public function test_persist_and_get_status_for_active_job(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$s = $this->scheduler->get_status( $j->get_id() );
		$this->assertNotNull( $s );
		$this->assertSame( Abstract_Job::STATUS_RUNNING, $s['status'] );
	}

	/**
	 * Verifies a completed job is stored in the custom table (not wp_options) and
	 * that its transient is removed.
	 */
	public function test_persist_terminal_job_uses_table(): void {
		$j = $this->make_job();
		$j->mark_completed();
		$this->scheduler->persist_job( $j );

		$key = Job_Scheduler::OPTION_PREFIX . $j->get_id();
		$this->assertFalse( get_transient( $key ) );

		$row = $this->repository->get_by_id( $j->get_id() );
		$this->assertIsArray( $row );
		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $row['status'] );
	}

	/**
	 * Verifies get_status() returns null for unknown job IDs.
	 */
	public function test_get_status_null_for_unknown(): void {
		$this->assertNull( $this->scheduler->get_status( 'nonexistent' ) );
	}

	/**
	 * Verifies persist_job() updates an existing job's state.
	 */
	public function test_persist_updates_existing(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$j->set_progress_total( 10 );
		$j->set_progress( 5 );
		$this->scheduler->persist_job( $j );
		$this->assertSame( 5, $this->scheduler->get_status( $j->get_id() )['progress'] );
	}

	/**
	 * Verifies completed jobs appear in the repository's terminal list.
	 */
	public function test_terminal_jobs_include_completed(): void {
		$j = $this->make_job();
		$j->mark_completed();
		$this->scheduler->persist_job( $j );
		$rows = $this->repository->get_terminal_jobs( 1, 50 );
		$this->assertContains( $j->get_id(), array_column( $rows, 'id' ) );
	}

	/**
	 * Verifies active (running) jobs are excluded from the terminal list.
	 */
	public function test_terminal_jobs_exclude_active(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$rows = $this->repository->get_terminal_jobs( 1, 50 );
		$this->assertNotContains( $j->get_id(), array_column( $rows, 'id' ) );
	}

	/**
	 * Verifies schedule() returns a positive Action Scheduler action ID.
	 */
	public function test_schedule_returns_positive_action_id(): void {
		$j = $this->make_job();
		$this->assertGreaterThan( 0, $this->scheduler->schedule( $j ) );
	}

	/**
	 * Verifies a scheduled job starts in 'pending' status.
	 */
	public function test_schedule_sets_pending(): void {
		$j = $this->make_job();
		$this->scheduler->schedule( $j );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $this->scheduler->get_status( $j->get_id() )['status'] );
	}

	/**
	 * Verifies cancel() sets the job status to 'cancelled'.
	 */
	public function test_cancel_marks_cancelled(): void {
		$j = $this->make_job();
		$this->scheduler->schedule( $j );
		$this->scheduler->cancel( $j->get_id() );
		$this->assertSame( Abstract_Job::STATUS_CANCELLED, $this->scheduler->get_status( $j->get_id() )['status'] );
	}

	/**
	 * Verifies persist_job() writes an active job to the table, not just the transient.
	 */
	public function test_persist_writes_active_job_to_table(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$row = $this->repository->get_by_id( $j->get_id() );
		$this->assertNotNull( $row );
		$this->assertSame( Abstract_Job::STATUS_RUNNING, $row['status'] );
	}

	/**
	 * A reindex completes once every batch has run, its batches cover every post exactly once,
	 * and a late failing run of a batch can't reopen it.
	 */
	public function test_reindex_completes_once_every_batch_completes(): void {
		$this->set_up_governing_site();
		$paths = [];
		$this->mock_algolia_http_client( $paths );

		$post_ids = self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		$parent_id = $this->start_reindex( 2 );
		$batch_ids = $this->scheduler->get_status( $parent_id )['child_ids'];

		$this->assertCount( 2, $batch_ids, '3 posts at 2 per batch make 2 batches.' );
		$this->assertEqualsCanonicalizing( $post_ids, $this->get_batched_post_ids( $batch_ids ) );

		foreach ( $batch_ids as $batch_id ) {
			$this->scheduler->execute_job( Sync_Job::class, $batch_id );
		}

		$parent = $this->scheduler->get_status( $parent_id );
		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $parent['status'] );
		$this->assertSame( 2, $parent['children_completed'] );

		// Action Scheduler can run an action twice; a failure on the second run must not touch the finished parent.
		$this->mock_algolia_http_client( $paths, null, '/batch' );
		$this->scheduler->execute_job( Sync_Job::class, $batch_ids[0], 2 );

		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $this->scheduler->get_status( $parent_id )['status'] );
	}

	/**
	 * A batch that runs out of retries fails the reindex.
	 */
	public function test_reindex_fails_when_a_batch_runs_out_of_retries(): void {
		$this->set_up_governing_site();
		$paths = [];
		$this->mock_algolia_http_client( $paths );

		self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		$parent_id                  = $this->start_reindex( 2 );
		[ $good_batch, $bad_batch ] = $this->scheduler->get_status( $parent_id )['child_ids'];

		$this->scheduler->execute_job( Sync_Job::class, $good_batch );

		// Reindex_Job gives each batch 2 retries, so retry 2 is its last attempt.
		$this->mock_algolia_http_client( $paths, null, '/batch' );
		$this->scheduler->execute_job( Sync_Job::class, $bad_batch, 2 );

		$batch = $this->scheduler->get_status( $bad_batch );
		$this->assertSame( Abstract_Job::STATUS_FAILED, $batch['status'] );
		$this->assertStringContainsString( 'retries exhausted', (string) $batch['error'] );

		$parent = $this->scheduler->get_status( $parent_id );
		$this->assertSame( Abstract_Job::STATUS_FAILED, $parent['status'] );
		$this->assertSame( '1/2 child batches failed', $parent['error'] );
	}

	/**
	 * An Algolia failure with retries left puts the job back to pending with the next attempt queued.
	 */
	public function test_algolia_error_leaves_job_pending_with_a_retry_scheduled(): void {
		$this->set_up_governing_site();
		$paths = [];
		$this->mock_algolia_http_client( $paths, null, '/batch' );

		$job = new Sync_Job();
		$job->set_data( [ 'post_ids' => [ self::factory()->post->create( [ 'post_status' => 'publish' ] ) ] ] );
		$job->set_max_retries( 2 );
		$this->scheduler->persist_job( $job );

		$this->scheduler->execute_job( Sync_Job::class, $job->get_id() );

		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $status['status'] );
		$this->assertSame( 1, $status['retry_count'] );
		$this->assertNotFalse(
			as_next_scheduled_action(
				Job_Scheduler::HOOK,
				[
					'job_class' => Sync_Job::class,
					'job_id'    => $job->get_id(),
					'retry'     => 1,
				],
				'onesearch_' . $job->get_group()
			),
			'The first retry has to be queued.'
		);
	}

	/**
	 * Cancelling a running reindex cancels and dequeues its unfinished batches, and leaves finished ones alone.
	 */
	public function test_cancelling_a_running_reindex_cancels_only_unfinished_batches(): void {
		$this->set_up_governing_site();
		$paths = [];
		$this->mock_algolia_http_client( $paths );

		self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		$parent_id                      = $this->start_reindex( 2 );
		[ $done_batch, $pending_batch ] = $this->scheduler->get_status( $parent_id )['child_ids'];
		$pending_action                 = [
			'job_class' => Sync_Job::class,
			'job_id'    => $pending_batch,
		];
		$batch_group                    = 'onesearch_reindex_' . $parent_id;

		$this->scheduler->execute_job( Sync_Job::class, $done_batch );
		$this->assertNotFalse( as_next_scheduled_action( Job_Scheduler::HOOK, $pending_action, $batch_group ) );

		$this->scheduler->cancel( $parent_id );

		$this->assertSame( Abstract_Job::STATUS_CANCELLED, $this->scheduler->get_status( $parent_id )['status'] );
		$this->assertSame( Abstract_Job::STATUS_CANCELLED, $this->scheduler->get_status( $pending_batch )['status'] );
		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $this->scheduler->get_status( $done_batch )['status'] );
		$this->assertFalse(
			as_next_scheduled_action( Job_Scheduler::HOOK, $pending_action, $batch_group ),
			'The unfinished batch must not stay queued.'
		);
	}

	/**
	 * Starts a reindex of all posts and returns its job ID.
	 *
	 * @param int $batch_size Posts per batch.
	 */
	private function start_reindex( int $batch_size ): string {
		$parent = new Reindex_Job();
		$parent->set_data(
			[
				'post_types' => [ 'post' ],
				'batch_size' => $batch_size,
			]
		);
		$this->scheduler->persist_job( $parent );

		$this->scheduler->execute_job( Reindex_Job::class, $parent->get_id() );

		return $parent->get_id();
	}

	/**
	 * Collects the post IDs across a reindex's batches.
	 *
	 * @param string[] $batch_ids The batch job IDs.
	 *
	 * @return int[]
	 */
	private function get_batched_post_ids( array $batch_ids ): array {
		$post_ids = [];

		foreach ( $batch_ids as $batch_id ) {
			$post_ids = array_merge( $post_ids, $this->scheduler->get_status( $batch_id )['data']['post_ids'] );
		}

		return $post_ids;
	}

	/**
	 * Creates a Sync_Job with a single post ID for testing.
	 */
	private function make_job(): Sync_Job {
		$j = new Sync_Job();
		$j->set_data( [ 'post_ids' => [ 42 ] ] );
		return $j;
	}
}
