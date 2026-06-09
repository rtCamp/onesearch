<?php
/**
 * Tests for the SyncJob class.
 *
 * @package OneSearch\Tests\Unit\Modules\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Jobs;

use OneSearch\Modules\Jobs\AbstractJob;
use OneSearch\Modules\Jobs\SyncJob;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for SyncJob.
 */
#[CoversClass( SyncJob::class )]
class SyncJobTest extends TestCase {
	/**
	 * Verifies get_type() returns 'sync'.
	 */
	public function test_get_type_returns_sync(): void {
		$this->assertSame( 'sync', SyncJob::get_type() );
	}

	/**
	 * Verifies SyncJob extends AbstractJob.
	 */
	public function test_extends_abstract_job(): void {
		$this->assertInstanceOf( AbstractJob::class, new SyncJob() );
	}

	/**
	 * Verifies the default job group is 'sync'.
	 */
	public function test_default_group(): void {
		$this->assertSame( 'sync', ( new SyncJob() )->get_group() );
	}

	/**
	 * Verifies the default progress total is 1.
	 */
	public function test_default_progress_total(): void {
		$this->assertSame( 1, ( new SyncJob() )->get_progress_total() );
	}

	/**
	 * Verifies the default max retries is 3.
	 */
	public function test_default_max_retries(): void {
		$this->assertSame( 3, ( new SyncJob() )->get_max_retries() );
	}

	/**
	 * Verifies the default retry delay is 30 seconds.
	 */
	public function test_default_retry_delay(): void {
		$this->assertSame( 30, ( new SyncJob() )->get_retry_delay_seconds() );
	}

	/**
	 * Verifies the initial status is 'pending'.
	 */
	public function test_initial_status_is_pending(): void {
		$this->assertSame( AbstractJob::STATUS_PENDING, ( new SyncJob() )->get_status() );
	}

	/**
	 * Verifies the job ID starts with 'job_'.
	 */
	public function test_id_prefix(): void {
		$this->assertStringStartsWith( 'job_', ( new SyncJob() )->get_id() );
	}

	/**
	 * Verifies set_data() stores post_ids correctly.
	 */
	public function test_set_data_post_ids(): void {
		$j = new SyncJob();
		$j->set_data( [ 'post_ids' => [ 1, 2, 3 ] ] );
		$this->assertSame( [ 1, 2, 3 ], $j->get_data()['post_ids'] );
	}

	/**
	 * Verifies handle() throws without post_ids.
	 */
	public function test_handle_throws_without_post_ids(): void {
		$j = new SyncJob();
		$this->expectException( \InvalidArgumentException::class );
		$j->handle();
	}
}
