<?php
/**
 * Tests for Governing_Data_Handler.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for brand config fetching, caching, and cross-site post type retrieval.
 */
#[CoversClass( Governing_Data_Handler::class )]
class Governing_Data_HandlerTest extends TestCase {
	// ── get_brand_config ────────────────────────────────────────────────

	/**
	 * Returns error when site is not a consumer site.
	 */
	public function test_get_brand_config_returns_error_when_not_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$result = Governing_Data_Handler::get_brand_config();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_unauthorized_site', $result->get_error_code() );
	}

	/**
	 * Returns cached value when transient is available.
	 */
	public function test_get_brand_config_returns_cached_value_when_available(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$cached = [
			'algolia_credentials' => [
				'app_id'    => 'CACHED',
				'write_key' => 'CACHED_KEY',
			],
			'search_settings'     => [
				'algolia_enabled'  => true,
				'searchable_sites' => [],
			],
			'indexable_entities'  => [ 'post' ],
			'available_sites'     => [ 'https://example.com/' ],
		];
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, $cached, 3600 );

		$result = Governing_Data_Handler::get_brand_config();

		$this->assertIsArray( $result );
		$this->assertSame( 'CACHED', $result['algolia_credentials']['app_id'] );
	}

	/**
	 * Returns error when no parent site is configured.
	 */
	public function test_get_brand_config_returns_error_when_no_parent_configured(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		$result = Governing_Data_Handler::get_brand_config();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_no_parent', $result->get_error_code() );
	}

	/**
	 * Returns error when no API key is configured.
	 */
	public function test_get_brand_config_returns_error_when_no_api_key(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		// Force get_api_key() to return an empty string by storing an undecryptable value.
		update_option( Settings::OPTION_CONSUMER_API_KEY, 'not-valid-encrypted-value' );

		$result = Governing_Data_Handler::get_brand_config();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_no_key', $result->get_error_code() );
	}

	// ── get_all_brand_post_types ────────────────────────────────────────

	/**
	 * Returns error when site is not a governing site.
	 */
	public function test_get_all_brand_post_types_returns_error_when_not_governing(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$result = Governing_Data_Handler::get_all_brand_post_types();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_unauthorized_site', $result->get_error_code() );
	}

	/**
	 * Returns empty sites and no errors when no shared sites exist.
	 */
	public function test_get_all_brand_post_types_returns_empty_when_no_shared_sites(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		$result = Governing_Data_Handler::get_all_brand_post_types();

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['sites'] );
		$this->assertSame( [], $result['errors'] );
	}

	/**
	 * Reports errors for shared sites missing url or api_key.
	 */
	public function test_get_all_brand_post_types_reports_errors_for_sites_missing_url_or_key(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'No Key',
					'url'     => 'https://no-key.example.com',
					'api_key' => '',
				],
			]
		);

		$result = Governing_Data_Handler::get_all_brand_post_types();

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Reports connection errors for unreachable shared sites.
	 */
	public function test_get_all_brand_post_types_reports_errors_for_unreachable_sites(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Test',
					'url'     => 'https://test.example.com',
					'api_key' => 'some-key',
				],
			]
		);

		$result = Governing_Data_Handler::get_all_brand_post_types();
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Invalid response received', $result['errors'][0]['message'] );
	}

	// ── clear_brand_config_cache ────────────────────────────────────────

	/**
	 * Deletes transient for non-governing site.
	 */
	public function test_clear_brand_config_cache_deletes_transient_for_non_governing(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		set_transient( Governing_Data_Handler::TRANSIENT_KEY, [ 'data' ], 3600 );

		Governing_Data_Handler::clear_brand_config_cache();

		$this->assertFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * Non-governing site cache clear succeeds when transient was already absent.
	 */
	public function test_clear_brand_config_cache_noop_when_no_transient(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		// Should not throw.
		Governing_Data_Handler::clear_brand_config_cache();

		$this->assertFalse( get_transient( Governing_Data_Handler::TRANSIENT_KEY ) );
	}

	/**
	 * Governing site clear skips sites with missing url or api_key.
	 */
	public function test_clear_brand_config_cache_governing_skips_incomplete_sites(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'No Key',
					'url'     => 'https://no-key.example.com',
					'api_key' => '',
				],
			]
		);

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$requested_urls[] = $url;
			return new \WP_Error( 'blocked', 'Intercepted' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		Governing_Data_Handler::clear_brand_config_cache();

		remove_filter( 'pre_http_request', $filter );

		$this->assertEmpty( $requested_urls, 'No HTTP requests should be made for sites missing api_key.' );
	}

	/**
	 * Governing site targets specific site when site_url provided.
	 */
	public function test_clear_brand_config_cache_governing_targets_specific_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com',
					'api_key' => 'key-a',
				],
				[
					'name'    => 'Site B',
					'url'     => 'https://site-b.example.com',
					'api_key' => 'key-b',
				],
			]
		);

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$requested_urls[] = $url;
			return new \WP_Error( 'blocked', 'Intercepted' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		Governing_Data_Handler::clear_brand_config_cache( 'https://site-a.example.com/' );

		remove_filter( 'pre_http_request', $filter );

		$this->assertCount( 1, $requested_urls, 'Only one HTTP request should be made.' );
		$this->assertStringContainsString( 'site-a.example.com', $requested_urls[0], 'Request should target Site A.' );
	}
}
