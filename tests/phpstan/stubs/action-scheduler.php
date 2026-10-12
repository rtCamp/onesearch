<?php // phpcs:disable -- This is a stub file for PHPStan analysis.
/**
 * Action Scheduler function and class stubs.
 */

namespace {
	class ActionScheduler_Store {
		/**
		 * @return static
		 */
		public static function instance(): static {}
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
	 * @param bool $unique
	 * @param int $priority
	 * @return int
	 */
	function as_schedule_single_action( int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {}

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
}