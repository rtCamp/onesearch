<?php
/**
 * Deletes the plugin's data when it's uninstalled, or deactivated if the site opted in.
 *
 * @package OneSearch\Modules\Core
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Core;

use OneSearch\Jobs\Queue;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - Uninstaller
 */
final class Uninstaller {
	/**
	 * The prefix of the plugin's options and meta.
	 */
	private const PLUGIN_PREFIX = 'onesearch_';

	/**
	 * Uninstalls the plugin. If multisite, uninstalls from all sites.
	 */
	public static function uninstall(): void {
		self::on_each_site( static fn () => self::uninstall_site( true ) );
	}

	/**
	 * Uninstalls the plugin on deactivation from the sites that opted in, since hosts like WordPress VIP never uninstall plugins.
	 *
	 * @internal Hook callback
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated for the whole network.
	 */
	public static function deactivate( $network_wide ): void {
		$maybe_uninstall_site = static function () use ( $network_wide ): void {
			if ( $network_wide && in_array( plugin_basename( ONESEARCH_FILE ), (array) get_option( 'active_plugins', [] ), true ) ) {
				return;
			}

			if ( Settings::should_delete_data_on_deactivation() ) {
				self::uninstall_site( false );
			}
		};

		// Deactivating for the network leaves the plugin active on the other networks.
		if ( $network_wide ) {
			self::on_each_site( $maybe_uninstall_site, get_current_network_id() );
			return;
		}

		$maybe_uninstall_site();
	}

	/**
	 * Runs a callback on each site. If not multisite, runs it on the current site.
	 *
	 * @param callable $callback   The callback to run.
	 * @param int      $network_id Only runs it on this network's sites. Default 0, every network.
	 */
	private static function on_each_site( callable $callback, int $network_id = 0 ): void {
		if ( ! is_multisite() ) {
			$callback();
			return;
		}

		$site_ids = get_sites(
			[
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => $network_id,
			]
		) ?: [];

		foreach ( $site_ids as $site_id ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- The state doesn't matter while deleting the plugin's data.
			if ( ! switch_to_blog( (int) $site_id ) ) {
				continue;
			}

			$callback();
			restore_current_blog();
		}
	}

	/**
	 * The (site-specific) uninstall function.
	 *
	 * @param bool $skip_network_sites Whether to skip the sites on the network.
	 */
	private static function uninstall_site( bool $skip_network_sites ): void {
		// Delete the job records first, or deleting each waiting job updates its record.
		self::delete_job_records();
		self::delete_pending_jobs();
		self::cleanup_algolia_index( $skip_network_sites );
		self::disconnect_sites( $skip_network_sites );
		self::delete_proxy_attachment();
		self::delete_index_status_meta();

		// Wait until the end to delete options and transients.
		self::delete_transients();
		self::delete_options();
	}

	/**
	 * Deletes the plugin's background jobs that are waiting to run.
	 */
	private static function delete_pending_jobs(): void {
		( new Queue( 'onesearch' ) )->delete_pending();
	}

	/**
	 * Delete the shared "proxy" attachment that 1.1.x and earlier used for remote post thumbnails.
	 */
	private static function delete_proxy_attachment(): void {
		$proxy_id = (int) get_option( self::PLUGIN_PREFIX . 'proxy_attachment_id', 0 );
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
	 * Deletes when each post was indexed, or why it couldn't be.
	 */
	private static function delete_index_status_meta(): void {
		delete_post_meta_by_key( '_' . self::PLUGIN_PREFIX . 'indexed_at' );
		delete_post_meta_by_key( '_' . self::PLUGIN_PREFIX . 'index_error' );
	}

	/**
	 * Deletes options.
	 */
	private static function delete_options(): void {
		$options = [
			// Shared options.
			self::PLUGIN_PREFIX . 'delete_data_on_deactivation',
			self::PLUGIN_PREFIX . 'site_type',

			// Governing site options.
			self::PLUGIN_PREFIX . 'shared_sites',
			self::PLUGIN_PREFIX . 'indexable_entities',
			self::PLUGIN_PREFIX . 'algolia_credentials',
			self::PLUGIN_PREFIX . 'sites_search_settings',

			// Brand site options.
			self::PLUGIN_PREFIX . 'parent_site_url',
			self::PLUGIN_PREFIX . 'consumer_api_key',

			// 1.1.x and earlier: the shared proxy attachment used for remote post thumbnails.
			self::PLUGIN_PREFIX . 'proxy_attachment_id',
		];

		foreach ( $options as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Tells the connected sites this site is going away.
	 *
	 * @param bool $skip_network_sites Whether to skip the sites on the network.
	 */
	private static function disconnect_sites( bool $skip_network_sites ): void {
		if ( Settings::is_governing_site() ) {
			// Governing sites tell their brand sites to forget them.
			foreach ( Settings::get_shared_sites() as $site_data ) {
				if ( ! $skip_network_sites || ! self::is_network_site_url( $site_data['url'] ) ) {
					Remote_Client::disconnect_brand_site( $site_data );
				}
			}

			return;
		}

		// Brand sites deregister from the governing site, while they still have its URL and their key.
		$governing_site_url = (string) Settings::get_parent_site_url();
		if ( ! Settings::is_consumer_site() || '' === $governing_site_url || ( $skip_network_sites && self::is_network_site_url( $governing_site_url ) ) ) {
			return;
		}

		$deregistered = Remote_Client::deregister_from_governing_site();

		if ( is_wp_error( $deregistered ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Nowhere else to report it while deleting the plugin's data.
			error_log( sprintf( 'OneSearch: Deregistering from the governing site failed: %s', $deregistered->get_error_message() ) );
		}
	}

	/**
	 * Deletes transients.
	 */
	private static function delete_transients(): void {
		$transients = [
			// Governing site transients.
			self::PLUGIN_PREFIX . 'brand_config_cache',
		];

		foreach ( $transients as $transient ) {
			delete_transient( $transient );
		}
	}

	/**
	 * Cleans up entries from the Algolia index, or the index itself if governing site.
	 *
	 * @param bool $skip_network_sites Whether to skip the sites on the network.
	 */
	private static function cleanup_algolia_index( bool $skip_network_sites ): void {
		if ( Settings::is_governing_site() ) {
			$is_deleted = ( new Indexer() )->delete_index();
		} else {
			$governing_site_url = (string) Settings::get_parent_site_url();
			if ( ! Settings::is_consumer_site() || '' === $governing_site_url || ( $skip_network_sites && self::is_network_site_url( $governing_site_url ) ) ) {
				return;
			}

			$is_deleted = ( new Indexer() )->delete_site_records( [ Utils::get_current_site_url() ] );
		}

		if ( is_wp_error( $is_deleted ) ) {
			$data = $is_deleted->get_error_data();

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Nowhere else to report it while deleting the plugin's data.
			error_log( sprintf( 'OneSearch: Algolia cleanup failed: %s %s', $is_deleted->get_error_message(), $data['message'] ?? '' ) );
		}
	}

	/**
	 * Whether a URL is the home URL of a site on the network.
	 *
	 * @param string $url The URL.
	 */
	private static function is_network_site_url( string $url ): bool {
		$network_site_urls = array_map(
			static fn ( \WP_Site $site ): string => Utils::normalize_url( (string) $site->home ),
			is_multisite() ? get_sites( [ 'number' => 0 ] ) : []
		);

		return in_array( Utils::normalize_url( $url ), $network_site_urls, true );
	}

	/**
	 * Deletes the background jobs' records, which have an option each.
	 */
	private static function delete_job_records(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API deletes options by name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_' . self::PLUGIN_PREFIX ) . '%' ) );
	}
}
