<?php
/**
 * Tests that Action Scheduler ships with the plugin.
 *
 * @package OneSearch\Tests\Integration\Modules\Scheduler
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Scheduler;

use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Reindexing and post syncs can't run without Action Scheduler, and on a site without WooCommerce the plugin's own
 * copy is the only one.
 */
#[CoversNothing]
final class Action_Scheduler_DependencyTest extends TestCase {
	/**
	 * A fresh `composer install --no-dev`, and the release zip built from it, ships the copy onesearch.php loads.
	 *
	 * Left in composer.lock without a composer.json requirement it still installs, until the next `composer update`
	 * drops it.
	 */
	public function test_action_scheduler_is_a_runtime_dependency(): void {
		$composer = json_decode( (string) file_get_contents( ONESEARCH_DIR . 'composer.json' ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		$lock     = json_decode( (string) file_get_contents( ONESEARCH_DIR . 'composer.lock' ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown

		$this->assertArrayHasKey( 'woocommerce/action-scheduler', $composer['require'] );
		$this->assertContains( 'woocommerce/action-scheduler', array_column( $lock['packages'], 'name' ), 'It must be locked as a runtime package, not a dev one.' );
		$this->assertFileExists( ONESEARCH_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php', 'onesearch.php loads Action Scheduler from here.' );
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ), 'Action Scheduler is loaded once the plugin is.' );
	}
}
