<?php
/**
 * A job that splits its work across child jobs.
 *
 * @package OneSearch\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Jobs;

/**
 * Trait - Parent_Job_Trait
 *
 * @phpstan-require-extends \OneSearch\Jobs\Abstract_Job
 */
trait Parent_Job_Trait {
	/**
	 * Gets the job this one dispatches.
	 */
	abstract protected function get_child(): Abstract_Job;

	/**
	 * {@inheritDoc}
	 *
	 * Checks the children each time one finishes.
	 */
	public function register_hooks(): void {
		parent::register_hooks();

		add_action( $this->get_child()->hook . '_finished', [ $this, 'on_child_finished' ], 10, 2 );
	}

	/**
	 * Checks the children of the run that dispatched a child, once the child has finished.
	 *
	 * @internal Hook callback
	 *
	 * @param string              $child_key    The child's record key.
	 * @param array<string,mixed> $child_record The child's record.
	 */
	public function on_child_finished( $child_key, $child_record ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		$parent = (array) $child_record['data']['parent'];

		$this->check_children( (string) $parent['key'], (string) $parent['run'] );
	}

	/**
	 * Deletes a record's children, so the ones still waiting don't run. A parent calls this from dispatch(), since dispatching again starts over.
	 *
	 * @param string $key The record's key.
	 */
	protected function delete_children( string $key ): void {
		$child = $this->get_child();

		foreach ( array_keys( $child->records->get_records() ) as $child_key ) {
			if ( str_starts_with( (string) $child_key, $key . ':' ) ) {
				$child->records->delete_record( (string) $child_key );
			}
		}
	}

	/**
	 * Starts a run of a record, and dispatches a child for each of the args.
	 *
	 * @param string                  $key      The record's key.
	 * @param list<array<int, mixed>> $children The args of each child.
	 * @param int                     $total    The number of items the children have between them.
	 *
	 * @return bool Whether the job is done, when it has no children.
	 */
	protected function dispatch_children( string $key, array $children, int $total ): bool {
		$run   = wp_generate_uuid4();
		$child = $this->get_child();

		$this->save_data(
			$key,
			[
				'run'      => $run,
				'children' => count( $children ),
				'total'    => $total,
				'done'     => 0,
				'failed'   => 0,
			]
		);

		// A child that can't be queued fails, and is checked like the others.
		foreach ( $children as $number => $args ) {
			$child->dispatch(
				[ ...$args, $key . ':' . $run . ':' . $number ],
				[
					'parent' => [
						'key' => $key,
						'run' => $run,
					],
				]
			);
		}

		return [] === $children;
	}

	/**
	 * Counts a run's children from their records, and once all of them have finished, completes the run, or fails it with the error of the first child that failed. A run that was replaced or has ended is left alone.
	 *
	 * @param string $key The record's key.
	 * @param string $run The run.
	 */
	private function check_children( string $key, string $run ): void {
		$record = $this->records->get_record( $key );

		if ( null === $record || self::STATUS_RUNNING !== $record['status'] || ( $record['data']['run'] ?? null ) !== $run ) {
			return;
		}

		$prefix   = $key . ':' . $run . ':';
		$children = [];
		$finished = 0;
		$error    = null;
		$counts   = [
			'done'   => 0,
			'failed' => 0,
		];

		foreach ( $this->get_child()->records->get_records() as $child_key => $child_record ) {
			if ( ! str_starts_with( (string) $child_key, $prefix ) ) {
				continue;
			}

			$children[] = (string) $child_key;

			$counts['done']   += (int) ( $child_record['data']['done'] ?? 0 );
			$counts['failed'] += (int) ( $child_record['data']['failed'] ?? 0 );

			if ( in_array( $child_record['status'], [ self::STATUS_COMPLETED, self::STATUS_FAILED ], true ) ) {
				++$finished;
			}

			if ( self::STATUS_FAILED === $child_record['status'] ) {
				$error ??= (string) $child_record['error'];
			}
		}

		$this->save_data( $key, $counts );

		if ( $finished === (int) $record['data']['children'] ) {
			foreach ( $children as $child_key ) {
				$this->get_child()->records->delete_record( $child_key );
			}

			$this->finish( $key, $error );
		}
	}
}
