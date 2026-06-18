<?php
/**
 * Tests for the Sync_Job class.
 *
 * @package OneSearch\Tests\Unit\Modules\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Jobs;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Jobs\Sync_Job;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Sync_Job.
 */
#[CoversClass( Sync_Job::class )]
class Sync_Job_Test extends TestCase {
	/**
	 * Verifies get_type() returns 'sync'.
	 */
	public function test_get_type_returns_sync(): void {
		$this->assertSame( 'sync', Sync_Job::get_type() );
	}

	/**
	 * Verifies Sync_Job extends Abstract_Job.
	 */
	public function test_extends_abstract_job(): void {
		$this->assertInstanceOf( Abstract_Job::class, new Sync_Job() );
	}

	/**
	 * Verifies the default job group is 'sync'.
	 */
	public function test_default_group(): void {
		$this->assertSame( 'sync', ( new Sync_Job() )->get_group() );
	}

	/**
	 * Verifies the default progress total is 1.
	 */
	public function test_default_progress_total(): void {
		$this->assertSame( 1, ( new Sync_Job() )->get_progress_total() );
	}

	/**
	 * Verifies the default max retries is 3.
	 */
	public function test_default_max_retries(): void {
		$this->assertSame( 3, ( new Sync_Job() )->get_max_retries() );
	}

	/**
	 * Verifies the default retry delay is 30 seconds.
	 */
	public function test_default_retry_delay(): void {
		$this->assertSame( 30, ( new Sync_Job() )->get_retry_delay_seconds() );
	}

	/**
	 * Verifies the initial status is 'pending'.
	 */
	public function test_initial_status_is_pending(): void {
		$this->assertSame( Abstract_Job::STATUS_PENDING, ( new Sync_Job() )->get_status() );
	}

	/**
	 * Verifies the job ID is a non-empty UUID string.
	 */
	public function test_id_prefix(): void {
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
			( new Sync_Job() )->get_id()
		);
	}

	/**
	 * Verifies set_data() stores post_ids correctly.
	 */
	public function test_set_data_post_ids(): void {
		$j = new Sync_Job();
		$j->set_data( [ 'post_ids' => [ 1, 2, 3 ] ] );
		$this->assertSame( [ 1, 2, 3 ], $j->get_data()['post_ids'] );
	}

	/**
	 * Verifies handle() throws without post_ids.
	 */
	public function test_handle_throws_without_post_ids(): void {
		$j = new Sync_Job();
		$this->expectException( \InvalidArgumentException::class );
		$j->handle();
	}
}
