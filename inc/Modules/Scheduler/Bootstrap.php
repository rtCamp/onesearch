<?php
/**
 * Bootstrap for the job scheduler module.
 *
 * @package OneSearch\Modules\Scheduler
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Scheduler;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Modules\Jobs\Registry;

/**
 * Class - Bootstrap
 *
 * Initializes the Action Scheduler-based job system:
 * 1. Conditionally loads Action Scheduler if not already available.
 * 2. Registers built-in job types (sync, reindex).
 * 3. Fires the onesearch_register_jobs action for extensibility.
 */
final class Bootstrap implements Registrable {
	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'plugins_loaded', [ $this, 'init' ], 20 );
	}

	/**
	 * Initialize the scheduler module.
	 *
	 * Registers built-in job types and fires an action for extensibility.
	 * Action Scheduler is loaded in onesearch.php before any hooks fire.
	 */
	public function init(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$scheduler = new Job_Scheduler();

		$registry = Registry::instance();
		$registry->register( 'sync', \OneSearch\Modules\Jobs\Sync_Job::class );
		$registry->register( 'reindex', \OneSearch\Modules\Jobs\Reindex_Job::class );

		/**
		 * Fires after built-in job types are registered.
		 *
		 * @param \OneSearch\Modules\Jobs\Registry      $registry  The job registry instance.
		 * @param \OneSearch\Modules\Scheduler\Job_Scheduler $scheduler The scheduler instance.
		 */
		do_action( 'onesearch_register_jobs', $registry, $scheduler );
	}
}
