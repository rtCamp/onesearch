<?php
/**
 * The jobs that share an Action Scheduler group.
 *
 * @package OneSearch\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Jobs;

/**
 * Class - Job_Registry
 */
final class Job_Registry {
	/**
	 * The Action Scheduler group of the jobs' actions.
	 */
	private string $group;

	/**
	 * The jobs, by name.
	 *
	 * @var array<string, class-string<\OneSearch\Jobs\Abstract_Job>>
	 */
	private array $jobs;

	/**
	 * Sets up the registry.
	 *
	 * @param string                                                    $group The Action Scheduler group of the jobs' actions.
	 * @param array<string, class-string<\OneSearch\Jobs\Abstract_Job>> $jobs  The jobs, by name.
	 */
	public function __construct( string $group, array $jobs ) {
		$this->group = $group;
		$this->jobs  = $jobs;
	}

	/**
	 * Registers every job's hooks.
	 */
	public function register_hooks(): void {
		foreach ( $this->jobs as $job ) {
			$this->get( $job )->register_hooks();
		}
	}

	/**
	 * Gets a job.
	 *
	 * @template T of \OneSearch\Jobs\Abstract_Job
	 *
	 * @param string $job The job's class.
	 * @phpstan-param class-string<T> $job
	 *
	 * @return T
	 *
	 * @throws \InvalidArgumentException If the job isn't in the registry.
	 */
	public function get( string $job ): Abstract_Job {
		$name = array_search( $job, $this->jobs, true );

		// Its actions would queue on a hook nothing runs.
		if ( false === $name ) {
			throw new \InvalidArgumentException( sprintf( 'The %s job isn\'t registered.', esc_html( $job ) ) );
		}

		$hook = $this->get_hook( $name );

		return new $job( $hook, new Queue( $this->group ), new Job_Records( $hook ) );
	}

	/**
	 * Gets the statuses every job reports, with the job's name.
	 *
	 * @return list<array{ job: string, key: string, status: string, error: ?string, data: array<string, mixed> }>
	 */
	public function get_statuses(): array {
		$statuses = [];

		foreach ( $this->jobs as $name => $job ) {
			foreach ( $this->get( $job )->get_statuses() as $status ) {
				$statuses[] = [ 'job' => $name ] + $status;
			}
		}

		return $statuses;
	}

	/**
	 * Gets a job's hook, which also names the options of its records.
	 *
	 * @param string $name The job's name.
	 */
	private function get_hook( string $name ): string {
		return $this->group . '_job_' . $name;
	}
}
