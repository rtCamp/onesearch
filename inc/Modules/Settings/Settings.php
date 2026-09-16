<?php
/**
 * Registers the plugin's settings and options
 *
 * @package OneSearch\Modules\Settings
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Settings;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Encryptor;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Utils;

/**
 * Class - Settings
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
	// Shared settings.
	public const OPTION_SITE_TYPE = self::SETTING_PREFIX . 'site_type';

	// Consumer settings.
	public const OPTION_CONSUMER_API_KEY         = self::SETTING_PREFIX . 'consumer_api_key';
	public const OPTION_CONSUMER_PARENT_SITE_URL = self::SETTING_PREFIX . 'parent_site_url';

	// Governing settings.
	public const OPTION_GOVERNING_SHARED_SITES = self::SETTING_PREFIX . 'shared_sites';

	/**
	 * Site type keys.
	 */
	public const SITE_TYPE_CONSUMER  = 'brand-site';
	public const SITE_TYPE_GOVERNING = 'governing-site';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'rest_api_init', [ $this, 'register_settings' ] );

		// Listen to updates.
		add_action( 'update_option_' . self::OPTION_SITE_TYPE, [ $this, 'on_site_type_change' ], 10, 2 );
		add_action( 'update_option_' . self::OPTION_GOVERNING_SHARED_SITES, [ $this, 'on_brand_site_removed' ], 10, 2 );
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		$shared_settings = [
			self::OPTION_SITE_TYPE => [
				'type'              => 'string',
				'label'             => __( 'Site Type', 'onesearch' ),
				'description'       => __( 'Defines whether this site is a governing or a brand site.', 'onesearch' ),
				'sanitize_callback' => static function ( $value ): string {
					$valid_values = [
						self::SITE_TYPE_CONSUMER  => true,
						self::SITE_TYPE_GOVERNING => true,
					];

					return is_string( $value ) && isset( $valid_values[ $value ] ) ? $value : '';
				},
				'show_in_rest'      => [
					'schema' => [
						'enum' => [ self::SITE_TYPE_CONSUMER, self::SITE_TYPE_GOVERNING ],
					],
				],
			],
		];

		$consumer_settings = [
			self::OPTION_CONSUMER_API_KEY         => [
				'type'              => 'string',
				'label'             => __( 'Consumer API Key', 'onesearch' ),
				'description'       => __( 'API key used by governing site to authenticate requests from this consumer site.', 'onesearch' ),
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => [
					'schema' => [
						'type' => 'string',
					],
				],
			],
			self::OPTION_CONSUMER_PARENT_SITE_URL => [
				'type'              => 'string',
				'label'             => __( 'Parent Site URL', 'onesearch' ),
				'description'       => __( 'The URL of the governing site that manages this consumer site.', 'onesearch' ),
				'sanitize_callback' => static function ( $value ) {
					return is_string( $value ) ? untrailingslashit( esc_url_raw( $value ) ) : null;
				},
				'show_in_rest'      => [
					'schema' => [
						'type'   => 'string',
						'format' => 'uri',
					],
				],
			],
		];

		$governing_settings = [
			self::OPTION_GOVERNING_SHARED_SITES => [
				'type'              => 'array',
				'label'             => __( 'Brand Sites', 'onesearch' ),
				'description'       => __( 'An array of brand sites connected to this governing site.', 'onesearch' ),
				'sanitize_callback' => [ self::class, 'sanitize_shared_sites' ],
				'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'      => [
									'type' => 'string',
								],
								'name'    => [
									'type' => 'string',
								],
								'url'     => [
									'type'   => 'string',
									'format' => 'uri',
								],
								'logo'    => [
									'type'   => 'string',
									'format' => 'uri',
								],
								'logo_id' => [
									'type' => 'integer',
								],
								'api_key' => [
									'type' => 'string',
								],
							],
						],
					],
				],
			],
		];

		$all_settings = array_merge(
			$shared_settings,
			self::is_consumer_site() ? $consumer_settings : $governing_settings
		);

		foreach ( $all_settings as $key => $args ) {
			register_setting(
				self::SETTING_GROUP,
				$key,
				$args
			);
		}
	}

	/**
	 * Ensures the API key is generated when the site type changes to 'consumer'.
	 *
	 * @internal Hook callback
	 *
	 * @param mixed $old_value The old value.
	 * @param mixed $new_value The new value.
	 */
	public function on_site_type_change( $old_value, $new_value ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		if ( self::SITE_TYPE_CONSUMER !== $new_value ) {
			return;
		}

		// By getting the API key, it will be generated if it doesn't exist.
		self::get_api_key();
	}

	/**
	 * Tells brand sites that were dropped from this governing site to clear their pairing.
	 *
	 * Without this, a removed site keeps naming this one as its governing site and
	 * keeps serving its cached config.
	 *
	 * @internal Hook callback
	 *
	 * @param mixed $old_value The old value.
	 * @param mixed $new_value The new value.
	 */
	public function on_brand_site_removed( $old_value, $new_value ): void {
		if ( ! is_array( $old_value ) || empty( $old_value ) ) {
			return;
		}

		$remaining_urls = [];
		foreach ( is_array( $new_value ) ? $new_value : [] as $site ) {
			if ( ! empty( $site['url'] ) ) {
				$remaining_urls[ Utils::normalize_url( $site['url'] ) ] = true;
			}
		}

		// The API keys of removed sites only exist in the old value.
		$removed_sites = [];
		$site_names    = [];
		foreach ( $old_value as $site ) {
			if ( empty( $site['url'] ) || empty( $site['api_key'] ) ) {
				continue;
			}

			$site_url = Utils::normalize_url( $site['url'] );
			if ( isset( $remaining_urls[ $site_url ] ) ) {
				continue;
			}

			$api_key = Encryptor::decrypt( $site['api_key'] );
			if ( empty( $api_key ) ) {
				continue;
			}

			$removed_sites[ $site_url ] = $api_key;
			$site_names[ $site_url ]    = ! empty( $site['name'] ) ? (string) $site['name'] : $site_url;
		}

		if ( empty( $removed_sites ) ) {
			return;
		}

		Governing_Data_Handler::notify_brand_sites_of_disconnection( $removed_sites, $site_names );
	}

	/**
	 * Sanitize the `shared_sites` option.
	 *
	 * @param mixed $input The input value.
	 *
	 * @return array{
	 * id: string,
	 * name: string,
	 * url: string,
	 * logo: string,
	 * logo_id: int,
	 * api_key: string
	 * }[]
	 */
	public static function sanitize_shared_sites( $input ): array {
		if ( ! is_array( $input ) || empty( $input ) ) {
			return [];
		}

		$sanitized = [];

		foreach ( $input as $site_data ) {
			if ( ! is_array( $site_data ) ) {
				continue;
			}

			$site_id      = isset( $site_data['id'] ) ? sanitize_text_field( $site_data['id'] ) : '';
			$site_name    = isset( $site_data['name'] ) ? sanitize_text_field( $site_data['name'] ) : '';
			$site_url     = isset( $site_data['url'] ) ? esc_url_raw( $site_data['url'] ) : '';
			$site_logo    = isset( $site_data['logo'] ) ? esc_url_raw( $site_data['logo'] ) : '';
			$site_logo_id = isset( $site_data['logo_id'] ) ? absint( $site_data['logo_id'] ) : 0;
			$site_api_key = isset( $site_data['api_key'] ) ? sanitize_text_field( $site_data['api_key'] ) : '';

			// Only save if required fields are filled.
			if ( empty( $site_name ) || empty( $site_url ) ) {
				continue;
			}

			$sanitized[] = [
				'id'      => $site_id ?: wp_generate_uuid4(),
				'name'    => $site_name,
				'url'     => untrailingslashit( $site_url ),
				'logo'    => $site_logo,
				'logo_id' => $site_logo_id,
				'api_key' => $site_api_key,
			];
		}

		return $sanitized;
	}

	/**
	 * Static setters and getters for the individual settings.
	 */

	/**
	 * Get brand sites configured for this governing site, keyed by the (trailing-slash) URL.
	 *
	 * @return array<string,array{
	 *  api_key: string,
	 *  id: string,
	 *  logo: string,
	 *  logo_id: int,
	 *  name: string,
	 *  url: string,
	 * }>
	 */
	public static function get_shared_sites(): array {
		$brands = get_option( self::OPTION_GOVERNING_SHARED_SITES, null ) ?: [];

		$brands_to_return = [];
		foreach ( $brands as $brand ) {
			if ( empty( $brand['url'] ) ) {
				continue;
			}

			$url                      = trailingslashit( $brand['url'] );
			$brands_to_return[ $url ] = self::hydrate_shared_site( $brand, $url );
		}

		return $brands_to_return;
	}

	/**
	 * Get a single brand site by URL
	 *
	 * @param string $site_url The site URL.
	 *
	 * @return ?array{
	 *   api_key: string,
	 *   id: string,
	 *   logo: string,
	 *   logo_id: int,
	 *   name: string,
	 *   url: string,
	 * }
	 */
	public static function get_shared_site_by_url( string $site_url ): ?array {
		$brand_sites = self::get_shared_sites();

		$normalized_url = trailingslashit( $site_url );

		return $brand_sites[ $normalized_url ] ?? null;
	}

	/**
	 * Set the shared sites.
	 *
	 * @param array<string,array<string,mixed>> $sites The sites to set.
	 *
	 * @phpstan-param array<string,array{
	 *   api_key?: string,
	 *   id?: string,
	 *   logo?: string,
	 *   logo_id?: int,
	 *   name?: string,
	 *   url?: string,
	 *   is_editable?: bool
	 * }> $sites The sites to set.
	 */
	public static function set_shared_sites( array $sites ): bool {
		foreach ( $sites as &$site ) {
			if ( empty( $site['api_key'] ) || empty( $site['url'] ) ) {
				continue;
			}
			// Ensure URLs are trailing-slashed.
			$site['url'] = trailingslashit( $site['url'] );

			// Encrypt API keys before saving.
			$encrypted_key = Encryptor::encrypt( $site['api_key'] );

			// Bail if encryption fails.
			if ( false === $encrypted_key ) {
				return false;
			}

			$site['api_key'] = $encrypted_key;
		}

		return update_option( self::OPTION_GOVERNING_SHARED_SITES, array_values( $sites ), false );
	}

	/**
	 * Atomically removes a single brand site from the shared-sites option.
	 *
	 * A plain get-modify-update round trip lets two concurrent disconnects each read the
	 * same snapshot and overwrite each other, resurrecting whichever site the other request
	 * removed. This instead compare-and-swaps the raw option value, retrying against a fresh
	 * read whenever another process wrote in between.
	 *
	 * @param string $site_url           Brand site URL to remove.
	 * @param bool   $is_self_disconnect Whether this site is disconnecting itself, so it
	 *                                   should not be sent a disconnection notice back.
	 *
	 * @return array{api_key:string,id:string,logo:string,logo_id:int,name:string,url:string}|false|null
	 *         The removed site's (decrypted) data, null if it was already absent, or false if
	 *         the write could not be applied after retrying.
	 */
	public static function remove_shared_site( string $site_url, bool $is_self_disconnect = false ) {
		$site_url = trailingslashit( $site_url );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$raw_sites = get_option( self::OPTION_GOVERNING_SHARED_SITES, [] );
			$raw_sites = is_array( $raw_sites ) ? $raw_sites : [];

			$removed  = null;
			$filtered = [];
			foreach ( $raw_sites as $site ) {
				if ( null === $removed && ! empty( $site['url'] ) && trailingslashit( $site['url'] ) === $site_url ) {
					$removed = $site;
					continue;
				}
				$filtered[] = $site;
			}

			if ( null === $removed ) {
				return null;
			}

			/*
			 * The removal below fires the shared-sites-changed notification synchronously,
			 * so this has to be set immediately before it, not after.
			 */
			if ( $is_self_disconnect ) {
				Governing_Data_Handler::suppress_disconnect_notice( $site_url );
			}

			if ( self::compare_and_swap_option( self::OPTION_GOVERNING_SHARED_SITES, $raw_sites, $filtered ) ) {
				return self::hydrate_shared_site( $removed, $site_url );
			}

			// Another process wrote to the option first; back off briefly and retry against fresh data.
			usleep( wp_rand( 1000, 5000 ) );
		}

		return false;
	}

	/**
	 * Builds a shared site's public shape from its raw stored row, decrypting its API key.
	 *
	 * @param array<string,mixed> $raw The raw stored site row.
	 * @param string              $url The (already trailing-slashed) URL to record it under.
	 *
	 * @return array{api_key:string,id:string,logo:string,logo_id:int,name:string,url:string}
	 */
	private static function hydrate_shared_site( array $raw, string $url ): array {
		return [
			'api_key' => ! empty( $raw['api_key'] ) ? ( Encryptor::decrypt( $raw['api_key'] ) ?: '' ) : '',
			'id'      => $raw['id'] ?? '',
			'logo'    => $raw['logo'] ?? '',
			'logo_id' => $raw['logo_id'] ?? 0,
			'name'    => $raw['name'] ?? '',
			'url'     => $url,
		];
	}

	/**
	 * Replaces an option's stored value only if it still matches the value read just before.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $expected      The value read immediately before this call.
	 * @param mixed  $new_value     The value to write if nothing has changed since.
	 */
	private static function compare_and_swap_option( string $option, $expected, $new_value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Compare-and-swap has no wpdb/options-API equivalent.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( array_values( $new_value ) ),
				$option,
				maybe_serialize( $expected )
			)
		);

		if ( ! $updated ) {
			return false;
		}

		wp_cache_delete( $option, 'options' );

		/*
		 * update_option() fires these around its own write; other code (e.g. the brand-disconnect
		 * notice and the search-settings cleanup) depends on them, so this has to replicate them
		 * for a direct write to behave the same as going through the Options API.
		 */
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Replicating core's own update_option() hooks, not inventing new ones.
		do_action( 'update_option', $option, $expected, $new_value );
		do_action( "update_option_{$option}", $expected, $new_value, $option );
		do_action( 'updated_option', $option, $expected, $new_value );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return true;
	}

	/**
	 * Get the current site type.
	 */
	public static function get_site_type(): ?string {
		$value = get_option( self::OPTION_SITE_TYPE, null );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Check if the current site is a governing site.
	 */
	public static function is_governing_site(): bool {
		return self::SITE_TYPE_GOVERNING === self::get_site_type();
	}

	/**
	 * Check if the current site is a consumer site.
	 */
	public static function is_consumer_site(): bool {
		return self::SITE_TYPE_CONSUMER === self::get_site_type();
	}

	/**
	 * Gets the API key, generating a new one if it doesn't exist.
	 *
	 * Returns an empty string on failure.
	 */
	public static function get_api_key(): string {
		$api_key = get_option( self::OPTION_CONSUMER_API_KEY, '' );

		$api_key = ! empty( $api_key ) ? Encryptor::decrypt( $api_key ) : self::regenerate_api_key();

		return $api_key ?: '';
	}

	/**
	 * Regenerates the API key.
	 *
	 * @return string The new (unencrypted) API key.
	 */
	public static function regenerate_api_key(): string {
		$api_key = self::generate_api_key();

		$encrypted_key = Encryptor::encrypt( $api_key );

		if ( ! $encrypted_key ) {
			return '';
		}

		update_option( self::OPTION_CONSUMER_API_KEY, $encrypted_key, false );

		return $api_key;
	}

	/**
	 * Get the parent URL for consumer sites.
	 */
	public static function get_parent_site_url(): ?string {
		$value = get_option( self::OPTION_CONSUMER_PARENT_SITE_URL, null );
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Set the parent URL for consumer sites.
	 *
	 * @param string $url The parent site URL.
	 */
	public static function set_parent_site_url( string $url ): bool {
		return update_option( self::OPTION_CONSUMER_PARENT_SITE_URL, untrailingslashit( esc_url_raw( $url ) ), false );
	}

	/**
	 * Generate a random API key.
	 */
	private static function generate_api_key(): string {
		return wp_generate_password( 128, false, false );
	}
}
