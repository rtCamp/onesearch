<?php

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Jobs;

use OneSearch\Modules\Jobs\AbstractJob;
use OneSearch\Modules\Jobs\SyncJob;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SyncJob::class )]
class SyncJobTest extends TestCase {
	public function test_get_type_returns_sync(): void {
		$this->assertSame( 'sync', SyncJob::get_type() );
	}

	public function test_extends_abstract_job(): void {
		$this->assertInstanceOf( AbstractJob::class, new SyncJob() );
	}

	public function test_default_group(): void {
		$this->assertSame( 'sync', ( new SyncJob() )->get_group() );
	}

	public function test_default_progress_total(): void {
		$this->assertSame( 1, ( new SyncJob() )->get_progress_total() );
	}

	public function test_default_max_retries(): void {
		$this->assertSame( 3, ( new SyncJob() )->get_max_retries() );
	}

	public function test_default_retry_delay(): void {
		$this->assertSame( 30, ( new SyncJob() )->get_retry_delay_seconds() );
	}

	public function test_initial_status_is_pending(): void {
		$this->assertSame( AbstractJob::STATUS_PENDING, ( new SyncJob() )->get_status() );
	}

	public function test_id_prefix(): void {
		$this->assertStringStartsWith( 'job_', ( new SyncJob() )->get_id() );
	}

	public function test_set_data_post_ids(): void {
		$j = new SyncJob();
		$j->set_data( [ 'post_ids' => [ 1, 2, 3 ] ] );
		$this->assertSame( [ 1, 2, 3 ], $j->get_data()['post_ids'] );
	}

	public function test_handle_throws_without_post_ids(): void {
		$j = new SyncJob();
		$this->expectException( \InvalidArgumentException::class );
		$j->handle();
	}
}
