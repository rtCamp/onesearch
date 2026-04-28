<?php
/**
 * Algolia service wrapper.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Vendor\Algolia\AlgoliaSearch\SearchClient;

/**
 * Class - Algolia
 */
final class Algolia {
	/**
	 * Get the index object for the current site.
	 */
	public function get_index(): \OneSearch\Vendor\Algolia\AlgoliaSearch\SearchIndex|\WP_Error {
		$index_name = $this->get_index_name();

		if ( empty( $index_name ) ) {
			return new \WP_Error(
				'algolia_index_name_invalid',
				__( 'Algolia index name could not be determined.', 'onesearch' )
			);
		}

		$client = $this->get_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		return $client->initIndex( $index_name );
	}

	/**
	 * Create an Algolia client using stored credentials.
	 */
	private function get_client(): \OneSearch\Vendor\Algolia\AlgoliaSearch\SearchClient|\WP_Error {
		$creds = $this->get_algolia_credentials();

		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		if ( empty( $creds['app_id'] ) || empty( $creds['write_key'] ) ) {
			return new \WP_Error(
				'algolia_credentials_missing',
				__( 'Algolia admin credentials missing.', 'onesearch' )
			);
		}

		return SearchClient::create( $creds['app_id'], $creds['write_key'] );
	}

	/**
	 * Gets the index name.
	 *
	 * Returns empty string if the index name could not be determined.
	 */
	private function get_index_name(): string {
		$site_url = Settings::is_governing_site()
			? get_site_url()
			: Settings::get_parent_site_url();

		if ( empty( $site_url ) ) {
			return '';
		}

		$parsed_url = wp_parse_url( $site_url );
		$site_name  = ! empty( $parsed_url['host'] ) ? $parsed_url['host'] : null;

		if ( null === $site_name ) {
			return '';
		}

		$site_name = str_replace( '.', '_', $site_name );

		return sprintf( 'onesearch_%s_wp_posts', sanitize_title( $site_name ) );
	}

	/**
	 * Get algolia credentials.
	 *
	 * If on a child site, the credentials are fetched from the governing site.
	 *
	 * @return array{
	 *   app_id: ?string,
	 *   write_key: ?string,
	 * }|\WP_Error
	 */
	private function get_algolia_credentials(): array|\WP_Error {
		if ( Settings::is_governing_site() ) {
			return Search_Settings::get_algolia_credentials();
		}

		// For brand sites, fetch from the consolidated config.
		$config = Governing_Data_Handler::get_brand_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return [
			'app_id'    => $config['algolia_credentials']['app_id'] ?? null,
			'write_key' => $config['algolia_credentials']['write_key'] ?? null,
		];
	}
}
