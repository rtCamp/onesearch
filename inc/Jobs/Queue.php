<?php
/**
 * A queue of Action Scheduler actions in one group.
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
	 * The Action Scheduler group of the actions.
	 */
	private string $group;

	/**
	 * Sets up the queue.
	 *
	 * @param string $group The Action Scheduler group of the actions.
	 */
	public function __construct( string $group ) {
		$this->group = $group;
	}

	/**
	 * Queues an action to run as soon as possible, unless the same action is still waiting to run. A running one doesn't count, since it may have started before the change it's queued for.
	 *
	 * @param string            $hook The action's hook.
	 * @param array<int, mixed> $args The args for the hook's callbacks. Stored as JSON.
	 *
	 * @return int The ID of the action that will run, or 0 if it couldn't be queued.
	 */
	public function add( string $hook, array $args = [] ): int {
		$waiting = $this->get_waiting_ids( $hook, $args, 1 );

		return [] !== $waiting ? $waiting[0] : (int) as_enqueue_async_action( $hook, $args, $this->group );
	}

	/**
	 * Deletes every action in the group that's waiting to run. The ones that ran are kept, along with their logs.
	 */
	public function delete_pending(): void {
		foreach ( $this->get_waiting_ids( '', null, -1 ) as $action_id ) {
			\ActionScheduler::store()->delete_action( (string) $action_id );
		}
	}

	/**
	 * Starts Action Scheduler's runner at the end of the request, so an admin watching doesn't wait for WP-Cron.
	 *
	 * Action Scheduler only does this itself on admin screens, at most once a minute.
	 *
	 * @internal Hook callback for `shutdown`.
	 */
	public static function start_runner(): void {
		( new \ActionScheduler_AsyncRequest_QueueRunner( \ActionScheduler::store() ) )->maybe_dispatch();
	}

	/**
	 * Gets the IDs of the actions waiting to run, oldest first.
	 *
	 * @param string                 $hook  The actions' hook, or '' for any.
	 * @param array<int, mixed>|null $args  The actions' args, or null for any.
	 * @param int                    $limit The most IDs to get, or -1 for all.
	 *
	 * @return int[]
	 */
	private function get_waiting_ids( string $hook, ?array $args, int $limit ): array {
		$query = [
			'hook'     => $hook,
			'group'    => $this->group,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => $limit,
			'orderby'  => 'action_id',
			'order'    => 'ASC',
		];

		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return array_map( 'intval', as_get_scheduled_actions( $query, 'ids' ) );
	}
}
