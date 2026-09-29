<?php
/**
 * Tests for the Reindex_Job class.
 *
 * @package OneSearch\Tests\Unit\Modules\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Jobs;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Jobs\Reindex_Job;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Reindex_Job.
 */
#[CoversClass( Reindex_Job::class )]
class Reindex_Job_Test extends TestCase {
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
}
