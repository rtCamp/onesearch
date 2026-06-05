<?php

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Jobs;

use OneSearch\Modules\Jobs\AbstractJob;
use OneSearch\Modules\Jobs\ReindexJob;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ReindexJob::class )]
class ReindexJobTest extends TestCase {
	public function test_get_type_returns_reindex(): void {
		$this->assertSame( 'reindex', ReindexJob::get_type() );
	}

	public function test_extends_abstract_job(): void {
		$this->assertInstanceOf( AbstractJob::class, new ReindexJob() );
	}

	public function test_default_group(): void {
		$this->assertSame( 'reindex', ( new ReindexJob() )->get_group() );
	}

	public function test_default_progress_total(): void {
		$this->assertSame( 1, ( new ReindexJob() )->get_progress_total() );
	}

	public function test_default_max_retries(): void {
		$this->assertSame( 2, ( new ReindexJob() )->get_max_retries() );
	}

	public function test_default_retry_delay(): void {
		$this->assertSame( 60, ( new ReindexJob() )->get_retry_delay_seconds() );
	}

	public function test_id_prefix(): void {
		$this->assertStringStartsWith( 'job_', ( new ReindexJob() )->get_id() );
	}

	public function test_set_data_post_types(): void {
		$j = new ReindexJob();
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
}
