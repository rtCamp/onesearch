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
 * x-release-please-start-version
 * Version:           1.0.1
 * x-release-please-end
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
	define( 'ONESEARCH_VERSION', '1.0.1' ); // x-release-please-version.

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

// Load the plugin.
if ( class_exists( '\OneSearch\Main' ) ) {
	\OneSearch\Main::instance();
}
