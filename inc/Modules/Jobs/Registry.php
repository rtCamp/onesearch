<?php
/**
 * Registry for job types.
 *
 * @package OneSearch\Modules\Jobs
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Jobs;

use OneSearch\Contracts\Traits\Singleton;

/**
 * Class - Registry
 *
 * Singleton registry that maps job type names to their concrete FQCNs.
 * Used by Job_Scheduler to instantiate jobs from stored state, and by
 * Bootstrap to register built-in job types.
 *
 * Example usage:
 *   Registry::instance()->register( 'sync', Sync_Job::class );
 *   Registry::instance()->register( 'reindex', Reindex_Job::class );
 *
 *   $job = Registry::instance()->resolve( 'sync' );
 *   // $job is a fresh Sync_Job instance.
 */
final class Registry {
	use Singleton;

	/**
	 * Map of job type name → FQCN.
	 *
	 * @var array<string, class-string<\OneSearch\Modules\Jobs\Abstract_Job>>
	 */
	private array $jobs = [];

	/**
	 * Register a job type by name and class.
	 *
	 * @param string $name      The job type name (e.g. "sync", "reindex").
	 * @param string $class_name The fully-qualified class name that extends Abstract_Job.
	 * @throws \InvalidArgumentException If the class does not extend Abstract_Job.
	 * @throws \InvalidArgumentException If the class does not exist.
	 */
	public function register( string $name, string $class_name ): void {
		if ( ! class_exists( $class_name ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: class name */
					esc_html__( 'Job class "%s" does not exist.', 'onesearch' ),
					esc_html( $class_name )
				)
			);
		}

		if ( ! is_a( $class_name, Abstract_Job::class, true ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: 1: class name, 2: abstract job class */
					esc_html__( 'Job class "%1$s" must extend "%2$s".', 'onesearch' ),
					esc_html( $class_name ),
					Abstract_Job::class
				)
			);
		}

		$this->jobs[ $name ] = $class_name;
	}

	/**
	 * Create a fresh job instance by type name.
	 *
	 * @param string $name The registered job type name.
	 * @return \OneSearch\Modules\Jobs\Abstract_Job A new instance of the registered job class.
	 * @throws \InvalidArgumentException If the job type is not registered.
	 */
	public function resolve( string $name ): Abstract_Job {
		if ( ! $this->has( $name ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: job type name */
					esc_html__( 'Job type "%s" is not registered.', 'onesearch' ),
					esc_html( $name )
				)
			);
		}

		$class_name = $this->jobs[ $name ];

		return new $class_name();
	}

	/**
	 * Check if a job type name is registered.
	 *
	 * @param string $name The job type name.
	 * @return bool True if the name is registered.
	 */
	public function has( string $name ): bool {
		return isset( $this->jobs[ $name ] );
	}

	/**
	 * Get all registered job type names and their class names.
	 *
	 * @return array<string, class-string<\OneSearch\Modules\Jobs\Abstract_Job>> Map of name → FQCN.
	 */
	public function all(): array {
		return $this->jobs;
	}

	/**
	 * Get all registered job type names.
	 *
	 * @return string[] List of registered names.
	 */
	public function names(): array {
		return array_keys( $this->jobs );
	}

	/**
	 * Get the number of registered job types.
	 */
	public function count(): int {
		return count( $this->jobs );
	}
}
