<?php
/**
 * Tests for the JobScheduler class.
 *
 * @package OneSearch\Tests\Unit\Modules\Scheduler
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Scheduler;

use OneSearch\Modules\Jobs\AbstractJob;
use OneSearch\Modules\Jobs\SyncJob;
use OneSearch\Modules\Scheduler\JobScheduler;
use OneSearch\Modules\Schema\JobRepository;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for JobScheduler.
 */
#[CoversClass( JobScheduler::class )]
class JobSchedulerTest extends TestCase {
	/**
	 * Scheduler instance used across tests.
	 *
	 * @var \OneSearch\Modules\Scheduler\JobScheduler
	 */
	private JobScheduler $scheduler;

	/**
	 * Repository for direct table assertions.
	 *
	 * @var \OneSearch\Modules\Schema\JobRepository
	 */
	private JobRepository $repository;

	/**
	 * Sets up the test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->scheduler  = new JobScheduler();
		$this->repository = new JobRepository();
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
		$this->assertSame( AbstractJob::STATUS_RUNNING, $s['status'] );
	}

	/**
	 * Verifies a completed job is stored in the custom table (not wp_options) and
	 * that its transient is removed.
	 */
	public function test_persist_terminal_job_uses_table(): void {
		$j = $this->make_job();
		$j->mark_completed();
		$this->scheduler->persist_job( $j );

		$key = JobScheduler::OPTION_PREFIX . $j->get_id();
		$this->assertFalse( get_transient( $key ) );

		$row = $this->repository->get_by_id( $j->get_id() );
		$this->assertIsArray( $row );
		$this->assertSame( AbstractJob::STATUS_COMPLETED, $row['status'] );
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
	 * Verifies active jobs appear in get_active_job_ids() after persist.
	 */
	public function test_active_job_appears_in_active_ids(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$this->assertContains( $j->get_id(), $this->scheduler->get_active_job_ids() );
	}

	/**
	 * Verifies a terminal job is removed from get_active_job_ids() after persist.
	 */
	public function test_terminal_persist_removes_from_active(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$this->assertContains( $j->get_id(), $this->scheduler->get_active_job_ids() );
		$j->mark_completed();
		$this->scheduler->persist_job( $j );
		$this->assertNotContains( $j->get_id(), $this->scheduler->get_active_job_ids() );
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
		$this->assertSame( AbstractJob::STATUS_PENDING, $this->scheduler->get_status( $j->get_id() )['status'] );
	}

	/**
	 * Verifies schedule() stores the Action Scheduler action ID in the custom table.
	 */
	public function test_schedule_stores_action_id_in_table(): void {
		$j = $this->make_job();
		$this->scheduler->schedule( $j );
		$action = $this->repository->get_action( $j->get_id() );
		$this->assertNotNull( $action );
		$this->assertGreaterThan( 0, $action['action_id'] );
	}

	/**
	 * Verifies cancel() sets the job status to 'cancelled'.
	 */
	public function test_cancel_marks_cancelled(): void {
		$j = $this->make_job();
		$this->scheduler->schedule( $j );
		$this->scheduler->cancel( $j->get_id() );
		$this->assertSame( AbstractJob::STATUS_CANCELLED, $this->scheduler->get_status( $j->get_id() )['status'] );
	}

	/**
	 * Verifies cancel() removes the job from the active list.
	 */
	public function test_cancel_removes_from_active(): void {
		$j = $this->make_job();
		$this->scheduler->schedule( $j );
		$this->scheduler->cancel( $j->get_id() );
		$this->assertNotContains( $j->get_id(), $this->scheduler->get_active_job_ids() );
	}

	/**
	 * Verifies get_jobs_by_group() returns jobs in the requested group.
	 */
	public function test_get_jobs_by_group_returns_jobs(): void {
		$j = $this->make_job();
		$j->set_group( 'grp_test' );
		$this->scheduler->schedule( $j );
		$jobs = $this->scheduler->get_jobs_by_group( 'grp_test' );
		$this->assertNotEmpty( $jobs );
		$this->assertContains( $j->get_id(), array_column( $jobs, 'id' ) );
	}

	/**
	 * Verifies get_jobs_by_group() returns an empty array for unknown groups.
	 */
	public function test_get_jobs_by_group_unknown_empty(): void {
		$this->assertSame( [], $this->scheduler->get_jobs_by_group( 'nonexistent_grp' ) );
	}

	/**
	 * Verifies persist_job() with skip_active_index still writes to the table.
	 */
	public function test_skip_active_index_still_persists_to_table(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j, true );
		$row = $this->repository->get_by_id( $j->get_id() );
		$this->assertNotNull( $row );
		$this->assertSame( AbstractJob::STATUS_RUNNING, $row['status'] );
	}

	/**
	 * Verifies set_progress_callback() returns the scheduler for chaining.
	 */
	public function test_progress_callback_is_chainable(): void {
		$result = $this->scheduler->set_progress_callback( static function (): void {} );
		$this->assertSame( $this->scheduler, $result );
	}

	/**
	 * Creates a SyncJob with a single post ID for testing.
	 */
	private function make_job(): SyncJob {
		$j = new SyncJob();
		$j->set_data( [ 'post_ids' => [ 42 ] ] );
		return $j;
	}
}
