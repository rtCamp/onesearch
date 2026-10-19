<?php
/**
 * Search settings unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

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
	 * Ensures register_hooks adds expected actions.
	 */
	public function test_class_instantiation(): void {
		$settings = new Search_Settings();
		$settings->register_hooks();
		// If we made it here without erroring, we're good.
		$this->assertTrue( true );
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

		$raw = [
			'app_id'    => ' <b>app-id</b> ',
			'write_key' => "\n<script>alert(1)</script>write-key\t",
		];

		update_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS, $raw );
		$sanitized = get_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$this->assertSame( 'app-id', $sanitized['app_id'] );
		$this->assertSame( 'write-key', $sanitized['write_key'] );
	}

	/**
	 * Ensures register_settings sanitizes search settings payload and normalizes keys.
	 */
	public function test_register_settings_sanitizes_search_settings_payload(): void {
		$settings = new Search_Settings();
		$settings->register_settings();

		$raw = [
			' https://example.com/site '  => [
				'algolia_enabled'  => 1,
				'searchable_sites' => [ ' https://child.example.com/<b>x</b> ' ],
			],
			'https://example.com/invalid' => 'not-array',
		];

		update_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS, $raw );
		$sanitized = get_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$this->assertArrayHasKey( 'https://example.com/site/', $sanitized );
		$this->assertTrue( $sanitized['https://example.com/site/']['algolia_enabled'] );
		$this->assertSame( [ 'https://child.example.com/x' ], $sanitized['https://example.com/site/']['searchable_sites'] );

		$this->assertArrayHasKey( 'https://example.com/invalid/', $sanitized );
		$this->assertFalse( $sanitized['https://example.com/invalid/']['algolia_enabled'] );
		$this->assertSame( [], $sanitized['https://example.com/invalid/']['searchable_sites'] );
	}

	/**
	 * Ensures register_settings sanitizes the indexable entities and normalizes their site URLs.
	 */
	public function test_register_settings_sanitizes_indexable_entities(): void {
		$settings = new Search_Settings();
		$settings->register_settings();

		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					'https://example.com'         => [ 'post', 'Page', 'post', 7 ],
					'https://invalid.example.com' => 'post',
				],
				'unknown'  => true,
			]
		);

		$this->assertSame(
			[
				'entities' => [
					'https://example.com/' => [ 'post', 'page' ],
				],
			],
			get_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES )
		);
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
	 * Only a governing site becoming a consumer deletes the index.
	 */
	public function test_on_site_type_change_skips_other_changes(): void {
		$this->set_up_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$settings = new Search_Settings();
		$settings->on_site_type_change( Settings::OPTION_SITE_TYPE, '', Settings::SITE_TYPE_GOVERNING );
		$settings->on_site_type_change( Settings::OPTION_SITE_TYPE, '', Settings::SITE_TYPE_CONSUMER );
		$settings->on_site_type_change( 'blogname', Settings::SITE_TYPE_GOVERNING, Settings::SITE_TYPE_CONSUMER );

		$this->assertSame( [], $recorded_paths );
	}

	/**
	 * Switching a governing site to a consumer deletes the governing site's index.
	 *
	 * The hook runs before the new site type is saved, so the index still resolves as the governing site's.
	 */
	public function test_switching_governing_site_to_consumer_deletes_its_index(): void {
		$this->set_up_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		( new Search_Settings() )->register_hooks();
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$this->assertContains( '/1/indexes/onesearch_governing_example_org_wp_posts', $recorded_paths );
		$this->assertEmpty(
			array_filter( $recorded_paths, static fn ( string $path ) => str_contains( $path, '/deleteByQuery' ) ),
			'The governing site should delete the whole index, not just its records.'
		);
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
				'https://child-a.example.com/' => [ 'post' ],
				'https://child-b.example.com/' => [ 'post' ],
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
	 * Removing shared sites stops them from being indexed.
	 */
	public function test_on_shared_sites_change_removes_removed_sites_entities(): void {
		$this->set_up_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					'https://child-a.example.com/' => [ 'post' ],
					'https://child-b.example.com/' => [ 'page' ],
				],
			]
		);

		( new Search_Settings() )->on_shared_sites_change(
			[
				[ 'url' => 'https://child-a.example.com' ],
				[ 'url' => 'https://child-b.example.com' ],
			],
			[
				[ 'url' => 'https://child-b.example.com' ],
			]
		);

		$this->assertSame(
			[
				'entities' => [
					'https://child-b.example.com/' => [ 'page' ],
				],
			],
			get_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES )
		);
	}

	/**
	 * Removing shared sites deletes their records with a valid filter.
	 */
	public function test_on_shared_sites_change_deletes_removed_sites_records(): void {
		$this->set_up_governing_credentials();

		$recorded_paths    = [];
		$recorded_requests = [];
		$this->mock_algolia_http_client( $recorded_paths, null, null, $recorded_requests );

		( new Search_Settings() )->on_shared_sites_change(
			[
				[ 'url' => 'https://child-a.example.com' ],
				[ 'url' => 'https://child-b.example.com/' ],
				[ 'url' => 'https://child-c.example.com/' ],
			],
			[
				[ 'url' => 'https://child-c.example.com/' ],
			]
		);

		$delete_requests = array_values( array_filter( $recorded_requests, static fn ( array $request ) => str_contains( $request['path'], '/deleteByQuery' ) ) );

		$this->assertCount( 1, $delete_requests );
		$this->assertSame(
			'site_url:"https://child-a.example.com/" OR site_url:"https://child-b.example.com/"',
			json_decode( $delete_requests[0]['body'], true )['filters']
		);
	}

	/**
	 * Adding or updating an unrelated option does not purge the brand config cache.
	 */
	public function test_unrelated_option_does_not_purge_cache(): void {
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'test' => true ], 3600 );
		( new Search_Settings() )->register_hooks();

		add_option( 'some_unrelated_option', 'first' );
		update_option( 'some_unrelated_option', 'second' );

		$this->assertNotFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * The first save of an option goes through add_option(), and still tells the brands to purge their config.
	 */
	public function test_first_save_of_indexable_entities_purges_brand_config(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'api_key' => 'brand-key',
					'url'     => 'https://brand.example.com',
				],
			]
		);
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		( new Search_Settings() )->register_hooks();

		$purges = [];
		$filter = static function ( $preempt, $args, $url ) use ( &$purges ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( str_contains( $url, '/brand-config' ) && 'DELETE' === $args['method'] ) {
				$purges[] = $url;
			}

			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => '',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		update_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES, [ 'entities' => [ 'https://brand.example.com/' => [ 'post' ] ] ] );

		remove_filter( 'pre_http_request', $filter );

		$this->assertSame( [ 'https://brand.example.com/wp-json/onesearch/v1/brand-config' ], $purges );
	}

	/**
	 * Configures the current site as a governing site with Algolia credentials.
	 */
	private function set_up_governing_credentials(): void {
		// Name the index after a known host, since the test site's URL depends on the environment.
		add_filter( 'option_home', static fn (): string => 'https://governing.example.org', 11 );
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'test-app',
				'write_key' => 'test-key',
			]
		);
	}
}
