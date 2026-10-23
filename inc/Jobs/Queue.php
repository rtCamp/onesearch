<?php
/**
 * The plugin's queue of Action Scheduler background jobs.
 *
 * @package OneSearch\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Jobs;

/**
 * Class - Queue
 */
final class Queue {
	/**
	 * The Action Scheduler group of the plugin's jobs.
	 */
	private const GROUP = 'onesearch';

	/**
	 * Queues a job to run as soon as possible.
	 *
	 * @param string            $hook   The action the job fires.
	 * @param array<int, mixed> $args   The args for the action's callbacks. Stored as JSON.
	 * @param bool              $unique Whether to skip adding the job if it's already queued to run.
	 *
	 * @return bool Whether the job is queued.
	 */
	public function add( string $hook, array $args = [], bool $unique = false ): bool {
		if ( $unique && [] !== $this->get_waiting_ids( $hook, $args, 1 ) ) {
			return true;
		}

		return (bool) as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Whether a job is waiting to run or running.
	 *
	 * @param string                 $hook The action the job fires.
	 * @param array<int, mixed>|null $args The job's args, or null for any.
	 */
	public function has( string $hook, ?array $args = null ): bool {
		return as_has_scheduled_action( $hook, $args, self::GROUP );
	}

	/**
	 * Gets the jobs that failed since the given time, latest first.
	 *
	 * Jobs that failed before Action Scheduler's retention period are left out too, since the completed runs that may have made up for them are deleted by then.
	 *
	 * @param string $hook  The action the jobs fire.
	 * @param int    $since The Unix timestamp.
	 *
	 * @return list<array{args: array<int, mixed>, error: string, failed_at: int}>
	 */
	public function get_failures( string $hook, int $since ): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Action Scheduler's own setting.
		$since = max( $since, time() - (int) apply_filters( 'action_scheduler_retention_period', MONTH_IN_SECONDS ) );

		$failed = as_get_scheduled_actions(
			[
				'hook'             => $hook,
				'group'            => self::GROUP,
				'status'           => \ActionScheduler_Store::STATUS_FAILED,
				'modified'         => as_get_datetime_object( $since ),
				'modified_compare' => '>=',
				'per_page'         => -1,
				'orderby'          => 'none',
			]
		);

		// IDs only go up, so each job's latest failure comes first.
		krsort( $failed );

		$failures = [];
		$seen     = [];

		foreach ( $failed as $action_id => $action ) {
			$args = $action->get_args();
			$key  = (string) wp_json_encode( $args );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$latest = as_get_scheduled_actions(
				[
					'hook'     => $hook,
					'group'    => self::GROUP,
					'args'     => $args,
					'status'   => [ \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler_Store::STATUS_FAILED ],
					'orderby'  => 'action_id',
					'order'    => 'DESC',
					'per_page' => 1,
				],
				'ids'
			);

			if ( (int) reset( $latest ) !== (int) $action_id ) {
				continue;
			}

			$log = $this->get_last_log( (int) $action_id );

			$failures[] = [
				'args'      => $args,
				'error'     => $this->get_error( $log ),
				'failed_at' => null !== $log ? $log->get_date()->getTimestamp() : 0,
			];
		}

		return $failures;
	}

	/**
	 * Gets the latest job of an action, along with its status, and its error if it failed.
	 *
	 * @param string $hook The action the job fires.
	 *
	 * @return ?array{args: array<int, mixed>, status: string, error: string} Null if there's none.
	 */
	public function get_latest( string $hook ): ?array {
		$action_ids = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'group'    => self::GROUP,
				'orderby'  => 'action_id',
				'order'    => 'DESC',
				'per_page' => 1,
			],
			'ids'
		);

		if ( [] === $action_ids ) {
			return null;
		}

		$action_id = (int) reset( $action_ids );
		$status    = (string) \ActionScheduler::store()->get_status( (string) $action_id );
		$log       = \ActionScheduler_Store::STATUS_FAILED === $status ? $this->get_last_log( $action_id ) : null;

		return [
			'args'   => \ActionScheduler::store()->fetch_action( (string) $action_id )->get_args(),
			'status' => $status,
			'error'  => $this->get_error( $log ),
		];
	}

	/**
	 * Cancels the jobs waiting to run. Jobs that are running finish.
	 *
	 * @param string $hook The action the jobs fire.
	 */
	public function cancel( string $hook ): void {
		foreach ( $this->get_waiting_ids( $hook, null, -1 ) as $action_id ) {
			\ActionScheduler::store()->cancel_action( $action_id );
		}
	}

	/**
	 * Deletes every job of the plugin that's waiting to run, e.g. when the plugin is uninstalled. The jobs that ran are kept, along with their logs.
	 */
	public function delete_pending(): void {
		foreach ( $this->get_waiting_ids( '', null, -1 ) as $action_id ) {
			\ActionScheduler::store()->delete_action( (string) $action_id );
		}
	}

	/**
	 * Starts Action Scheduler's runner at the end of the request, so jobs someone is waiting on don't wait for WP-Cron. Hook it to `shutdown` after queueing them.
	 *
	 * Action Scheduler only does this itself on admin screens, at most once a minute. Starting it holds the request for about a second, since WordPress waits at least that long for the runner's request.
	 *
	 * @internal Hook callback
	 */
	public static function start_runner(): void {
		// Action Scheduler unhooks its own check once the runner is started, so it starts once per request.
		if ( false === has_action( 'shutdown', [ \ActionScheduler_QueueRunner::instance(), 'maybe_dispatch_async_request' ] ) ) {
			return;
		}

		( new \ActionScheduler_AsyncRequest_QueueRunner( \ActionScheduler::store() ) )->maybe_dispatch();
	}

	/**
	 * Gets the last line Action Scheduler logged for a job.
	 *
	 * @param int $action_id The job's action ID.
	 */
	private function get_last_log( int $action_id ): ?\ActionScheduler_LogEntry {
		$logs = \ActionScheduler::logger()->get_logs( $action_id );
		$log  = end( $logs );

		return $log instanceof \ActionScheduler_LogEntry ? $log : null;
	}

	/**
	 * Gets a job's error from the last line Action Scheduler logged for it.
	 *
	 * @param ?\ActionScheduler_LogEntry $log The line.
	 */
	private function get_error( ?\ActionScheduler_LogEntry $log ): string {
		if ( null === $log ) {
			return '';
		}

		// Cleanup Action Scheduler failure prefix.
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment -- Action Scheduler's own string.
		$format  = preg_quote( __( 'action failed via %1$s: %2$s', 'action-scheduler' ), '/' );
		$message = preg_replace( '/^' . str_replace( [ '%1\$s', '%2\$s' ], [ '.+?', '(.*)' ], $format ) . '$/s', '$1', $log->get_message() );

		// Exceptions are escaped when they're thrown, and whoever shows the error escapes it again.
		return wp_specialchars_decode( (string) $message, ENT_QUOTES );
	}

	/**
	 * Gets the IDs of the jobs waiting to run.
	 *
	 * @param string                 $hook  The action the jobs fire, or '' for any.
	 * @param array<int, mixed>|null $args  The jobs' args, or null for any.
	 * @param int                    $limit The most IDs to get, or -1 for all.
	 *
	 * @return int[]
	 */
	private function get_waiting_ids( string $hook, ?array $args, int $limit ): array {
		$query = [
			'hook'     => $hook,
			'group'    => self::GROUP,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => $limit,
			'orderby'  => 'none',
		];

		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return array_map( 'intval', as_get_scheduled_actions( $query, 'ids' ) );
	}
}
