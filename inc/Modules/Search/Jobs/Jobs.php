<?php
/**
 * The plugin's background jobs.
 *
 * @package OneSearch\Modules\Search\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search\Jobs;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Jobs\Job_Registry;

/**
 * Class - Jobs
 *
 * Each job that reports a status keys its records by the site it's about.
 */
final class Jobs implements Registrable {
	/**
	 * The Action Scheduler group of the jobs' actions. It starts their hooks and the options of their records too.
	 */
	private const GROUP = 'onesearch';

	/**
	 * The jobs, by name.
	 */
	private const JOBS = [
		'index_site'          => Index_Site_Job::class,
		'index_posts'         => Index_Posts_Job::class,
		'delete_site_records' => Delete_Site_Records_Job::class,
	];

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		self::registry()->register_hooks();
	}

	/**
	 * Gets the registry of the plugin's jobs.
	 */
	public static function registry(): Job_Registry {
		return new Job_Registry( self::GROUP, self::JOBS );
	}
}
