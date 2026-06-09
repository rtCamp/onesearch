<?php
/**
 * Tests for the ReindexJob class.
 *
 * @package OneSearch\Tests\Unit\Modules\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Jobs;

use OneSearch\Modules\Jobs\AbstractJob;
use OneSearch\Modules\Jobs\ReindexJob;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for ReindexJob.
 */
#[CoversClass( ReindexJob::class )]
class ReindexJobTest extends TestCase {
	/**
	 * Verifies get_type() returns 'reindex'.
	 */
	public function test_get_type_returns_reindex(): void {
		$this->assertSame( 'reindex', ReindexJob::get_type() );
	}

	/**
	 * Verifies ReindexJob extends AbstractJob.
	 */
	public function test_extends_abstract_job(): void {
		$this->assertInstanceOf( AbstractJob::class, new ReindexJob() );
	}

	/**
	 * Verifies the default job group is 'reindex'.
	 */
	public function test_default_group(): void {
		$this->assertSame( 'reindex', ( new ReindexJob() )->get_group() );
	}

	/**
	 * Verifies the default progress total is 1.
	 */
	public function test_default_progress_total(): void {
		$this->assertSame( 1, ( new ReindexJob() )->get_progress_total() );
	}

	/**
	 * Verifies the default max retries is 2.
	 */
	public function test_default_max_retries(): void {
		$this->assertSame( 2, ( new ReindexJob() )->get_max_retries() );
	}

	/**
	 * Verifies the default retry delay is 60 seconds.
	 */
	public function test_default_retry_delay(): void {
		$this->assertSame( 60, ( new ReindexJob() )->get_retry_delay_seconds() );
	}

	/**
	 * Verifies the job ID starts with 'job_'.
	 */
	public function test_id_prefix(): void {
		$this->assertStringStartsWith( 'job_', ( new ReindexJob() )->get_id() );
	}

	/**
	 * Verifies set_data() stores post_types and batch_size correctly.
	 */
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
