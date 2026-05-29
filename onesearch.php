<?php
/**
 * OneSearch
 *
 * @package           OneSearch
 * @author            rtCamp
 * @copyright         2025 rtCamp
 * @license           GPL-2.0-or-later
 *
 * Plugin Name:       OneSearch
 * Plugin URI:        https://github.com/rtCamp/onesearch
 * Description:       This plugin allows you to run multi-index, multi-site searches seamlessly, without duplicate or missing results.
 * Author:            rtCamp
 * Author URI:        https://rtcamp.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       onesearch
 * Domain Path:       /languages
 * Version:           1.0.1
 * Requires PHP:      8.2
 * Requires at least: 6.8
 * Tested up to:      6.9
 */

declare( strict_types = 1 );

namespace OneSearch;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Define the plugin constants.
 */
function constants(): void {
	/**
	 * File path to the plugin's main file.
	 */
	define( 'ONESEARCH_FILE', __FILE__ );

	/**
	 * Version of the plugin.
	 */
	define( 'ONESEARCH_VERSION', '1.0.1' );

	/**
	 * Root path to the plugin directory.
	 */
	define( 'ONESEARCH_DIR', plugin_dir_path( __FILE__ ) );

	/**
	 * Root URL to the plugin directory.
	 */
	define( 'ONESEARCH_URL', plugin_dir_url( __FILE__ ) );

	/**
	 * The plugin basename.
	 */
	define( 'ONESEARCH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

constants();

// If autoloader fails, we cannot proceed.
require_once __DIR__ . '/inc/Autoloader.php';
if ( ! \OneSearch\Autoloader::autoload() ) {
	return;
}

// Load Action Scheduler early, before plugins_loaded, so its own hooks register on time.
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	$onesearch_as_path = ONESEARCH_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
	if ( file_exists( $onesearch_as_path ) ) {
		require_once $onesearch_as_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
	}
}

// Load the plugin.
if ( class_exists( '\OneSearch\Main' ) ) {
	\OneSearch\Main::instance();
}
