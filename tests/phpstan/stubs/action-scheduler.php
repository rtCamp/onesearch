<?php // phpcs:disable -- This is a stub file for PHPStan analysis.
/**
 * Action Scheduler function and class stubs.
 */

namespace {
	class ActionScheduler_Store {
		public const STATUS_PENDING = 'pending';
		public const STATUS_RUNNING = 'running';

		/**
		 * @return static
		 */
		public static function instance(): static {}
	}

	class ActionScheduler_Action {
		/**
		 * @return array<string, mixed>
		 */
		public function get_args(): array {}
	}

	/**
	 * @param string $hook
	 * @param array<string, mixed> $args
	 * @param string $group
	 * @return int
	 */
	function as_enqueue_async_action( string $hook, array $args = [], string $group = '' ): int {}

	/**
	 * @param int $timestamp
	 * @param string $hook
	 * @param array<string, mixed> $args
	 * @param string $group
	 * @return int
	 */
	function as_schedule_single_action( int $timestamp, string $hook, array $args = [], string $group = '' ): int {}

	/**
	 * @param int $timestamp
	 * @param int $interval_in_seconds
	 * @param string $hook
	 * @param array<string, mixed> $args
	 * @param string $group
	 * @return int
	 */
	function as_schedule_recurring_action( int $timestamp, int $interval_in_seconds, string $hook, array $args = [], string $group = '' ): int {}

	/**
	 * @param string $hook
	 * @param array<string, mixed> $args
	 * @param string $group
	 * @return int|null
	 */
	function as_unschedule_action( string $hook, array $args = [], string $group = '' ): ?int {}

	/**
	 * @param string $hook
	 * @param array<string, mixed> $args
	 * @param string $group
	 * @return void
	 */
	function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {}

	/**
	 * @param array<string, mixed> $args
	 * @return ActionScheduler_Action[]
	 */
	function as_get_scheduled_actions( array $args = [] ): array {}
}