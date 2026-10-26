<?php
/**
 * A background job on Action Scheduler, and the records of its state.
 *
 * @package OneSearch\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Jobs;

/**
 * Class - Abstract_Job
 *
 * A job keeps a record of its state for each key it runs for, written as its actions run.
 *
 * @phpstan-import-type JobRecord from \OneSearch\Jobs\Job_Records
 * @phpstan-type RecordStatus array{ key: string, status: string, error: ?string, data: array<string, mixed> }
 */
abstract class Abstract_Job {
	/**
	 * The job's statuses.
	 */
	protected const STATUS_QUEUED    = 'queued';
	protected const STATUS_RUNNING   = 'running';
	protected const STATUS_COMPLETED = 'completed';
	protected const STATUS_FAILED    = 'failed';

	/**
	 * The job's hook.
	 */
	protected string $hook;

	/**
	 * The queue the job's actions run in.
	 */
	private Queue $queue;

	/**
	 * The records of the job's state.
	 */
	protected Job_Records $records;

	/**
	 * Sets up the job.
	 *
	 * @param string                      $hook    The job's hook.
	 * @param \OneSearch\Jobs\Queue       $queue   The queue its actions run in.
	 * @param \OneSearch\Jobs\Job_Records $records The records of its state.
	 */
	public function __construct( string $hook, Queue $queue, Job_Records $records ) {
		$this->hook    = $hook;
		$this->queue   = $queue;
		$this->records = $records;
	}

	/**
	 * Registers the job's action, and fails the job when Action Scheduler reports its action as failed, canceled or deleted.
	 */
	public function register_hooks(): void {
		add_action( $this->hook, [ $this, 'handle' ], 10, 9 );
		add_action( 'action_scheduler_failed_execution', [ $this, 'on_failed_execution' ], 10, 2 );
		add_action( 'action_scheduler_unexpected_shutdown', [ $this, 'on_unexpected_shutdown' ], 10, 2 );
		add_action( 'action_scheduler_failed_action', [ $this, 'on_timeout' ], 10, 2 );
		add_action( 'action_scheduler_canceled_action', [ $this, 'on_canceled_action' ] );
		add_action( 'action_scheduler_bulk_cancel_actions', [ $this, 'on_canceled_actions' ] );
		add_action( 'action_scheduler_deleted_action', [ $this, 'on_deleted_action' ] );
	}

	/**
	 * Runs the job's action. Throwing fails the job.
	 *
	 * @param array<int, mixed> $args The action's args.
	 *
	 * @return bool Whether the job is done. A job that isn't finishes later with finish().
	 */
	abstract protected function run( array $args ): bool;

	/**
	 * Gets the key of the record the job's state is kept in. Dispatches with the same key share a record.
	 *
	 * @param array<int, mixed> $args The action's args.
	 */
	abstract protected function get_key( array $args ): string;

	/**
	 * Runs after the job is written as completed. Throwing here fails the job instead.
	 *
	 * @param string              $key    The record's key.
	 * @param array<string,mixed> $record The job record.
	 * @phpstan-param JobRecord $record
	 *
	 * @throws \Exception When the job fails during completion.
	 */
	protected function on_complete( string $key, array $record ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- For the jobs that need it.
	}

	/**
	 * Runs after the job is written as failed.
	 *
	 * @param string              $key    The record's key.
	 * @param array<string,mixed> $record The job record, with the error.
	 * @phpstan-param JobRecord $record
	 */
	protected function on_failure( string $key, array $record ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- For the jobs that need it.
	}

	/**
	 * Queues the job, and writes its record as queued.
	 *
	 * @param array<int, mixed>    $args The args for the job's action.
	 * @param array<string, mixed> $data The data the job starts with, if it keeps any.
	 */
	public function dispatch( array $args = [], array $data = [] ): true|\WP_Error {
		$key = $this->get_key( $args );

		// Saved first, so the action finds its record.
		$this->records->save_record(
			$key,
			[
				'status'    => self::STATUS_QUEUED,
				'error'     => null,
				'data'      => $data,
				'action_id' => null,
			]
		);

		$action_id = $this->queue->add( $this->hook, $args );

		if ( 0 === $action_id ) {
			$error = "The job couldn't be queued.";
			$this->fail_record( $key, $error );

			return new \WP_Error( 'job_not_queued', $error, [ 'status' => 500 ] );
		}

		$this->records->change_record( $key, static fn ( array $record ): array => array_merge( $record, [ 'action_id' => $action_id ] ) );

		return true;
	}

	/**
	 * Gets the statuses the job reports, one for each of its records unless the job leaves some out.
	 *
	 * @return list<RecordStatus>
	 */
	public function get_statuses(): array {
		$statuses = [];

		foreach ( $this->records->get_records() as $key => $record ) {
			$statuses[] = [
				'key'    => (string) $key,
				'status' => $record['status'],
				'error'  => $record['error'],
				'data'   => $record['data'],
			];
		}

		return $statuses;
	}

	/**
	 * Runs the job's action, and writes the record around it.
	 *
	 * @internal Hook callback
	 *
	 * @param mixed ...$args The action's args.
	 */
	public function handle( ...$args ): void {
		$args = array_values( $args );
		$key  = $this->get_key( $args );

		$record = $this->records->change_record(
			$key,
			static fn ( array $record ): array => [
				'status' => self::STATUS_RUNNING,
				'error'  => null,
			] + $record
		);

		// Bail if deleted.
		if ( null === $record ) {
			return;
		}

		if ( $this->run( $args ) ) {
			$this->finish( $key );
		}
	}

	/**
	 * Fails the job of an action that threw, e.g. from the job's own code, or another callback on its hook.
	 *
	 * @internal Hook callback
	 *
	 * @param int        $action_id The action's ID.
	 * @param \Exception $e         What was thrown.
	 */
	public function on_failed_execution( $action_id, $e ): void {
		// The exception is escaped, and the error is escaped again where it's shown.
		$this->fail( (int) $action_id, wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
	}

	/**
	 * Fails the job of an action that PHP stopped with a fatal error, under Action Scheduler's queue runner.
	 *
	 * @internal Hook callback
	 *
	 * @param int                 $action_id The action's ID.
	 * @param array<string,mixed> $error     The error, from error_get_last().
	 */
	public function on_unexpected_shutdown( $action_id, $error ): void {
		$this->fail( (int) $action_id, (string) ( $error['message'] ?? '' ) );
	}

	/**
	 * Fails the job of an action that Action Scheduler found stuck, e.g. after a fatal error under the admin's Run link, or a killed process.
	 *
	 * @internal Hook callback
	 *
	 * @param int $action_id The action's ID.
	 * @param int $timeout   How long it could run, in seconds.
	 */
	public function on_timeout( $action_id, $timeout ): void {
		$this->fail( (int) $action_id, sprintf( "The job didn't finish within %d seconds.", (int) $timeout ) );
	}

	/**
	 * Fails the job of a canceled action.
	 *
	 * @internal Hook callback
	 *
	 * @param int $action_id The action's ID.
	 */
	public function on_canceled_action( $action_id ): void {
		$this->fail( (int) $action_id, 'The job was canceled.' );
	}

	/**
	 * Fails the jobs of canceled actions.
	 *
	 * @internal Hook callback
	 *
	 * @param int[] $action_ids The actions' IDs.
	 */
	public function on_canceled_actions( $action_ids ): void {
		foreach ( (array) $action_ids as $action_id ) {
			$this->on_canceled_action( (int) $action_id );
		}
	}

	/**
	 * Fails the job of a deleted action.
	 *
	 * @internal Hook callback
	 *
	 * @param int $action_id The action's ID.
	 */
	public function on_deleted_action( $action_id ): void {
		$this->fail( (int) $action_id, 'The job was deleted before it finished.' );
	}

	/**
	 * Saves some of the job's data, over the latest copy of its record with a key.
	 *
	 * @param string               $key  The record's key.
	 * @param array<string, mixed> $data The data to change.
	 */
	protected function save_data( string $key, array $data ): void {
		$this->records->change_record(
			$key,
			static fn ( array $record ): array => array_merge(
				$record,
				[ 'data' => $data + $record['data'] ]
			)
		);
	}

	/**
	 * Writes the job as completed, or as failed with an error. Throwing from on_complete() fails it instead.
	 *
	 * @uses on_complete()
	 * @uses on_failure() When the job fails.
	 *
	 * @param string  $key   The record's key.
	 * @param ?string $error Why the job failed, or null if it's done.
	 */
	protected function finish( string $key, ?string $error = null ): void {
		if ( null !== $error ) {
			$this->fail_record( $key, $error );
			return;
		}

		$record = $this->records->change_record(
			$key,
			static fn ( array $record ): array => [
				'status' => self::STATUS_COMPLETED,
				'error'  => null,
			] + $record
		);

		// Deleted while it ran.
		if ( null === $record ) {
			return;
		}

		try {
			$this->on_complete( $key, $record );
		} catch ( \Throwable $e ) {
			$this->fail_record( $key, wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
			return;
		}

		/**
		 * Fires after the job is written as completed or failed.
		 *
		 * @param string              $key    The record's key.
		 * @param array<string,mixed> $record The job record.
		 */
		do_action( $this->hook . '_finished', $key, $record ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The job's hook is prefixed.
	}

	/**
	 * Fails the record that's waiting on an action.
	 *
	 * @param int    $action_id The action's ID.
	 * @param string $error     Why the job failed.
	 */
	private function fail( int $action_id, string $error ): void {
		foreach ( $this->records->get_records() as $key => $record ) {
			// One that has moved on keeps its status, e.g. one that completed before its action was deleted.
			if ( $action_id === $record['action_id'] && in_array( $record['status'], [ self::STATUS_QUEUED, self::STATUS_RUNNING ], true ) ) {
				$this->fail_record( (string) $key, $error );
			}
		}
	}

	/**
	 * Fails a record.
	 *
	 * @param string $key   The record's key.
	 * @param string $error Why the job failed.
	 */
	private function fail_record( string $key, string $error ): void {
		$record = $this->records->change_record(
			$key,
			static fn ( array $record ): array => [
				'status' => self::STATUS_FAILED,
				'error'  => $error,
			] + $record
		);

		if ( null !== $record ) {
			$this->on_failure( $key, $record );

			/**
			 * This action is documented in inc/Jobs/Abstract_Job.php::finish()
			 *
			 * @param string              $key    The record's key.
			 * @param array<string,mixed> $record The job record.
			 */
			do_action( $this->hook . '_finished', $key, $record ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The job's hook is prefixed.
		}
	}
}
