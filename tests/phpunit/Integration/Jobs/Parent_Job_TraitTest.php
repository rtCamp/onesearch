<?php
/**
 * Parent_Job_Trait tests.
 *
 * @package OneSearch\Tests\Integration\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Jobs;

use OneSearch\Jobs\Abstract_Job;
use OneSearch\Jobs\Job_Registry;
use OneSearch\Jobs\Parent_Job_Trait;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversTrait;

/**
 * A job keyed by its first arg, that gives each list of items in its second arg to an Item_Job.
 */
final class List_Job extends Abstract_Job {
	use Parent_Job_Trait;

	/**
	 * The registry the job and its child are in.
	 */
	public static Job_Registry $registry;

	/**
	 * {@inheritDoc}
	 */
	public function dispatch( array $args = [], array $data = [] ): true|\WP_Error {
		$this->delete_children( $this->get_key( $args ) );

		return parent::dispatch( $args, $data );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_key( array $args ): string {
		return (string) $args[0];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_child(): Item_Job {
		return self::$registry->get( Item_Job::class );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( array $args ): bool {
		/** @var list<list<string>> $lists */
		$lists = $args[1];

		return $this->dispatch_children(
			$this->get_key( $args ),
			array_map( static fn ( array $items ): array => [ $items ], $lists ),
			count( array_merge( [], ...$lists ) )
		);
	}
}

/**
 * A child that handles its items and counts the ones named "bad" as failed. It throws when it has one named "down".
 */
final class Item_Job extends Abstract_Job {
	/**
	 * The items handled.
	 *
	 * @var list<string>
	 */
	public static array $handled = [];

	/**
	 * {@inheritDoc}
	 */
	protected function get_key( array $args ): string {
		return (string) $args[1];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When it has an item named "down".
	 */
	protected function run( array $args ): bool {
		/** @var list<string> $items */
		$items = $args[0];

		if ( in_array( 'down', $items, true ) ) {
			throw new \RuntimeException( 'The service is down.' );
		}

		array_push( self::$handled, ...$items );
		$this->save_data(
			$this->get_key( $args ),
			[
				'done'   => count( $items ),
				'failed' => count( array_keys( $items, 'bad', true ) ),
			]
		);

		return true;
	}
}

/**
 * Tests for the Parent_Job_Trait trait.
 */
#[CoversTrait( Parent_Job_Trait::class )]
final class Parent_Job_TraitTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		Item_Job::$handled  = [];
		List_Job::$registry = new Job_Registry(
			'onesearch',
			[
				'test_list' => List_Job::class,
				'test_item' => Item_Job::class,
			]
		);
		List_Job::$registry->register_hooks();
	}

	/**
	 * A parent counts its children as they finish, and once the last one has, fails with the error of the one that failed. The children's records go once they're counted.
	 */
	public function test_a_parent_fails_once_every_child_finished_when_one_failed(): void {
		$this->dispatch( 'list', [ [ 'a', 'bad' ], [ 'c' ], [ 'down' ] ] );

		$this->run_oldest_action();
		$this->assertSame( [ 'running', 3, 4, 0, 0 ], $this->get_progress( 'list' ) );

		$this->run_oldest_action();
		$this->assertSame( [ 'running', 3, 4, 2, 1 ], $this->get_progress( 'list' ) );

		$this->run_jobs();

		$this->assertSame( [ 'failed', 3, 4, 3, 1 ], $this->get_progress( 'list' ) );
		$this->assertSame( [ 'The service is down.' ], array_column( List_Job::$registry->get_statuses(), 'error' ) );
	}

	/**
	 * A parent without children completes right away.
	 */
	public function test_a_parent_without_children_completes(): void {
		$this->dispatch( 'list', [] );
		$this->run_jobs();

		$this->assertSame( [ 'completed', 0, 0, 0, 0 ], $this->get_progress( 'list' ) );
	}

	/**
	 * A parent dispatched again starts over, and the children of the run it replaced don't run or count.
	 */
	public function test_a_parent_dispatched_again_starts_over(): void {
		$this->dispatch( 'list', [ [ 'a' ], [ 'b' ] ] );
		$this->run_oldest_action();

		$this->dispatch( 'list', [ [ 'c' ] ] );
		$this->run_jobs();

		$this->assertSame( [ 'c' ], Item_Job::$handled );
		$this->assertSame( [ 'completed', 1, 1, 1, 0 ], $this->get_progress( 'list' ) );
	}

	/**
	 * Parents with different keys count only their own children.
	 */
	public function test_parents_count_only_their_own_children(): void {
		$this->dispatch( 'first', [ [ 'a' ] ] );
		$this->dispatch( 'second', [ [ 'b', 'bad' ], [ 'c' ] ] );
		$this->run_jobs();

		$this->assertSame( [ 'completed', 1, 1, 1, 0 ], $this->get_progress( 'first' ) );
		$this->assertSame( [ 'completed', 2, 3, 3, 1 ], $this->get_progress( 'second' ) );
	}

	/**
	 * Queues a List_Job.
	 *
	 * @param string               $key   The record's key.
	 * @param array<int, string[]> $lists The items of each child.
	 */
	private function dispatch( string $key, array $lists ): void {
		List_Job::$registry->get( List_Job::class )->dispatch( [ $key, $lists ] );
	}

	/**
	 * Gets a List_Job's status, with its numbers of children and items, and its items done and failed.
	 *
	 * @param string $key The record's key.
	 *
	 * @return array{ 0: string, 1: int, 2: int, 3: int, 4: int }
	 */
	private function get_progress( string $key ): array {
		foreach ( List_Job::$registry->get_statuses() as $status ) {
			if ( 'test_list' === $status['job'] && $key === $status['key'] ) {
				$data = $status['data'];

				return [ $status['status'], (int) $data['children'], (int) $data['total'], (int) $data['done'], (int) $data['failed'] ];
			}
		}

		$this->fail( sprintf( 'The %s list has no status.', $key ) );
	}
}
