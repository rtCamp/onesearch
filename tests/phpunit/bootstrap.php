<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * @package OneSearch\Tests
 *
 * phpcs:disable WordPressVIPMinimum.Files.IncludingFile.UsingVariable
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
 */

declare( strict_types = 1 );

define( 'TESTS_REPO_ROOT_DIR', dirname( __DIR__, 2 ) );

// Load Composer dependencies if applicable.
if ( file_exists( TESTS_REPO_ROOT_DIR . '/vendor/autoload.php' ) ) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/autoload.php';
}

// Detect where to load the WordPress tests environment from.
if ( false !== getenv( 'WP_TESTS_DIR' ) ) {
	$_test_root = getenv( 'WP_TESTS_DIR' );
} elseif ( false !== getenv( 'WP_DEVELOP_DIR' ) ) {
	$_test_root = getenv( 'WP_DEVELOP_DIR' ) . '/tests/phpunit';
} elseif ( false !== getenv( 'WP_PHPUNIT__DIR' ) ) {
	$_test_root = getenv( 'WP_PHPUNIT__DIR' );
} elseif ( file_exists( TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit/includes/functions.php' ) ) {
	$_test_root = TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit';
} else { // Fallback.
	$_test_root = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_test_root . '/includes/functions.php';

// Activate the plugin.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// Composer loaded Action Scheduler before WordPress, when it can't register itself yet.
		require TESTS_REPO_ROOT_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

		// Require ( to bypass require_once ).
		require TESTS_REPO_ROOT_DIR . '/onesearch.php';
	}
);

// Tests run the queued jobs themselves, so Action Scheduler doesn't send requests to start its runner.
tests_add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );

// Skip Action Scheduler's migration status check.
tests_add_filter( 'pre_option_action_scheduler_migration_status', static fn () => 'complete' );

// Start up the WP testing environment.
require $_test_root . '/includes/bootstrap.php';
