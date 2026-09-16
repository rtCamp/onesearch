<?php
/**
 * Settings unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Settings
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Settings;

use OneSearch\Encryptor;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** Class SettingsTest */
#[CoversClass( Settings::class )]
final class SettingsTest extends TestCase {
	/** @var \OneSearch\Modules\Settings\Settings */
	private Settings $settings;

	/** {@inheritDoc} */
	protected function setUp(): void {
		parent::setUp();

		$this->settings = new Settings();
	}

	/** {@inheritDoc} */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		parent::tearDown();
	}

	/** Ensures the class can be instantiated and hook methods can be called without error. */
	public function test_class_instantiation(): void {
		$this->settings->register_hooks();
		$this->settings->register_settings();

		$this->assertTrue( true );
	}

	/** Ensures register_settings registers the site type setting. */
	public function test_register_settings_registers_site_type(): void {
		$this->settings->register_settings();

		$this->assertSettingRegistered( Settings::OPTION_SITE_TYPE );
	}

	/** Ensures register_settings registers consumer options. */
	public function test_register_settings_registers_consumer_options(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$this->settings->register_settings();

		$this->assertSettingRegistered( Settings::OPTION_CONSUMER_API_KEY );
		$this->assertSettingRegistered( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
	}

	/** Ensures register_settings registers governing options. */
	public function test_register_settings_registers_governing_options(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$this->settings->register_settings();

		$this->assertSettingRegistered( Settings::OPTION_GOVERNING_SHARED_SITES );
	}

	/** Ensures get_site_type returns null when not set. */
	public function test_get_site_type_returns_null_when_not_set(): void {
		delete_option( Settings::OPTION_SITE_TYPE );

		$this->assertNull( Settings::get_site_type() );
	}

	/** Ensures get_site_type returns a value when set. */
	public function test_get_site_type_returns_value_when_set(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$this->assertSame( Settings::SITE_TYPE_CONSUMER, Settings::get_site_type() );
	}

	/** Ensures governing site detection works. */
	public function test_is_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$this->assertTrue( Settings::is_governing_site() );
		$this->assertFalse( Settings::is_consumer_site() );
	}

	/** Ensures consumer site detection works. */
	public function test_is_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$this->assertTrue( Settings::is_consumer_site() );
		$this->assertFalse( Settings::is_governing_site() );
	}

	/** Ensures sanitize_shared_sites handles valid data. */
	public function test_sanitize_shared_sites_with_valid_data(): void {
		$sanitized = Settings::sanitize_shared_sites(
			[
				[
					'id'      => ' site-id ',
					'name'    => ' Demo Site ',
					'url'     => 'https://example.com/path/',
					'logo'    => 'https://example.com/logo.png',
					'logo_id' => '42',
					'api_key' => ' secret-key ',
				],
			]
		);

		$this->assertSame(
			[
				[
					'id'      => 'site-id',
					'name'    => 'Demo Site',
					'url'     => 'https://example.com/path',
					'logo'    => 'https://example.com/logo.png',
					'logo_id' => 42,
					'api_key' => 'secret-key',
				],
			],
			$sanitized
		);
	}

	/** Ensures sanitize_shared_sites returns an empty array for non-array input. */
	public function test_sanitize_shared_sites_returns_empty_for_non_array(): void {
		$this->assertSame( [], Settings::sanitize_shared_sites( 'not-an-array' ) );
	}

	/** Ensures sanitize_shared_sites generates a UUID for a missing ID. */
	public function test_sanitize_shared_sites_generates_uuid_for_missing_id(): void {
		$sanitized = Settings::sanitize_shared_sites(
			[
				[
					'name' => 'Demo Site',
					'url'  => 'https://example.com',
				],
			]
		);

		$this->assertCount( 1, $sanitized );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/i', $sanitized[0]['id'] );
	}

	/** Ensures sanitize_shared_sites skips entries without a name or URL. */
	public function test_sanitize_shared_sites_skips_sites_without_name_or_url(): void {
		$sanitized = Settings::sanitize_shared_sites(
			[
				[
					'name' => 'Missing URL',
				],
			]
		);

		$this->assertSame( [], $sanitized );
	}

	/** Ensures get_shared_sites returns an empty array when not set. */
	public function test_get_shared_sites_returns_empty_when_not_set(): void {
		$this->assertSame( [], Settings::get_shared_sites() );
	}

	/** Ensures shared sites round-trip through storage. */
	public function test_set_and_get_shared_sites_roundtrip(): void {
		$sites = [
			[
				'id'      => 'brand-1',
				'name'    => 'Brand One',
				'url'     => 'https://brand-one.example',
				'logo'    => 'https://brand-one.example/logo.png',
				'logo_id' => 11,
				'api_key' => 'brand-one-key',
			],
		];

		$this->assertTrue( Settings::set_shared_sites( $sites ) );

		$stored_sites = get_option( Settings::OPTION_GOVERNING_SHARED_SITES, [] );
		$this->assertNotSame( 'brand-one-key', $stored_sites[0]['api_key'] );

		$this->assertSame(
			[
				'https://brand-one.example/' => [
					'api_key' => 'brand-one-key',
					'id'      => 'brand-1',
					'logo'    => 'https://brand-one.example/logo.png',
					'logo_id' => 11,
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example/',
				],
			],
			Settings::get_shared_sites()
		);
	}

	/** Ensures get_shared_site_by_url returns the matching site. */
	public function test_get_shared_site_by_url(): void {
		Settings::set_shared_sites(
			[
				[
					'id'      => 'brand-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example',
					'logo'    => '',
					'logo_id' => 0,
					'api_key' => 'brand-one-key',
				],
			]
		);

		$this->assertSame( 'Brand One', Settings::get_shared_site_by_url( 'https://brand-one.example' )['name'] );
	}

	/** Ensures get_shared_site_by_url returns null for unknown URLs. */
	public function test_get_shared_site_by_url_returns_null_for_unknown(): void {
		$this->assertNull( Settings::get_shared_site_by_url( 'https://unknown.example' ) );
	}

	/** Removing a site returns its (decrypted) data and leaves the rest untouched. */
	public function test_remove_shared_site_removes_only_the_target(): void {
		Settings::set_shared_sites(
			[
				[
					'id'      => 'brand-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example',
					'api_key' => 'brand-one-key',
				],
				[
					'id'      => 'brand-2',
					'name'    => 'Brand Two',
					'url'     => 'https://brand-two.example',
					'api_key' => 'brand-two-key',
				],
			]
		);

		$removed = Settings::remove_shared_site( 'https://brand-one.example' );

		$this->assertSame(
			[
				'api_key' => 'brand-one-key',
				'id'      => 'brand-1',
				'logo'    => '',
				'logo_id' => 0,
				'name'    => 'Brand One',
				'url'     => 'https://brand-one.example/',
			],
			$removed
		);
		$this->assertSame( [ 'https://brand-two.example/' ], array_keys( Settings::get_shared_sites() ) );
	}

	/** Removing a site that isn't there is a no-op, reported as such rather than as an error. */
	public function test_remove_shared_site_returns_null_when_already_absent(): void {
		Settings::set_shared_sites(
			[
				[
					'id'      => 'brand-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example',
					'api_key' => 'brand-one-key',
				],
			]
		);

		$this->assertNull( Settings::remove_shared_site( 'https://unknown.example' ) );
	}

	/**
	 * A concurrent write between the read and the write must not resurrect a site removed
	 * by that other write, nor stop this removal from going through: it should retry
	 * against fresh data instead of blindly overwriting.
	 */
	public function test_remove_shared_site_retries_past_a_concurrent_write(): void {
		Settings::set_shared_sites(
			[
				[
					'id'      => 'brand-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example',
					'api_key' => 'brand-one-key',
				],
				[
					'id'      => 'brand-2',
					'name'    => 'Brand Two',
					'url'     => 'https://brand-two.example',
					'api_key' => 'brand-two-key',
				],
			]
		);

		$reads  = 0;
		$filter = static function ( $value ) use ( &$reads ) {
			++$reads;

			// Only the first read is stale, simulating another request's write landing in between.
			if ( 1 === $reads && is_array( $value ) ) {
				foreach ( $value as &$site ) {
					if ( ( $site['url'] ?? '' ) === 'https://brand-one.example/' ) {
						$site['name'] = 'Renamed-By-Concurrent-Write';
					}
				}
				unset( $site );
			}

			return $value;
		};
		add_filter( 'option_' . Settings::OPTION_GOVERNING_SHARED_SITES, $filter );

		$removed = Settings::remove_shared_site( 'https://brand-two.example' );

		remove_filter( 'option_' . Settings::OPTION_GOVERNING_SHARED_SITES, $filter );

		$this->assertIsArray( $removed );
		$this->assertSame( 'https://brand-two.example/', $removed['url'] );
		$this->assertGreaterThan( 1, $reads, 'Expected a retry after the simulated concurrent write.' );
		$this->assertSame( [ 'https://brand-one.example/' ], array_keys( Settings::get_shared_sites() ) );
	}

	/** Ensures parent site URL can be stored and retrieved. */
	public function test_set_parent_site_url_and_get(): void {
		$this->assertTrue( Settings::set_parent_site_url( 'https://governing.example/' ) );
		$this->assertSame( 'https://governing.example', Settings::get_parent_site_url() );
	}

	/** Ensures get_api_key generates a key if none is set. */
	public function test_get_api_key_generates_if_not_set(): void {
		$api_key = Settings::get_api_key();

		$this->assertNotSame( '', $api_key );
		$this->assertNotSame( $api_key, get_option( Settings::OPTION_CONSUMER_API_KEY, '' ) );
		$this->assertSame( $api_key, Settings::get_api_key() );
	}

	/** Ensures regenerate_api_key returns a new key. */
	public function test_regenerate_api_key_returns_new_key(): void {
		$initial_api_key = Settings::get_api_key();
		$new_api_key     = Settings::regenerate_api_key();

		$this->assertNotSame( '', $new_api_key );
		$this->assertNotSame( $initial_api_key, $new_api_key );
		$this->assertSame( $new_api_key, Settings::get_api_key() );
	}

	/** Ensures on_site_type_change generates an API key for consumer sites. */
	public function test_on_site_type_change_generates_api_key_for_consumer(): void {
		$this->settings->on_site_type_change( '', Settings::SITE_TYPE_CONSUMER );

		$stored_api_key = get_option( Settings::OPTION_CONSUMER_API_KEY, '' );

		$this->assertIsString( $stored_api_key );
		$this->assertNotSame( '', $stored_api_key );
		$this->assertSame( Settings::get_api_key(), Encryptor::decrypt( $stored_api_key ) );
	}

	/**
	 * Ensures a setting is registered.
	 *
	 * @param string $setting_name Setting name.
	 */
	private function assertSettingRegistered( string $setting_name ): void {
		$registered_settings = get_registered_settings();

		$this->assertArrayHasKey( $setting_name, $registered_settings );

		global $new_allowed_options;

		$this->assertContains( $setting_name, $new_allowed_options[ Settings::SETTING_GROUP ] ?? [] );
	}

	/**
	 * A brand site removed from the governing site is told to drop its own pairing.
	 */
	public function test_notify_removed_brand_sites_notifies_dropped_sites(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$requests = [];
		$filter   = static function ( $preempt, $args, $url ) use ( &$requests ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$requests[ $url ] = $args;
			return new \WP_Error( 'blocked', 'Intercepted' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$this->settings->notify_removed_brand_sites(
			[
				[
					'url'     => 'https://removed.example.com',
					'api_key' => Encryptor::encrypt( 'removed-key' ),
				],
				[
					'url'     => 'https://kept.example.com',
					'api_key' => Encryptor::encrypt( 'kept-key' ),
				],
			],
			[
				[
					'url'     => 'https://kept.example.com',
					'api_key' => Encryptor::encrypt( 'kept-key' ),
				],
			]
		);

		remove_filter( 'pre_http_request', $filter );

		$this->assertSame( [ 'https://removed.example.com/wp-json/onesearch/v1/site-connection/governing/remove' ], array_keys( $requests ) );
		$this->assertSame( 'removed-key', $requests['https://removed.example.com/wp-json/onesearch/v1/site-connection/governing/remove']['headers']['X-OneSearch-Token'] );
	}

	/**
	 * Nothing is sent when the update did not drop any site.
	 */
	public function test_notify_removed_brand_sites_ignores_unrelated_updates(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$requested_urls[] = $url;
			return new \WP_Error( 'blocked', 'Intercepted' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		// Renaming a site keeps it connected.
		$this->settings->notify_removed_brand_sites(
			[
				[
					'name'    => 'Old Name',
					'url'     => 'https://kept.example.com',
					'api_key' => Encryptor::encrypt( 'kept-key' ),
				],
			],
			[
				[
					'name'    => 'New Name',
					'url'     => 'https://kept.example.com/',
					'api_key' => Encryptor::encrypt( 'kept-key' ),
				],
			]
		);

		// There was nothing connected to begin with.
		$this->settings->notify_removed_brand_sites( [], [] );

		remove_filter( 'pre_http_request', $filter );

		$this->assertEmpty( $requested_urls );
	}

	/**
	 * Deleting the last brand site still notifies it.
	 */
	public function test_notify_removed_brand_sites_handles_emptied_list(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$requested_urls[] = $url;
			return new \WP_Error( 'blocked', 'Intercepted' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$this->settings->notify_removed_brand_sites(
			[
				[
					'url'     => 'https://removed.example.com',
					'api_key' => Encryptor::encrypt( 'removed-key' ),
				],
			],
			[]
		);

		remove_filter( 'pre_http_request', $filter );

		$this->assertSame( [ 'https://removed.example.com/wp-json/onesearch/v1/site-connection/governing/remove' ], $requested_urls );
	}

	/**
	 * Removing a brand site through the option notifies it, end to end.
	 */
	public function test_removing_a_shared_site_notifies_it(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->settings->register_hooks();

		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => 'https://brand.example.com',
					'api_key' => 'brand-key',
				],
			]
		);

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$requested_urls[] = $url;
			return new \WP_Error( 'blocked', 'Intercepted' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		Settings::set_shared_sites( [] );

		remove_filter( 'pre_http_request', $filter );
		remove_action( 'update_option_' . Settings::OPTION_GOVERNING_SHARED_SITES, [ $this->settings, 'notify_removed_brand_sites' ], 10 );

		$this->assertContains( 'https://brand.example.com/wp-json/onesearch/v1/site-connection/governing/remove', $requested_urls );
	}
}
