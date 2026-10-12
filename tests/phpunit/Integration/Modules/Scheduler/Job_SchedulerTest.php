<?php
/**
 * Tests for the Job_Scheduler class.
 *
 * @package OneSearch\Tests\Integration\Modules\Scheduler
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Scheduler;

use OneSearch\Modules\Jobs\Abstract_Job;
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
	 * Creates a Sync_Job with a single post ID for testing.
	 */
	private function make_job(): Sync_Job {
		$j = new Sync_Job();
		$j->set_data( [ 'post_ids' => [ 42 ] ] );
		return $j;
	}
}
