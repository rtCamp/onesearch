<?php
/**
 * Search settings unit tests.
 *
 * @package OneSearch\Tests\Unit\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Search;

use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the search Settings class.
 */
#[CoversClass( \OneSearch\Modules\Search\Settings::class )]
final class SettingsTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		parent::tearDown();
	}

	/**
	 * Ensures register_hooks adds expected actions.
	 */
	public function test_register_hooks_adds_expected_actions(): void {
		$settings = new Search_Settings();
		$settings->register_hooks();

		$this->assertNotFalse( has_action( 'admin_init', [ $settings, 'register_settings' ] ) );
		$this->assertNotFalse( has_action( 'rest_api_init', [ $settings, 'register_settings' ] ) );
	}

	/**
	 * Ensures register_hooks listens to site_type, shared_sites option updates.
	 */
	public function test_register_hooks_listens_to_site_type_changes(): void {
		$settings = new Search_Settings();
		$settings->register_hooks();

		$this->assertNotFalse(
			has_action( 'update_option_' . Settings::OPTION_SITE_TYPE, [ $settings, 'on_site_type_change' ] )
		);
		$this->assertNotFalse(
			has_action( 'update_option_' . Settings::OPTION_GOVERNING_SHARED_SITES, [ $settings, 'on_shared_sites_change' ] )
		);
	}

	/**
	 * Ensures register_hooks sets up cache purge hooks.
	 */
	public function test_register_hooks_sets_up_cache_purge_actions(): void {
		$settings = new Search_Settings();
		$settings->register_hooks();

		$this->assertNotFalse(
			has_action( 'update_option_' . Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS, [ $settings, 'purge_cache_on_update' ] )
		);
		$this->assertNotFalse(
			has_action( 'update_option_' . Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, [ $settings, 'purge_cache_on_update' ] )
		);
		$this->assertNotFalse(
			has_action( 'update_option_' . Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS, [ $settings, 'purge_cache_on_update' ] )
		);
	}

	/**
	 * Ensures register_settings registers governing settings when governing.
	 */
	public function test_register_settings_registers_governing_options(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$settings = new Search_Settings();
		$settings->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS, $registered );
		$this->assertArrayHasKey( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, $registered );
		$this->assertArrayHasKey( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS, $registered );
	}

	/**
	 * Ensures register_settings sanitizes algolia credentials payload.
	 */
	public function test_register_settings_sanitizes_algolia_credentials(): void {
		$settings = new Search_Settings();
		$settings->register_settings();

		$registered = get_registered_settings();
		$sanitize   = $registered[ Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS ]['sanitize_callback'] ?? null;

		$this->assertIsCallable( $sanitize );

		$sanitized = $sanitize(
			[
				'app_id'    => ' <b>app-id</b> ',
				'write_key' => "\n<script>alert(1)</script>write-key\t",
			]
		);

		$this->assertSame( 'app-id', $sanitized['app_id'] );
		$this->assertSame( 'write-key', $sanitized['write_key'] );
	}

	/**
	 * Ensures register_settings sanitizes search settings payload and normalizes keys.
	 */
	public function test_register_settings_sanitizes_search_settings_payload(): void {
		$settings = new Search_Settings();
		$settings->register_settings();

		$registered = get_registered_settings();
		$sanitize   = $registered[ Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS ]['sanitize_callback'] ?? null;

		$this->assertIsCallable( $sanitize );

		$sanitized = $sanitize(
			[
				' https://example.com/site '  => [
					'algolia_enabled'  => 1,
					'searchable_sites' => [ ' https://child.example.com/<b>x</b> ' ],
				],
				'https://example.com/invalid' => 'not-array',
			]
		);

		$this->assertArrayHasKey( 'https://example.com/site/', $sanitized );
		$this->assertTrue( $sanitized['https://example.com/site/']['algolia_enabled'] );
		$this->assertSame( [ 'https://child.example.com/x' ], $sanitized['https://example.com/site/']['searchable_sites'] );

		$this->assertArrayHasKey( 'https://example.com/invalid/', $sanitized );
		$this->assertFalse( $sanitized['https://example.com/invalid/']['algolia_enabled'] );
		$this->assertSame( [], $sanitized['https://example.com/invalid/']['searchable_sites'] );
	}

	/**
	 * Returns null values when no credentials are stored.
	 */
	public function test_get_algolia_credentials_returns_nulls_when_empty(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$creds = Search_Settings::get_algolia_credentials();

		$this->assertNull( $creds['app_id'] );
		$this->assertNull( $creds['write_key'] );
	}

	/**
	 * Returns stored credentials after setting them.
	 */
	public function test_get_algolia_credentials_returns_stored_values(): void {
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'my-app',
				'write_key' => 'my-key',
			]
		);

		$creds = Search_Settings::get_algolia_credentials();

		$this->assertSame( 'my-app', $creds['app_id'] );
		$this->assertSame( 'my-key', $creds['write_key'] );
	}

	/**
	 * Returns true on successful save.
	 */
	public function test_set_algolia_credentials_returns_true_on_success(): void {
		$result = Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'app',
				'write_key' => 'key',
			]
		);

		$this->assertTrue( $result );
	}

	/**
	 * Returns false when value is not an array.
	 */
	public function test_set_algolia_credentials_returns_false_for_non_array(): void {
		// @phpstan-ignore argument.type
		$result = Search_Settings::set_algolia_credentials( 'invalid' );

		$this->assertFalse( $result );
	}

	/**
	 * Stores null when app_id is missing.
	 */
	public function test_set_algolia_credentials_stores_null_for_missing_app_id(): void {
		Search_Settings::set_algolia_credentials( [ 'write_key' => 'a-key' ] );

		$creds = Search_Settings::get_algolia_credentials();

		$this->assertNull( $creds['app_id'] );
	}

	/**
	 * Returns empty array when nothing is stored.
	 */
	public function test_get_indexable_entities_returns_empty_when_unset(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );

		$this->assertSame( [], Search_Settings::get_indexable_entities() );
	}

	/**
	 * Returns stored value.
	 */
	public function test_get_indexable_entities_returns_stored_value(): void {
		$entities = [
			'entities' => [
				'https://example.com/' => [ 'post', 'page' ],
			],
		];
		update_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, $entities );

		$this->assertSame( $entities, Search_Settings::get_indexable_entities() );
	}

	/**
	 * Returns empty array when nothing is stored.
	 */
	public function test_get_search_settings_returns_empty_when_unset(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$this->assertSame( [], Search_Settings::get_search_settings() );
	}

	/**
	 * Returns stored search settings.
	 */
	public function test_get_search_settings_returns_stored_value(): void {
		$value = [
			'https://example.com/' => [
				'algolia_enabled'  => true,
				'searchable_sites' => [ 'https://child.example.com/' ],
			],
		];
		update_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS, $value );

		$this->assertSame( $value, Search_Settings::get_search_settings() );
	}

	/**
	 * Does nothing when new value is not consumer.
	 */
	public function test_on_site_type_change_skips_non_consumer(): void {
		$settings = new Search_Settings();

		// Should not throw or error out.
		$settings->on_site_type_change( '', Settings::SITE_TYPE_GOVERNING );

		$this->assertTrue( true );
	}

	/**
	 * Does nothing when old value is empty.
	 */
	public function test_on_shared_sites_change_skips_empty_old_value(): void {
		$settings = new Search_Settings();

		$settings->on_shared_sites_change( [], [ [ 'url' => 'https://new.example.com' ] ] );

		$this->assertTrue( true );
	}

	/**
	 * Does nothing when old value is not an array.
	 */
	public function test_on_shared_sites_change_skips_non_array_old_value(): void {
		$settings = new Search_Settings();

		$settings->on_shared_sites_change( 'not-array', [ [ 'url' => 'https://new.example.com' ] ] );

		$this->assertTrue( true );
	}

	/**
	 * Does nothing when no sites have been removed.
	 */
	public function test_on_shared_sites_change_skips_when_no_sites_removed(): void {
		$sites    = [ [ 'url' => 'https://child.example.com' ] ];
		$settings = new Search_Settings();

		$settings->on_shared_sites_change( $sites, $sites );

		$this->assertTrue( true );
	}

	/**
	 * Keeps indexable entities unchanged when site removal delete fails.
	 */
	public function test_on_shared_sites_change_keeps_entities_when_delete_fails(): void {
		$settings = new Search_Settings();

		$initial_entities = [
			'entities' => [
				'post' => [
					'https://child-a.example.com/',
					'https://child-b.example.com/',
				],
			],
		];

		update_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, $initial_entities );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$old_sites = [
			[ 'url' => 'https://child-a.example.com/' ],
			[ 'url' => 'https://child-b.example.com/' ],
		];
		$new_sites = [
			[ 'url' => 'https://child-b.example.com/' ],
		];

		$settings->on_shared_sites_change( $old_sites, $new_sites );

		$this->assertSame( $initial_entities, get_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES ) );
	}

	/**
	 * Purges cache when algolia credentials option updates.
	 */
	public function test_purge_cache_on_update_clears_cache_for_credentials(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'test' => true ], 3600 );

		$settings = new Search_Settings();
		$settings->purge_cache_on_update( [], [], Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$this->assertFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * Purges cache when indexable sites option updates.
	 */
	public function test_purge_cache_on_update_clears_cache_for_indexable_sites(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'test' => true ], 3600 );

		$settings = new Search_Settings();
		$settings->purge_cache_on_update( [], [], Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );

		$this->assertFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * Purges cache when search settings option updates.
	 */
	public function test_purge_cache_on_update_clears_cache_for_search_settings(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'test' => true ], 3600 );

		$settings = new Search_Settings();
		$settings->purge_cache_on_update( [], [], Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$this->assertFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * Does not purge cache for unknown option.
	 */
	public function test_purge_cache_on_update_ignores_unknown_option(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'test' => true ], 3600 );

		$settings = new Search_Settings();
		$settings->purge_cache_on_update( [], [], 'some_unrelated_option' );

		$this->assertNotFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}
}
