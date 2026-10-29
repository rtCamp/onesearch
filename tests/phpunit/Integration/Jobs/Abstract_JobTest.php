<?php
/**
 * Abstract_Job tests.
 *
 * @package OneSearch\Tests\Integration\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Jobs;

use OneSearch\Jobs\Abstract_Job;
use OneSearch\Jobs\Job_Records;
use OneSearch\Jobs\Queue;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A job keyed by its first arg, that fails while `$fails` is set and calls `$while_running` as it runs.
 */
final class Keyed_Job extends Abstract_Job {
	/**
	 * Why the job fails, if it does.
	 *
	 * @var ?string
	 */
	public static ?string $fails = null;

	/**
	 * What happens while the job runs, if anything.
	 *
	 * @var ?\Closure
	 */
	public static ?\Closure $while_running = null;

	/**
	 * The records passed to on_complete().
	 *
	 * @var list<array<string, mixed>>
	 */
	public static array $completed = [];

	/**
	 * The records passed to on_failure().
	 *
	 * @var list<array<string, mixed>>
	 */
	public static array $failed = [];

	/**
	 * {@inheritDoc}
	 */
	protected function get_key( array $args ): string {
		return (string) ( $args[0] ?? '' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException While `$fails` is set.
	 */
	protected function run( array $args ): bool { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		if ( null !== self::$while_running ) {
			( self::$while_running )();
		}

		if ( null !== self::$fails ) {
			throw new \RuntimeException( esc_html( self::$fails ) );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function on_complete( string $key, array $record ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		self::$completed[] = $record;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function on_failure( string $key, array $record ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		self::$failed[] = $record;
	}
}

/**
 * A job that saves its data and finishes after its action, when it's told to, like a job waiting on others.
 */
final class Waiting_Job extends Abstract_Job {
	/**
	 * Why on_complete() throws, if it does.
	 *
	 * @var ?string
	 */
	public static ?string $complete_fails = null;

	/**
	 * The records passed to on_complete().
	 *
	 * @var list<array<string, mixed>>
	 */
	public static array $completed = [];

	/**
	 * The records passed to on_failure().
	 *
	 * @var list<array<string, mixed>>
	 */
	public static array $failed = [];

	/**
	 * Finishes the job.
	 *
	 * @param ?string $error Why it failed, or null if it's done.
	 */
	public function finish_now( ?string $error = null ): void {
		$this->finish( '', $error );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_key( array $args ): string { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( array $args ): bool { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		$this->save_data( '', [ 'started' => true ] );

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException While `$complete_fails` is set.
	 */
	protected function on_complete( string $key, array $record ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		if ( null !== self::$complete_fails ) {
			throw new \RuntimeException( esc_html( self::$complete_fails ) );
		}

		self::$completed[] = $record;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function on_failure( string $key, array $record ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		self::$failed[] = $record;
	}
}

/**
 * Tests for the Abstract_Job class.
 */
#[CoversClass( Abstract_Job::class )]
final class Abstract_JobTest extends TestCase {
	/**
	 * The keyed job.
	 */
	private Keyed_Job $keyed;

	/**
	 * The waiting job.
	 */
	private Waiting_Job $waiting;

	/**
	 * The keyed job's records.
	 */
	private Job_Records $keyed_records;

	/**
	 * The waiting job's records.
	 */
	private Job_Records $waiting_records;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		Keyed_Job::$fails            = null;
		Keyed_Job::$while_running    = null;
		Keyed_Job::$completed        = [];
		Keyed_Job::$failed           = [];
		Waiting_Job::$complete_fails = null;
		Waiting_Job::$completed      = [];
		Waiting_Job::$failed         = [];

		$this->keyed_records   = new Job_Records( 'onesearch_test_keyed' );
		$this->waiting_records = new Job_Records( 'onesearch_test_waiting' );
		$this->keyed           = new Keyed_Job( 'onesearch_test_keyed', new Queue( 'onesearch' ), $this->keyed_records );
		$this->waiting         = new Waiting_Job( 'onesearch_test_waiting', new Queue( 'onesearch' ), $this->waiting_records );
		$this->keyed->register_hooks();
		$this->waiting->register_hooks();
	}

	/**
	 * A job is queued, runs, and completes, then on_complete() runs.
	 */
	public function test_a_job_is_queued_runs_and_completes(): void {
		$this->assertTrue( $this->keyed->dispatch( [ 'a' ] ) );
		$this->assertSame( [ 'a' => $this->record( 'queued' ) ], $this->get_records( $this->keyed_records ) );

		$while_running            = null;
		Keyed_Job::$while_running = function () use ( &$while_running ): void {
			$while_running = $this->get_records( $this->keyed_records )['a']['status'];
		};
		$this->run_jobs();

		$this->assertSame( 'running', $while_running );
		$this->assertSame( [ 'a' => $this->record( 'completed' ) ], $this->get_records( $this->keyed_records ) );
		$this->assertSame( [ $this->record( 'completed' ) ], $this->without_action_ids( Keyed_Job::$completed ) );
	}

	/**
	 * A job that can't be queued fails with why, and on_failure() runs.
	 */
	public function test_a_job_that_cannot_be_queued_fails(): void {
		add_filter( 'pre_as_enqueue_async_action', '__return_zero' );

		$result = $this->keyed->dispatch( [ 'a' ] );

		$this->assertWPError( $result );
		$this->assertSame( [ 'a' => $this->record( 'failed', "The job couldn't be queued." ) ], $this->get_records( $this->keyed_records ) );
		$this->assertCount( 1, Keyed_Job::$failed );
	}

	/**
	 * A job that isn't done when its action is stays running with its data, and finishes later through the same lifecycle.
	 */
	public function test_a_job_finishes_after_its_action(): void {
		$this->waiting->dispatch();
		$this->run_jobs();

		$this->assertSame( [ '' => $this->record( 'running', null, [ 'started' => true ] ) ], $this->get_records( $this->waiting_records ) );

		$this->waiting->finish_now();

		$this->assertSame( [ '' => $this->record( 'completed', null, [ 'started' => true ] ) ], $this->get_records( $this->waiting_records ) );
		$this->assertCount( 1, Waiting_Job::$completed );

		$this->waiting->dispatch();
		$this->run_jobs();
		$this->waiting->finish_now( 'A child failed.' );

		$this->assertSame( [ '' => $this->record( 'failed', 'A child failed.', [ 'started' => true ] ) ], $this->get_records( $this->waiting_records ) );
		$this->assertCount( 1, Waiting_Job::$failed );
	}

	/**
	 * A job whose on_complete() throws fails with the error instead.
	 */
	public function test_a_job_whose_on_complete_throws_fails(): void {
		Waiting_Job::$complete_fails = 'The cleanup is "down".';

		$this->waiting->dispatch();
		$this->run_jobs();
		$this->waiting->finish_now();

		$this->assertSame( [ '' => $this->record( 'failed', 'The cleanup is "down".', [ 'started' => true ] ) ], $this->get_records( $this->waiting_records ) );
		$this->assertCount( 1, Waiting_Job::$failed );
	}

	/**
	 * An action that throws fails its job with the error, once.
	 */
	public function test_an_action_that_throws_fails_its_job(): void {
		Keyed_Job::$fails = 'A is "down".';

		$this->keyed->dispatch( [ 'a' ] );
		$this->run_jobs();

		$this->assertSame( [ 'a' => $this->record( 'failed', 'A is "down".' ) ], $this->get_records( $this->keyed_records ) );
		$this->assertSame( [ $this->record( 'failed', 'A is "down".' ) ], $this->without_action_ids( Keyed_Job::$failed ) );
		$this->assertSame( [], Keyed_Job::$completed );
	}

	/**
	 * An action that throws before the job runs fails the job, e.g. from another plugin's callback on the job's hook.
	 */
	public function test_an_action_that_throws_before_the_job_runs_fails_it(): void {
		add_action(
			'onesearch_test_keyed',
			static function (): void {
				throw new \Error( 'Call to undefined function nope()' );
			},
			1
		);

		$this->keyed->dispatch( [ 'a' ] );
		$this->run_jobs();

		$this->assertSame( [ 'a' => $this->record( 'failed', 'Call to undefined function nope()' ) ], $this->get_records( $this->keyed_records ) );
		$this->assertCount( 1, Keyed_Job::$failed );
	}

	/**
	 * A fatal error or a stuck action fails the record of that action's key only, and other plugins' actions are left alone.
	 */
	public function test_a_fatal_error_or_a_stuck_action_fails_only_its_record(): void {
		$this->keyed->dispatch( [ 'a' ] );
		$this->keyed->dispatch( [ 'b' ] );
		[ $action_a, $action_b ] = array_map( 'intval', as_get_scheduled_actions( [ 'hook' => 'onesearch_test_keyed' ], 'ids' ) );
		$other                   = as_enqueue_async_action( 'other_plugin_action' );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Action Scheduler's own actions.
		do_action(
			'action_scheduler_unexpected_shutdown',
			$action_a,
			[
				'type'    => E_ERROR,
				'message' => 'Allowed memory size exhausted',
				'file'    => 'render.php',
				'line'    => 3,
			]
		);

		$this->assertSame(
			[
				'a' => $this->record( 'failed', 'Allowed memory size exhausted' ),
				'b' => $this->record( 'queued' ),
			],
			$this->get_records( $this->keyed_records )
		);

		do_action( 'action_scheduler_failed_action', $action_b, 300 );
		do_action( 'action_scheduler_failed_action', $other, 300 );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->assertSame( $this->record( 'failed', "The job didn't finish within 300 seconds." ), $this->get_records( $this->keyed_records )['b'] );
		$this->assertCount( 2, Keyed_Job::$failed );
	}

	/**
	 * A canceled action fails the record of its key, whether it's canceled on its own or with others.
	 */
	public function test_a_canceled_action_fails_its_job(): void {
		$this->keyed->dispatch( [ 'a' ] );
		$this->keyed->dispatch( [ 'b' ] );
		$this->keyed->dispatch( [ 'c' ] );
		[ $action_a ] = array_map( 'intval', as_get_scheduled_actions( [ 'hook' => 'onesearch_test_keyed' ], 'ids' ) );

		\ActionScheduler::store()->cancel_action( (string) $action_a );

		$this->assertSame(
			[
				'a' => $this->record( 'failed', 'The job was canceled.' ),
				'b' => $this->record( 'queued' ),
				'c' => $this->record( 'queued' ),
			],
			$this->get_records( $this->keyed_records )
		);

		\ActionScheduler::store()->cancel_actions_by_hook( 'onesearch_test_keyed' );

		$this->assertSame( [ 'failed', 'failed', 'failed' ], array_column( $this->get_records( $this->keyed_records ), 'status' ) );
		$this->assertCount( 3, Keyed_Job::$failed );
	}

	/**
	 * A deleted action fails the record it leaves with no action to run it, and a record that completed stays completed when its action is deleted later.
	 */
	public function test_a_deleted_action_fails_its_job(): void {
		$this->keyed->dispatch( [ 'a' ] );
		$this->keyed->dispatch( [ 'b' ] );
		$this->keyed->dispatch( [ 'c' ] );
		[ $action_a, , $action_c ] = array_map( 'intval', as_get_scheduled_actions( [ 'hook' => 'onesearch_test_keyed' ], 'ids' ) );
		\ActionScheduler::runner()->process_action( $action_c, 'PHPUnit' );

		\ActionScheduler::store()->delete_action( (string) $action_a );
		\ActionScheduler::store()->delete_action( (string) $action_c );

		$this->assertSame(
			[
				'a' => $this->record( 'failed', 'The job was deleted before it finished.' ),
				'b' => $this->record( 'queued' ),
				'c' => $this->record( 'completed' ),
			],
			$this->get_records( $this->keyed_records )
		);
		$this->assertCount( 1, Keyed_Job::$failed );
	}

	/**
	 * A job queued again while it runs is left to its newer action when the older one fails, and the newer one completes it.
	 */
	public function test_a_job_queued_again_while_it_runs_completes_with_its_newer_action(): void {
		$queued_again             = false;
		Keyed_Job::$fails         = 'A is down.';
		Keyed_Job::$while_running = function () use ( &$queued_again ): void {
			if ( ! $queued_again ) {
				$queued_again = true;
				$this->keyed->dispatch( [ 'a' ] );
			}
		};

		$this->keyed->dispatch( [ 'a' ] );
		$this->run_oldest_action();
		$this->assertSame( [ 'a' => $this->record( 'queued' ) ], $this->get_records( $this->keyed_records ) );
		$this->assertSame( [], Keyed_Job::$failed );

		$status                   = null;
		Keyed_Job::$fails         = null;
		Keyed_Job::$while_running = function () use ( &$status ): void {
			$status = $this->get_records( $this->keyed_records )['a']['status'];
		};
		$this->run_jobs();

		$this->assertSame( 'running', $status );
		$this->assertSame( [ 'a' => $this->record( 'completed' ) ], $this->get_records( $this->keyed_records ) );
	}

	/**
	 * A job whose record was deleted before its action runs doesn't run, and one whose record was deleted while it ran writes nothing back, e.g. one about a brand site that was removed.
	 */
	public function test_a_job_whose_record_was_deleted_writes_nothing_back(): void {
		$runs                     = 0;
		Keyed_Job::$while_running = function () use ( &$runs ): void {
			++$runs;
			$this->keyed_records->delete_record( 'a' );
		};

		$this->keyed->dispatch( [ 'a' ] );
		$this->keyed->dispatch( [ 'b' ] );
		$this->keyed_records->delete_record( 'b' );
		$this->run_jobs();

		$this->assertSame( 1, $runs );
		$this->assertSame( [], $this->get_records( $this->keyed_records ) );
		$this->assertSame( [], Keyed_Job::$completed );
	}

	/**
	 * Builds an expected record.
	 *
	 * @param string               $status The status.
	 * @param ?string              $error  The error.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return array<string, mixed>
	 */
	private function record( string $status, ?string $error = null, array $data = [] ): array {
		return [
			'status' => $status,
			'error'  => $error,
			'data'   => $data,
		];
	}

	/**
	 * Gets a job's records, without the actions they wait on.
	 *
	 * @param \OneSearch\Jobs\Job_Records $records The job's records.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_records( Job_Records $records ): array {
		return $this->without_action_ids( $records->get_records() );
	}

	/**
	 * Drops the actions records wait on.
	 *
	 * @param array<array-key, array<string, mixed>> $records The records.
	 *
	 * @return array<array-key, array<string, mixed>>
	 */
	private function without_action_ids( array $records ): array {
		return array_map(
			static function ( array $record ): array {
				unset( $record['action_id'] );
				return $record;
			},
			$records
		);
	}
}
