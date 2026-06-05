<?php

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Scheduler;

use OneSearch\Modules\Jobs\AbstractJob;
use OneSearch\Modules\Jobs\SyncJob;
use OneSearch\Modules\Scheduler\JobScheduler;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( JobScheduler::class )]
class JobSchedulerTest extends TestCase {
	private JobScheduler $scheduler;

	public function set_up(): void {
		parent::set_up();

		$this->scheduler = new JobScheduler();
	}

	public function test_persist_and_get_status_for_active_job(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$s = $this->scheduler->get_status( $j->get_id() );
		$this->assertNotNull( $s );
		$this->assertSame( AbstractJob::STATUS_RUNNING, $s['status'] );
	}

	public function test_persist_terminal_job_uses_option(): void {
		$j = $this->make_job();
		$j->mark_completed();
		$this->scheduler->persist_job( $j );
		$key = JobScheduler::OPTION_PREFIX . $j->get_id();
		$this->assertFalse( get_transient( $key ) );
		$this->assertIsArray( get_option( $key ) );
	}

	public function test_get_status_null_for_unknown(): void {
		$this->assertNull( $this->scheduler->get_status( 'nonexistent' ) );
	}

	public function test_persist_updates_existing(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$j->set_progress_total( 10 );
		$j->set_progress( 5 );
		$this->scheduler->persist_job( $j );
		$this->assertSame( 5, $this->scheduler->get_status( $j->get_id() )['progress'] );
	}

	public function test_add_and_get_active_index(): void {
		$this->scheduler->add_to_active_index( 'job_a' );
		$this->assertContains( 'job_a', $this->scheduler->get_active_job_ids() );
	}

	public function test_add_prevents_duplicates(): void {
		$this->scheduler->add_to_active_index( 'job_x' );
		$this->scheduler->add_to_active_index( 'job_x' );
		$ids = $this->scheduler->get_active_job_ids();
		$this->assertSame( 1, count( array_filter( $ids, static fn ( $id ) => $id === 'job_x' ) ) );
	}

	public function test_add_many(): void {
		$this->scheduler->add_many_to_active_index( [ 'a', 'b', 'c' ] );
		$ids = $this->scheduler->get_active_job_ids();
		$this->assertContains( 'a', $ids );
		$this->assertContains( 'b', $ids );
		$this->assertContains( 'c', $ids );
	}

	public function test_add_many_empty_noop(): void {
		$this->scheduler->add_many_to_active_index( [] );
		$this->assertSame( [], $this->scheduler->get_active_job_ids() );
	}

	public function test_terminal_persist_removes_from_active(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$this->assertContains( $j->get_id(), $this->scheduler->get_active_job_ids() );
		$j->mark_completed();
		$this->scheduler->persist_job( $j );
		$this->assertNotContains( $j->get_id(), $this->scheduler->get_active_job_ids() );
	}

	public function test_terminal_jobs_include_completed(): void {
		$j = $this->make_job();
		$j->mark_completed();
		$this->scheduler->persist_job( $j );
		$this->assertContains( $j->get_id(), $this->scheduler->get_terminal_job_ids( 50 ) );
	}

	public function test_terminal_jobs_exclude_active(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j );
		$this->assertNotContains( $j->get_id(), $this->scheduler->get_terminal_job_ids( 50 ) );
	}

	public function test_schedule_returns_positive_action_id(): void {
		$j = $this->make_job();
		$this->assertGreaterThan( 0, $this->scheduler->schedule( $j ) );
	}

	public function test_schedule_sets_pending(): void {
		$j = $this->make_job();
		$this->scheduler->schedule( $j );
		$this->assertSame( AbstractJob::STATUS_PENDING, $this->scheduler->get_status( $j->get_id() )['status'] );
	}

	public function test_schedule_stores_action_id_option(): void {
		$j = $this->make_job();
		$this->scheduler->schedule( $j );
		$stored = get_option( JobScheduler::OPTION_PREFIX . $j->get_id() . '_action_id', 0 );
		$this->assertGreaterThan( 0, (int) $stored );
	}

	public function test_cancel_marks_cancelled(): void {
		$j = $this->make_job();
		$this->scheduler->schedule( $j );
		$this->scheduler->cancel( $j->get_id() );
		$this->assertSame( AbstractJob::STATUS_CANCELLED, $this->scheduler->get_status( $j->get_id() )['status'] );
	}

	public function test_cancel_removes_from_active(): void {
		$j = $this->make_job();
		$this->scheduler->schedule( $j );
		$this->scheduler->cancel( $j->get_id() );
		$this->assertNotContains( $j->get_id(), $this->scheduler->get_active_job_ids() );
	}

	public function test_get_jobs_by_group_returns_jobs(): void {
		$j = $this->make_job();
		$j->set_group( 'grp_test' );
		$this->scheduler->schedule( $j );
		$jobs = $this->scheduler->get_jobs_by_group( 'grp_test' );
		$this->assertNotEmpty( $jobs );
		$this->assertContains( $j->get_id(), array_column( $jobs, 'id' ) );
	}

	public function test_get_jobs_by_group_unknown_empty(): void {
		$this->assertSame( [], $this->scheduler->get_jobs_by_group( 'nonexistent_grp' ) );
	}

	public function test_schedule_skip_active_index_does_not_add(): void {
		$j = $this->make_job();
		$j->mark_running();
		$this->scheduler->persist_job( $j, true );
		$this->assertNotContains( $j->get_id(), $this->scheduler->get_active_job_ids() );
	}

	public function test_progress_callback_is_chainable(): void {
		$result = $this->scheduler->set_progress_callback( static function (): void {} );
		$this->assertSame( $this->scheduler, $result );
	}

	private function make_job(): SyncJob {
		$j = new SyncJob();
		$j->set_data( [ 'post_ids' => [ 42 ] ] );
		return $j;
	}
}
