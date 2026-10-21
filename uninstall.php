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
	delete_pending_jobs_and_brand_progress();
	cleanup_algolia_index();
	delete_proxy_attachment();

	// Wait until the end to delete options and transients.

	delete_transients();
	delete_options();
}

/**
 * Delete the shared "proxy" attachment that 1.1.x and earlier used for remote post thumbnails.
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

		// Governing site options.
		PLUGIN_PREFIX . 'site_type',
		PLUGIN_PREFIX . 'shared_sites',
		PLUGIN_PREFIX . 'indexable_entities',
		PLUGIN_PREFIX . 'algolia_credentials',
		PLUGIN_PREFIX . 'sites_search_settings',

		// Brand site options.
		PLUGIN_PREFIX . 'parent_site_url',
		PLUGIN_PREFIX . 'consumer_api_key',

		// The progress of the site's latest indexing run.
		PLUGIN_PREFIX . 'index_progress',

		// 1.1.x and earlier: the shared proxy attachment used for remote post thumbnails.
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
 * Deletes the plugin's pending background jobs, and the indexing progress of a governing site's brand sites.
 */
function delete_pending_jobs_and_brand_progress(): void {
	if ( ! load_dependencies() ) {
		return;
	}

	( new \OneSearch\Jobs\Queue() )->delete_pending();

	foreach ( array_keys( \OneSearch\Modules\Settings\Settings::get_shared_sites() ) as $site_url ) {
		\OneSearch\Modules\Search\Indexer::delete_brand_progress( $site_url );
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

	$search_index = new \OneSearch\Modules\Search\Search_Index();

	// The index is shared by all sites, so brand sites only delete their own records.
	if ( \OneSearch\Modules\Settings\Settings::is_governing_site() ) {
		// Governing sites tell their brand sites to forget them, and delete the shared index.
		foreach ( \OneSearch\Modules\Settings\Settings::get_shared_sites() as $site_data ) {
			\OneSearch\Modules\Rest\Remote_Client::disconnect_brand_site( $site_data );
		}

		$is_deleted = $search_index->delete_index();
	} else {
		// Consumer sites need to delete their own records, and deregister from the governing site.
		$is_deleted = $search_index->delete_site_records( [ \OneSearch\Utils::get_current_site_url() ] );

		// Deregister after deletion, or deregistration may fail.
		$deregistered = \OneSearch\Modules\Rest\Remote_Client::deregister_from_governing_site();

		if ( is_wp_error( $deregistered ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Nowhere else to report it during uninstall.
			error_log( sprintf( 'OneSearch uninstall: Deregistering from the governing site failed: %s', $deregistered->get_error_message() ) );
		}
	}

	if ( is_wp_error( $is_deleted ) ) {
		$data = $is_deleted->get_error_data();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Nowhere else to report it during uninstall.
		error_log( sprintf( 'OneSearch uninstall: Algolia cleanup failed: %s %s', $is_deleted->get_error_message(), $data['message'] ?? '' ) );
	}
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

	// The main plugin file isn't loaded during uninstall, but the autoloader needs this.
	if ( ! defined( 'ONESEARCH_DIR' ) ) {
		define( 'ONESEARCH_DIR', plugin_dir_path( __FILE__ ) );
	}

	require_once $autoloader_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

	// If the autoloader succeeded we have what we need.
	return class_exists( '\OneSearch\Autoloader' ) && \OneSearch\Autoloader::autoload();
}

// Run the uninstaller.
run_uninstaller();
