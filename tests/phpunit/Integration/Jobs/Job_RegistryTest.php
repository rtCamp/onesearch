<?php
/**
 * Job_Registry tests.
 *
 * @package OneSearch\Tests\Integration\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Jobs;

use OneSearch\Jobs\Abstract_Job;
use OneSearch\Jobs\Job_Registry;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A job keyed by its first arg, that does nothing.
 */
final class Noop_Job extends Abstract_Job {
	/**
	 * {@inheritDoc}
	 */
	protected function get_key( array $args ): string {
		return (string) $args[0];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( array $args ): bool { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		return true;
	}
}

/**
 * Another job keyed by its first arg, that does nothing.
 */
final class Other_Noop_Job extends Abstract_Job {
	/**
	 * {@inheritDoc}
	 */
	protected function get_key( array $args ): string {
		return (string) $args[0];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( array $args ): bool { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		return true;
	}
}

/**
 * Tests for the Job_Registry class.
 */
#[CoversClass( Job_Registry::class )]
final class Job_RegistryTest extends TestCase {
	/**
	 * The statuses name the job they're from, and a registry with another group doesn't share the records of a job with the same name.
	 */
	public function test_gets_the_statuses_of_its_jobs_by_name(): void {
		$registry = new Job_Registry(
			'onesearch',
			[
				'first'  => Noop_Job::class,
				'second' => Other_Noop_Job::class,
			]
		);
		$registry->register_hooks();

		$registry->get( Noop_Job::class )->dispatch( [ 'a' ] );
		$registry->get( Other_Noop_Job::class )->dispatch( [ 'b' ] );
		$this->run_jobs();

		$this->assertSame(
			[
				[
					'job'    => 'first',
					'key'    => 'a',
					'status' => 'completed',
					'error'  => null,
					'data'   => [],
				],
				[
					'job'    => 'second',
					'key'    => 'b',
					'status' => 'completed',
					'error'  => null,
					'data'   => [],
				],
			],
			$registry->get_statuses()
		);
		$this->assertSame( [], ( new Job_Registry( 'other_plugin', [ 'first' => Noop_Job::class ] ) )->get_statuses() );
	}

	/**
	 * A job that isn't registered can't be got, since nothing would run its actions.
	 */
	public function test_an_unregistered_job_cannot_be_got(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Job_Registry( 'onesearch', [ 'first' => Noop_Job::class ] ) )->get( Other_Noop_Job::class );
	}
}
