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
	 * The queue.
	 */
	private Queue $queue;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		$this->queue = new Queue( 'onesearch' );
	}

	/**
	 * An action is skipped while the same one waits, but not while it runs, since it may have started before the change it's queued for.
	 */
	public function test_skips_only_a_waiting_duplicate(): void {
		$first = $this->queue->add( 'onesearch_test', [ 1 ] );

		$this->assertSame( $first, $this->queue->add( 'onesearch_test', [ 1 ] ) );
		$this->assertNotSame( $first, $this->queue->add( 'onesearch_test', [ 2 ] ) );

		$queued_while_running = 0;
		add_action(
			'onesearch_test',
			function () use ( &$queued_while_running ): void {
				$queued_while_running = $this->queue->add( 'onesearch_test', [ 1 ] );
			}
		);
		\ActionScheduler::runner()->process_action( $first, 'PHPUnit' );

		$this->assertNotSame( $first, $queued_while_running );
		$this->assertSame( \ActionScheduler_Store::STATUS_PENDING, $this->get_status( $queued_while_running ) );
	}

	/**
	 * Deleting the pending actions keeps the ones that ran, and other plugins' actions.
	 */
	public function test_delete_pending_keeps_the_actions_that_ran(): void {
		add_action( 'onesearch_test', '__return_null' );
		$ran = $this->queue->add( 'onesearch_test', [ 'ran' ] );
		\ActionScheduler::runner()->process_action( $ran, 'PHPUnit' );

		$this->queue->add( 'onesearch_test', [ 'waiting' ] );
		$others = as_enqueue_async_action( 'other_plugin_action' );

		$this->queue->delete_pending();

		$this->assertSame( [ $ran ], array_map( 'intval', as_get_scheduled_actions( [ 'group' => 'onesearch' ], 'ids' ) ) );
		$this->assertSame( \ActionScheduler_Store::STATUS_PENDING, $this->get_status( $others ) );
	}

	/**
	 * Gets an action's status.
	 *
	 * @param int $action_id The action's ID.
	 */
	private function get_status( int $action_id ): string {
		return \ActionScheduler::store()->get_status( (string) $action_id );
	}
}
