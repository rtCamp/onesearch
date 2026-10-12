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
 * Registers the built-in job types once Action Scheduler is available, then fires onesearch_register_jobs so other
 * code can add its own.
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
	 * Action Scheduler is loaded in onesearch.php before any hooks fire, so it's only missing if that failed.
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
