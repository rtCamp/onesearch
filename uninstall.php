<?php
/**
 * This will be executed when the plugin is uninstalled via the WordPress admin.
 *
 * @package OneSearch
 */

declare( strict_types = 1 );

namespace OneSearch;

// Only uninstall if called by WordPress.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// We use local constants so this plugin can be uninstalled even if the autoloader is corrupted or missing.
const PLUGIN_PREFIX = 'onesearch_';

/**
 * Uninstalls the plugin. If multisite, uninstalls from all sites.
 */
function run_uninstaller(): void {
	if ( ! is_multisite() ) {
		uninstall();
		return;
	}

	$site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) ?: [];

	foreach ( $site_ids as $site_id ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- The state doesn't matter during uninstall.
		if ( ! switch_to_blog( (int) $site_id ) ) {
			continue;
		}

		uninstall();
		restore_current_blog();
	}
}

/**
 * The (site-specific) uninstall function.
 */
function uninstall(): void {
	cleanup_algolia_index();

	// Wait until the end to delete options and transients.

	delete_transients();
	delete_options();
}

/**
 * Deletes options.
 */
function delete_options(): void {
	global $wpdb;

	$options = [
		// Add more options as needed.
		PLUGIN_PREFIX . 'version', // Set by Main::activate().

		// Governing site options.
		PLUGIN_PREFIX . 'site_type',
		PLUGIN_PREFIX . 'shared_sites',
		PLUGIN_PREFIX . 'indexable_entities',
		PLUGIN_PREFIX . 'algolia_credentials',
		PLUGIN_PREFIX . 'sites_search_settings',

		// Brand site options.
		PLUGIN_PREFIX . 'parent_site_url',
		PLUGIN_PREFIX . 'consumer_api_key',

		// Job scheduler options.
		PLUGIN_PREFIX . 'active_jobs',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Delete all job status options (onesearch_job_status_*).
	$job_status_options = $wpdb->get_col( // phpcs:ignore WordPressVIPMinimum.DirectDBQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			PLUGIN_PREFIX . 'job_status_%'
		)
	);

	foreach ( $job_status_options as $option_name ) {
		delete_option( $option_name );
	}
}

	/**
	 * Deletes transients.
	 */
function delete_transients(): void {
	global $wpdb;

	$transients = [
		// Governing site transients.
		PLUGIN_PREFIX . 'brand_config_cache',
	];

	foreach ( $transients as $transient ) {
		delete_transient( $transient );
	}

	// Delete all job status transients (onesearch_job_status_*).
	$job_transients = $wpdb->get_col( // phpcs:ignore WordPressVIPMinimum.DirectDBQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . PLUGIN_PREFIX . 'job_status_%',
			'_transient_timeout_' . PLUGIN_PREFIX . 'job_status_%'
		)
	);

	foreach ( $job_transients as $transient_name ) {
		if ( 0 === strpos( $transient_name, '_transient_timeout_' ) ) {
			delete_transient( substr( $transient_name, 18 ) );
		} elseif ( 0 === strpos( $transient_name, '_transient_' ) ) {
			delete_transient( substr( $transient_name, 11 ) );
		}
	}
}

/**
 * Cleans up entries from the Algolia index, or the index itself if governing site.
 */
function cleanup_algolia_index(): void {
	// Load required classes.
	if ( ! load_dependencies() ) {
		return;
	}

	$indexer = new \OneSearch\Modules\Search\Index();
	$indexer->delete_index();
}

/**
 * Load required plugin dependencies using the autoloader.
 *
 * @return bool True if dependencies loaded successfully.
 */
function load_dependencies(): bool {
	// Try to find and load the plugin's autoloader.
	$autoloader_path = __DIR__ . '/inc/Autoloader.php';
	if ( ! file_exists( $autoloader_path ) ) {
		return false;
	}

	require_once $autoloader_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

	// If the autoloader succeeded we have what we need.
	return class_exists( '\OneSearch\Autoloader' ) && \OneSearch\Autoloader::autoload();
}

// Run the uninstaller.
run_uninstaller();
