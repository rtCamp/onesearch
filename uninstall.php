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
	delete_proxy_attachment();

	// Wait until the end to delete options and transients.

	delete_transients();
	delete_options();
}

/**
 * Delete the shared "proxy" attachment used for remote post thumbnails.
 */
function delete_proxy_attachment(): void {
	$proxy_id = (int) get_option( PLUGIN_PREFIX . 'proxy_attachment_id', 0 );
	if ( $proxy_id <= 0 ) {
		return;
	}

	$proxy_post = get_post( $proxy_id );
	if ( ! $proxy_post instanceof \WP_Post || 'attachment' !== $proxy_post->post_type || 'onesearch-remote-attachment-proxy' !== $proxy_post->post_name ) {
		return;
	}

	wp_delete_post( $proxy_id, true );
}

/**
 * Deletes options.
 */
function delete_options(): void {
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

		// Shared proxy attachment used for remote post thumbnails.
		PLUGIN_PREFIX . 'proxy_attachment_id',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

/**
 * Deletes transients.
 */
function delete_transients(): void {
	$transients = [
		// Governing site transients.
		PLUGIN_PREFIX . 'brand_config_cache',
	];

	foreach ( $transients as $transient ) {
		delete_transient( $transient );
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
