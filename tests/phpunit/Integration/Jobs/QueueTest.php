<?php
/**
 * Queue tests.
 *
 * @package OneSearch\Tests\Integration\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Jobs;

use OneSearch\Jobs\Queue;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Queue class.
 */
#[CoversClass( Queue::class )]
final class QueueTest extends TestCase {
	/**
	 * The test job's action.
	 */
	private const HOOK = 'onesearch_test_job';

	/**
	 * The args of every run of the test job.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $runs = [];

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		$this->runs = [];
		add_action(
			self::HOOK,
			function ( ...$args ): void {
				$this->runs[] = $args;
			},
			10,
			2
		);
	}

	/**
	 * A queued job runs its action's callbacks with its args.
	 */
	public function test_add_runs_the_job_with_its_args(): void {
		$queue = new Queue();

		$this->assertTrue( $queue->add( self::HOOK, [ 42, 'abc' ] ) );
		$this->assertSame( [], $this->runs, 'The job runs later, not when it is queued.' );

		$this->run_jobs();

		$this->assertSame( [ [ 42, 'abc' ] ], $this->runs );
	}

	/**
	 * A unique job is left out while the same job waits, but not once that one is running.
	 */
	public function test_unique_add_skips_a_waiting_duplicate(): void {
		$queue = new Queue();

		$queue->add( self::HOOK, [ 1 ], true );
		$queue->add( self::HOOK, [ 1 ], true );
		$queue->add( self::HOOK, [ 2 ], true );

		// A change made while the job runs needs another run.
		$added_while_running = false;
		add_action(
			self::HOOK,
			static function ( int $id ) use ( $queue, &$added_while_running ): void {
				if ( 1 === $id && ! $added_while_running ) {
					$added_while_running = true;
					$queue->add( self::HOOK, [ 1 ], true );
				}
			}
		);

		$this->run_jobs();

		$this->assertEqualsCanonicalizing( [ [ 1 ], [ 2 ], [ 1 ] ], $this->runs );
	}

	/**
	 * Cancelled jobs don't run.
	 */
	public function test_cancel_removes_waiting_jobs(): void {
		$queue = new Queue();

		$queue->add( self::HOOK, [ 1 ] );
		$queue->add( self::HOOK, [ 2 ] );
		$queue->cancel( self::HOOK );

		$this->assertFalse( $queue->has( self::HOOK ) );

		$this->run_jobs();
		$this->assertSame( [], $this->runs );
	}

	/**
	 * A failed job is listed with the error Action Scheduler logged, whether it threw or PHP stopped it, until a later run of the same job makes up for it.
	 */
	public function test_get_failures_lists_the_failed_jobs_with_their_errors(): void {
		$queue = new Queue();
		$job   = (object) [ 'fails' => true ];
		add_action(
			self::HOOK,
			static function () use ( $job ): void {
				if ( $job->fails ) {
					throw new \RuntimeException( esc_html( "Algolia's hosts are unreachable." ) );
				}
			}
		);

		$queue->add( self::HOOK, [ 1 ] );
		$queue->add( self::HOOK, [ 2 ] );
		$this->run_jobs();

		$queue->add( self::HOOK, [ 3 ] );
		$killed_id = (int) \ActionScheduler::store()->find_action( self::HOOK, [ 'args' => [ 3 ] ] );
		$this->fail_job_with_fatal_error( $killed_id, 'Allowed memory size exhausted' );

		$failures = $queue->get_failures( self::HOOK, 0 );
		$this->assertSame( [ [ 3 ], [ 2 ], [ 1 ] ], array_column( $failures, 'args' ) );
		$this->assertSame( 'unexpected shutdown: PHP Fatal error Allowed memory size exhausted in render.php on line 3', $failures[0]['error'] );
		$this->assertStringEndsWith( "Algolia's hosts are unreachable.", $failures[1]['error'] );

		$job->fails = false;
		$queue->add( self::HOOK, [ 1 ] );
		$this->run_jobs();

		$this->assertSame( [ [ 3 ], [ 2 ] ], array_column( $queue->get_failures( self::HOOK, 0 ), 'args' ) );
	}

	/**
	 * The latest job of an action comes with how it ended, and its error if it failed.
	 */
	public function test_get_latest_gets_the_latest_job_and_how_it_ended(): void {
		$queue = new Queue();
		$this->assertNull( $queue->get_latest( self::HOOK ) );

		$queue->add( self::HOOK, [ 1 ] );
		$queue->add( self::HOOK, [ 2 ] );
		$queue->cancel( self::HOOK );
		$this->assertSame(
			[
				'args'   => [ 2 ],
				'status' => \ActionScheduler_Store::STATUS_CANCELED,
				'error'  => '',
			],
			$queue->get_latest( self::HOOK )
		);

		$queue->add( self::HOOK, [ 3 ] );
		$this->fail_job_with_fatal_error( (int) \ActionScheduler::store()->find_action( self::HOOK, [ 'args' => [ 3 ] ] ), 'Allowed memory size exhausted' );
		$this->assertSame(
			[
				'args'   => [ 3 ],
				'status' => \ActionScheduler_Store::STATUS_FAILED,
				'error'  => 'unexpected shutdown: PHP Fatal error Allowed memory size exhausted in render.php on line 3',
			],
			$queue->get_latest( self::HOOK )
		);
	}

	/**
	 * A failed job that a later run made up for isn't listed again once Action Scheduler deletes that run.
	 */
	public function test_get_failures_leaves_out_failures_older_than_completed_runs_are_kept(): void {
		global $wpdb;

		$queue = new Queue();
		$job   = (object) [ 'fails' => true ];
		add_action(
			self::HOOK,
			static function () use ( $job ): void {
				if ( $job->fails ) {
					throw new \RuntimeException( 'forced test exception' );
				}
			}
		);

		$queue->add( self::HOOK, [ 1 ] );
		$this->run_jobs();
		$this->assertCount( 1, $queue->get_failures( self::HOOK, 0 ) );

		$job->fails = false;
		$queue->add( self::HOOK, [ 1 ] );
		$this->run_jobs();
		$this->assertSame( [], $queue->get_failures( self::HOOK, 0 ) );

		// A month later, Action Scheduler deletes the completed run, and keeps the failed one for longer.
		$failed_id   = (int) \ActionScheduler::store()->find_action( self::HOOK, [ 'status' => \ActionScheduler_Store::STATUS_FAILED ] );
		$complete_id = (int) \ActionScheduler::store()->find_action( self::HOOK, [ 'status' => \ActionScheduler_Store::STATUS_COMPLETE ] );
		$wpdb->update( $wpdb->actionscheduler_actions, [ 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - MONTH_IN_SECONDS - DAY_IN_SECONDS ) ], [ 'action_id' => $failed_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		\ActionScheduler::store()->delete_action( $complete_id );

		$this->assertSame( [], $queue->get_failures( self::HOOK, 0 ) );
	}

	/**
	 * The runner is started with a request to admin-ajax.php, as Action Scheduler does.
	 */
	public function test_start_runner_requests_the_runner(): void {
		( new Queue() )->add( self::HOOK, [ 1 ] );

		$requests = [];
		add_filter( 'action_scheduler_allow_async_request_runner', '__return_true', 11 );
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$requests ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$requests[] = $url;
				return new \WP_Error( 'blocked', 'Blocked in tests.' );
			},
			10,
			3
		);

		Queue::start_runner();

		$this->assertNotEmpty( preg_grep( '/admin-ajax\.php\?action=as_async_request_queue_runner/', $requests ) );
	}
}
