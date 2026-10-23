<?php
/**
 * Registers the plugin's search settings.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Encryptor;
use OneSearch\Jobs\Queue;
use OneSearch\Modules\Settings\Settings as Admin_Settings;
use OneSearch\Utils;

/**
 * Class - Settings
 *
 * `IndexableEntities` holds the post types each site indexes, keyed by normalized site URL.
 *
 * @phpstan-type IndexableEntities array{
 *   entities?: array<string, string[]>,
 * }
 */
final class Settings implements Registrable {
	/**
	 * The setting prefix.
	 */
	private const SETTING_PREFIX = 'onesearch_';

	/**
	 * The setting group.
	 */
	public const SETTING_GROUP = self::SETTING_PREFIX . 'settings';

	/**
	 * Setting keys
	 */
	// Governing settings.
	public const OPTION_GOVERNING_ALGOLIA_CREDENTIALS = self::SETTING_PREFIX . 'algolia_credentials';
	public const OPTION_GOVERNING_INDEXABLE_SITES     = self::SETTING_PREFIX . 'indexable_entities';
	public const OPTION_GOVERNING_SEARCH_SETTINGS     = self::SETTING_PREFIX . 'sites_search_settings';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'rest_api_init', [ $this, 'register_settings' ] );

		// Listen to updates.
		add_action( 'update_option', [ $this, 'on_site_type_change' ], 10, 3 );
		add_action( 'update_option_' . Admin_Settings::OPTION_GOVERNING_SHARED_SITES, [ $this, 'on_shared_sites_change' ], 10, 2 );

		// Purge algolia caches when search settings change.
		foreach ( [
			self::OPTION_GOVERNING_ALGOLIA_CREDENTIALS,
			self::OPTION_GOVERNING_INDEXABLE_SITES,
			self::OPTION_GOVERNING_SEARCH_SETTINGS,
		] as $option ) {
			add_action( 'add_option_' . $option, [ $this, 'queue_brand_config_purges' ] );
			add_action( 'update_option_' . $option, [ $this, 'queue_brand_config_purges' ] );
		}
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {

		$governing_settings = [
			self::OPTION_GOVERNING_ALGOLIA_CREDENTIALS => [
				'type'              => 'object',
				'label'             => __( 'Algolia Credentials', 'onesearch' ),
				'description'       => __( 'Credentials used to connect to the Algolia service.', 'onesearch' ),
				'sanitize_callback' => static function ( $value ) {
					if ( ! is_array( $value ) ) {
						return null;
					}

					return [
						'app_id'    => isset( $value['app_id'] ) ? sanitize_text_field( $value['app_id'] ) : null,
						'write_key' => isset( $value['write_key'] ) ? sanitize_text_field( $value['write_key'] ) : null,
					];
				},
				// Core would return the encrypted write key and save a new one unencrypted, so it's saved through the OneSearch route.
				'show_in_rest'      => false,
			],
			self::OPTION_GOVERNING_INDEXABLE_SITES     => [
				'type'              => 'object',
				'label'             => __( 'Indexable Entities', 'onesearch' ),
				'description'       => __( 'List of content types that can be indexed by brand sites.', 'onesearch' ),
				'sanitize_callback' => static function ( $value ): array {
					$entities = is_array( $value ) && is_array( $value['entities'] ?? null ) ? $value['entities'] : [];

					$sanitized = [];
					foreach ( $entities as $site_url => $post_types ) {
						if ( ! is_array( $post_types ) ) {
							continue;
						}

						$sanitized[ Utils::normalize_url( (string) $site_url ) ] = array_values( array_unique( array_map( 'sanitize_key', array_filter( $post_types, 'is_string' ) ) ) );
					}

					return [ 'entities' => $sanitized ];
				},
				'show_in_rest'      => [
					'schema' => [
						'type'       => 'object',
						'properties' => [
							'entities' => [
								'type'                 => 'object',
								'additionalProperties' => [
									'type'  => 'array',
									'items' => [
										'type' => 'string',
									],
								],
							],
						],
					],
				],
			],
			self::OPTION_GOVERNING_SEARCH_SETTINGS     => [
				'type'              => 'object',
				'label'             => __( 'Sites Search Settings', 'onesearch' ),
				'description'       => __( 'Search settings for brand sites.', 'onesearch' ),
				'sanitize_callback' => static function ( $value ) {
					if ( ! is_array( $value ) ) {
						return [];
					}

					$sanitized = [];
					foreach ( $value as $site_url => $settings ) {
						$normalized_url = Utils::normalize_url( $site_url );
						if ( ! is_array( $settings ) ) {
							$sanitized[ $normalized_url ] = [
								'algolia_enabled'  => false,
								'searchable_sites' => [],
							];
							continue;
						}

						$sanitized[ $normalized_url ] = [
							'algolia_enabled'  => isset( $settings['algolia_enabled'] ) ? (bool) $settings['algolia_enabled'] : false,
							'searchable_sites' => isset( $settings['searchable_sites'] ) && is_array( $settings['searchable_sites'] ) ? array_map( 'sanitize_text_field', $settings['searchable_sites'] ) : [],
						];
					}

					return $sanitized;
				},
				'show_in_rest'      => [
					'schema' => [
						'type'                 => 'object',
						'properties'           => [],
						'additionalProperties' => [
							'type'       => 'object',
							'properties' => [
								'algolia_enabled'  => [
									'type' => 'boolean',
								],
								'searchable_sites' => [
									'type'  => 'array',
									'items' => [
										'type' => 'string',
									],
								],
							],
						],
					],
				],
			],
		];

		foreach ( $governing_settings as $key => $args ) {
			register_setting(
				self::SETTING_GROUP,
				$key,
				$args
			);
		}
	}

	/**
	 * Deletes the Algolia index when a governing site is changed to a consumer.
	 *
	 * @internal Hook callback
	 *
	 * @param string $option    The option name.
	 * @param mixed  $old_value The old value.
	 * @param mixed  $new_value The new value.
	 */
	public function on_site_type_change( $option, $old_value, $new_value ): void {
		if (
			Admin_Settings::OPTION_SITE_TYPE !== $option ||
			Admin_Settings::SITE_TYPE_GOVERNING !== $old_value ||
			Admin_Settings::SITE_TYPE_CONSUMER !== $new_value
		) {
			return;
		}

		( new Search_Index() )->delete_index();
	}

	/**
	 * Deletes algolia entries when a site is removed from the list of shared sites, and cleans it out of the search settings.
	 *
	 * @param mixed $old_value The old value.
	 * @param mixed $new_value The new value.
	 */
	public function on_shared_sites_change( $old_value, $new_value ): void {
		// If there is no old value, nothing to do.
		if ( ! is_array( $old_value ) || empty( $old_value ) ) {
			return;
		}

		$old_site_urls = array_map(
			static function ( $site ) {
				return ! empty( $site['url'] ) ? Utils::normalize_url( $site['url'] ) : null;
			},
			$old_value
		);
		$new_site_urls = is_array( $new_value ) ? array_map(
			static function ( $site ) {
				return ! empty( $site['url'] ) ? Utils::normalize_url( $site['url'] ) : null;
			},
			$new_value
		) : [];

		$removed_sites = array_filter( array_diff( $old_site_urls, $new_site_urls ) );
		if ( empty( $removed_sites ) ) {
			return;
		}

		$removed_sites = array_values( $removed_sites );

		foreach ( $removed_sites as $site_url ) {
			Indexer::delete_brand_progress( $site_url );
		}

		( new Queue() )->add( Indexer::DELETE_SITE_RECORDS_HOOK, [ $removed_sites ] );

		// Remove from indexable entities.
		$indexable_entities = self::get_indexable_entities();
		$entities_map       = $indexable_entities['entities'] ?? [];
		$updated_map        = array_diff_key( $entities_map, array_flip( $removed_sites ) );

		if ( $updated_map !== $entities_map ) {
			$indexable_entities['entities'] = $updated_map;
			update_option( self::OPTION_GOVERNING_INDEXABLE_SITES, $indexable_entities );
		}

		// Remove their search settings, and from the sites that could search them.
		$search_settings = self::get_search_settings();
		$updated_search  = array_diff_key( $search_settings, array_flip( $removed_sites ) );

		foreach ( $updated_search as $site_url => $settings ) {
			if ( ! is_array( $settings ) || ! is_array( $settings['searchable_sites'] ?? null ) ) {
				continue;
			}

			$updated_search[ $site_url ]['searchable_sites'] = array_values(
				array_filter(
					$settings['searchable_sites'],
					static fn ( $url ): bool => ! in_array( Utils::normalize_url( (string) $url ), $removed_sites, true )
				)
			);
		}

		if ( $updated_search !== $search_settings ) {
			update_option( self::OPTION_GOVERNING_SEARCH_SETTINGS, $updated_search );
		}
	}

	/**
	 * Queues a config purge for each brand site.
	 *
	 * @internal Hook callback
	 */
	public function queue_brand_config_purges(): void {
		if ( ! Admin_Settings::is_governing_site() ) {
			return;
		}

		$queue = new Queue();

		foreach ( array_keys( Admin_Settings::get_shared_sites() ) as $site_url ) {
			$queue->add( Indexer::PURGE_BRAND_CONFIG_HOOK, [ $site_url ], true );
		}
	}

	/**
	 * Get algolia credentials.
	 *
	 * @return array{
	 *   app_id: ?string,
	 *   write_key: ?string,
	 * }
	 */
	public static function get_algolia_credentials(): array {
		$creds = get_option( self::OPTION_GOVERNING_ALGOLIA_CREDENTIALS, [] );

		$decrypted_write_key = ! empty( $creds['write_key'] ) ? Encryptor::decrypt( $creds['write_key'] ) : null;

		return [
			'app_id'    => $creds['app_id'] ?? null,
			'write_key' => $decrypted_write_key ?: null,
		];
	}

	/**
	 * Sets the algolia credentials
	 *
	 * @param array<string,mixed> $value The credentials.
	 * @phpstan-param array{
	 *   app_id: string,
	 *   write_key: string,
	 * } $value
	 */
	public static function set_algolia_credentials( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$write_key = isset( $value['write_key'] ) ? sanitize_text_field( $value['write_key'] ) : null;
		$write_key = ! empty( $write_key ) ? Encryptor::encrypt( $write_key ) : null;

		$sanitized = [
			'app_id'    => isset( $value['app_id'] ) ? sanitize_text_field( $value['app_id'] ) : null,
			'write_key' => $write_key ?: null,
		];

		return update_option( self::OPTION_GOVERNING_ALGOLIA_CREDENTIALS, $sanitized );
	}

	/**
	 * Get the indexable entities.
	 *
	 * @return IndexableEntities The indexable entities.
	 */
	public static function get_indexable_entities(): array {
		$value = get_option( self::OPTION_GOVERNING_INDEXABLE_SITES, [] );
		return is_array( $value ) ? $value : [];
	}

	/**
	 * Get search settings for all sites.
	 *
	 * @return array<string, array{
	 *   algolia_enabled: bool,
	 *   searchable_sites: string[]
	 * }>
	 */
	public static function get_search_settings(): array {
		$value = get_option( self::OPTION_GOVERNING_SEARCH_SETTINGS, [] );
		return is_array( $value ) ? $value : [];
	}
}
