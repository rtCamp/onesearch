<?php
/**
 * Tests for Remote_Client.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for brand config fetching, caching, and cross-site post type retrieval.
 */
#[CoversClass( Remote_Client::class )]
class Remote_ClientTest extends TestCase {
	/**
	 * Returns error when site is not a consumer site.
	 */
	public function test_get_brand_config_returns_error_when_not_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$result = Remote_Client::get_brand_config();

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
		set_transient( Remote_Client::TRANSIENT_KEY, $cached, 3600 );

		$result = Remote_Client::get_brand_config();

		$this->assertIsArray( $result );
		$this->assertSame( 'CACHED', $result['algolia_credentials']['app_id'] );
	}

	/**
	 * Returns error when no parent site is configured.
	 */
	public function test_get_brand_config_returns_error_when_no_parent_configured(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Remote_Client::TRANSIENT_KEY );

		$result = Remote_Client::get_brand_config();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_no_parent', $result->get_error_code() );
	}

	/**
	 * Returns error when no API key is configured.
	 */
	public function test_get_brand_config_returns_error_when_no_api_key(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_transient( Remote_Client::TRANSIENT_KEY );

		// Store a base64 value that decodes to 32 bytes (valid IV length, no warning),
		// but whose content will never match the encryption salt, so Encryptor::decrypt
		// returns false and get_api_key() returns an empty string.
		update_option( Settings::OPTION_CONSUMER_API_KEY, base64_encode( str_repeat( 'x', 32 ) ) );

		$result = Remote_Client::get_brand_config();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_no_key', $result->get_error_code() );
	}

	/**
	 * Returns error when site is not a governing site.
	 */
	public function test_get_all_brand_post_types_returns_error_when_not_governing(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$result = Remote_Client::get_all_brand_post_types();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_unauthorized_site', $result->get_error_code() );
	}

	/**
	 * Returns empty sites and no errors when no shared sites exist.
	 */
	public function test_get_all_brand_post_types_returns_empty_when_no_shared_sites(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		$result = Remote_Client::get_all_brand_post_types();

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

		$result = Remote_Client::get_all_brand_post_types();

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

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, 'test.example.com' ) ) {
				return $preempt;
			}
			return new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::get_all_brand_post_types();

		remove_filter( 'pre_http_request', $filter );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Invalid response received', $result['errors'][0]['message'] );
	}

	/**
	 * Disconnecting a brand site sends it an authenticated DELETE, and reports whether it confirmed.
	 */
	public function test_disconnect_brand_site_sends_authenticated_delete(): void {
		$sent   = [];
		$filter = static function ( $preempt, array $args, string $url ) use ( &$sent ): array { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$sent[] = [
				'url'  => $url,
				'args' => $args,
			];

			return [
				'response' => [
					// Brand B no longer knows this governing site.
					'code'    => str_contains( $url, 'b.example.com' ) ? 404 : 200,
					'message' => '',
				],
				'body'     => '',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$confirmed = Remote_Client::disconnect_brand_site(
			[
				'url'     => 'https://a.example.com/',
				'api_key' => 'key-a',
			]
		);
		$refused   = Remote_Client::disconnect_brand_site(
			[
				'url'     => 'https://b.example.com/',
				'api_key' => 'key-b',
			]
		);

		remove_filter( 'pre_http_request', $filter );

		$this->assertTrue( $confirmed );
		$this->assertFalse( $refused );
		$this->assertSame( 'https://a.example.com/wp-json/onesearch/v1/governing-site', $sent[0]['url'] );
		$this->assertSame( 'DELETE', $sent[0]['args']['method'] );
		$this->assertSame( 'key-a', $sent[0]['args']['headers']['X-OneSearch-Token'] );
		$this->assertSame( Utils::get_current_site_url(), $sent[0]['args']['headers']['X-OneSearch-Site-URL'] );
	}

	/**
	 * Deletes the brand site's cached config transient.
	 */
	public function test_clear_brand_config_cache_deletes_the_transient(): void {
		set_transient( Remote_Client::TRANSIENT_KEY, [ 'data' ], 3600 );

		Remote_Client::clear_brand_config_cache();

		$this->assertFalse( get_transient( Remote_Client::TRANSIENT_KEY ) );
	}

	/**
	 * Clearing the cache succeeds when the transient was already absent.
	 */
	public function test_clear_brand_config_cache_noop_when_no_transient(): void {
		delete_transient( Remote_Client::TRANSIENT_KEY );

		// Should not throw.
		Remote_Client::clear_brand_config_cache();

		$this->assertFalse( get_transient( Remote_Client::TRANSIENT_KEY ) );
	}

	/**
	 * Governing site clear skips sites with missing url or api_key.
	 */
	public function test_clear_brand_sites_config_cache_skips_incomplete_sites(): void {
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

		Remote_Client::clear_brand_sites_config_cache();

		remove_filter( 'pre_http_request', $filter );

		$this->assertEmpty( $requested_urls, 'No HTTP requests should be made for sites missing api_key.' );
	}

	/**
	 * Governing site targets specific site when site_url provided.
	 */
	public function test_clear_brand_sites_config_cache_targets_specific_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
				[
					'name'    => 'Site B',
					'url'     => 'https://site-b.example.com/',
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

		Remote_Client::clear_brand_sites_config_cache( 'https://site-a.example.com/' );

		remove_filter( 'pre_http_request', $filter );

		$this->assertCount( 1, $requested_urls, 'Only one HTTP request should be made.' );
		$this->assertStringContainsString( 'site-a.example.com', $requested_urls[0], 'Request should target Site A.' );
	}

	/**
	 * A brand site without a stored key isn't sent anything.
	 */
	public function test_brand_site_requests_need_the_url_and_key(): void {
		$requested_urls = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$requested_urls[] = $url;
				return new \WP_Error( 'blocked', 'Intercepted' );
			},
			10,
			3
		);

		$site = [
			'url'     => 'https://no-key.example.com/',
			'api_key' => '',
		];

		$this->assertSame( 'onesearch_missing_site_data', Remote_Client::reindex_brand_site( $site )->get_error_code() );
		$this->assertSame( 'onesearch_missing_site_data', Remote_Client::get_brand_status( $site )->get_error_code() );
		$this->assertSame( [], $requested_urls );
	}

	/**
	 * A brand site is told to re-index with its key, and fails with the brand site's own error.
	 */
	public function test_brand_site_requests_return_the_brand_sites_answer(): void {
		$site = [
			'url'     => 'https://brand.example.com/',
			'api_key' => 'brand-key',
		];

		$answer = (object) [
			'code' => 200,
			'body' => '{}',
		];
		$sent   = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $answer, &$sent ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$sent[] = [ $args['method'] ?? 'GET', $url, $args['headers']['X-OneSearch-Token'] ];

				return [
					'response' => [
						'code'    => $answer->code,
						'message' => '',
					],
					'body'     => $answer->body,
				];
			},
			10,
			3
		);

		$this->assertTrue( Remote_Client::reindex_brand_site( $site ) );
		$this->assertSame(
			[
				[ 'POST', 'https://brand.example.com/wp-json/onesearch/v1/indexer', 'brand-key' ],
			],
			$sent
		);

		$answer->code = 403;
		$answer->body = '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that."}';
		$this->assertSame( 'Sorry, you are not allowed to do that.', Remote_Client::reindex_brand_site( $site )->get_error_message() );
	}

	/**
	 * A brand site's report comes back as it sent it, unless it isn't JSON.
	 */
	public function test_get_brand_status(): void {
		$site = [
			'url'     => 'https://brand.example.com/',
			'api_key' => 'brand-key',
		];

		$body = (object) [ 'json' => '{"sites":{},"jobs":[]}' ];
		add_filter(
			'pre_http_request',
			static fn () => [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => $body->json,
			]
		);

		$this->assertSame(
			[
				'sites' => [],
				'jobs'  => [],
			],
			Remote_Client::get_brand_status( $site )
		);

		$body->json = 'not json';
		$this->assertSame( 'onesearch_rest_invalid_response', Remote_Client::get_brand_status( $site )->get_error_code() );
	}

	/**
	 * Successful brand-site/config fetch returns sanitized payload and caches it.
	 */
	public function test_get_brand_config_returns_payload_on_success(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_transient( Remote_Client::TRANSIENT_KEY );
		Settings::regenerate_api_key();

		$payload = [
			'algolia_credentials' => [
				'app_id'    => 'REMOTE_APP',
				'write_key' => 'REMOTE_KEY',
			],
			'search_settings'     => [
				'algolia_enabled'  => true,
				'searchable_sites' => [ 'https://a.example.com/' ],
			],
			'indexable_entities'  => [ 'post', 'page' ],
			'available_sites'     => [ 'https://a.example.com/' ],
		];

		$sent_site_url = null;
		$filter        = static function ( $preempt, $args, $url ) use ( $payload, &$sent_site_url ) {
			if ( false === strpos( $url, '/brand-site/config' ) ) {
				return $preempt;
			}
			$sent_site_url = $args['headers']['X-OneSearch-Site-URL'] ?? null;
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode( $payload ),
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::get_brand_config();

		remove_filter( 'pre_http_request', $filter );

		$this->assertSame( Utils::get_current_site_url(), $sent_site_url );
		$this->assertIsArray( $result );
		$this->assertSame( 'REMOTE_APP', $result['algolia_credentials']['app_id'] );
		$this->assertSame( 'REMOTE_KEY', $result['algolia_credentials']['write_key'] );
		$this->assertTrue( $result['search_settings']['algolia_enabled'] );
		$this->assertSame( [ 'post', 'page' ], $result['indexable_entities'] );
		$this->assertSame( [ 'https://a.example.com/' ], $result['available_sites'] );
		$this->assertNotFalse( get_transient( Remote_Client::TRANSIENT_KEY ), 'Successful fetch should populate the cache.' );
	}

	/**
	 * Non-200 response from the governing site returns the failed-to-connect error.
	 */
	public function test_get_brand_config_returns_error_on_non_200(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_transient( Remote_Client::TRANSIENT_KEY );
		Settings::regenerate_api_key();

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/brand-site/config' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 502,
					'message' => 'Bad Gateway',
				],
				'body'     => '',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::get_brand_config();

		remove_filter( 'pre_http_request', $filter );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_rest_failed_to_connect', $result->get_error_code() );
	}

	/**
	 * A 200 response with a non-JSON body returns the invalid-response error.
	 */
	public function test_get_brand_config_returns_error_on_invalid_json(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_transient( Remote_Client::TRANSIENT_KEY );
		Settings::regenerate_api_key();

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/brand-site/config' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => 'not-json',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::get_brand_config();

		remove_filter( 'pre_http_request', $filter );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_rest_invalid_response', $result->get_error_code() );
	}

	/**
	 * A WP_Error from the remote layer is propagated unchanged.
	 */
	public function test_get_brand_config_propagates_wp_error(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		delete_transient( Remote_Client::TRANSIENT_KEY );
		Settings::regenerate_api_key();

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/brand-site/config' ) ) {
				return $preempt;
			}
			return new \WP_Error( 'http_request_failed', 'cURL error 7' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::get_brand_config();

		remove_filter( 'pre_http_request', $filter );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	/**
	 * Deregistering is only possible from a brand site.
	 */
	public function test_deregister_from_governing_site_returns_error_when_not_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$result = Remote_Client::deregister_from_governing_site();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_unauthorized_site', $result->get_error_code() );
	}

	/**
	 * Sends a DELETE to the governing site's brand-site route, authenticated as this brand site.
	 */
	public function test_deregister_from_governing_site_sends_authenticated_delete(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		$api_key = Settings::regenerate_api_key();

		$captured = [];
		$filter   = static function ( $preempt, $args, $url ) use ( &$captured ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$captured = [
				'url'  => $url,
				'args' => $args,
			];
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

		$result = Remote_Client::deregister_from_governing_site();

		remove_filter( 'pre_http_request', $filter );

		$this->assertTrue( $result );
		$this->assertSame( 'https://governing.example.com/wp-json/onesearch/v1/brand-site', $captured['url'] );
		$this->assertSame( 'DELETE', $captured['args']['method'] );
		$this->assertSame( $api_key, $captured['args']['headers']['X-OneSearch-Token'] );
		$this->assertSame( Utils::get_current_site_url(), $captured['args']['headers']['X-OneSearch-Site-URL'] );
		$this->assertArrayNotHasKey( 'Origin', $captured['args']['headers'] );
		$this->assertSame( 15, $captured['args']['timeout'] );
	}

	/**
	 * A rejected token means the governing site no longer lists this site with its current key.
	 */
	public function test_deregister_from_governing_site_reports_unrecognized_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		Settings::regenerate_api_key();

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/brand-site' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 401,
					'message' => 'Unauthorized',
				],
				'body'     => '',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::deregister_from_governing_site();

		remove_filter( 'pre_http_request', $filter );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_unrecognized_site', $result->get_error_code() );
	}

	/**
	 * Any other non-200 response from the governing site returns the failed-to-connect error.
	 */
	public function test_deregister_from_governing_site_returns_error_on_non_200(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		Settings::regenerate_api_key();

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/brand-site' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 500,
					'message' => 'Internal Server Error',
				],
				'body'     => '',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::deregister_from_governing_site();

		remove_filter( 'pre_http_request', $filter );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'onesearch_rest_failed_to_connect', $result->get_error_code() );
	}

	/**
	 * A WP_Error from the remote layer is propagated unchanged.
	 */
	public function test_deregister_from_governing_site_propagates_wp_error(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		Settings::regenerate_api_key();

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/brand-site' ) ) {
				return $preempt;
			}
			return new \WP_Error( 'http_request_failed', 'cURL error 7' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::deregister_from_governing_site();

		remove_filter( 'pre_http_request', $filter );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	/**
	 * Successful per-site responses are merged into the `sites` map.
	 */
	public function test_get_all_brand_post_types_aggregates_remote_responses(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
			]
		);

		$remote_payload = [
			'sites'  => [
				'https://site-a.example.com/' => [
					'site_name'  => 'Site A',
					'site_url'   => 'https://site-a.example.com/',
					'post_types' => [
						[
							'slug'     => 'post',
							'label'    => 'Posts',
							'restBase' => 'posts',
						],
					],
				],
			],
			'errors' => [],
		];

		$filter = static function ( $preempt, $args, $url ) use ( $remote_payload ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, 'site-a.example.com' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode( $remote_payload ),
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::get_all_brand_post_types();

		remove_filter( 'pre_http_request', $filter );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'https://site-a.example.com/', $result['sites'] );
		$this->assertSame( 'Site A', $result['sites']['https://site-a.example.com/']['site_name'] );
		$this->assertSame( [], $result['errors'] );
	}

	/**
	 * Non-200 responses from shared sites are recorded under `errors`.
	 */
	public function test_get_all_brand_post_types_reports_non_200_responses(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
			]
		);

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, 'site-a.example.com' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 500,
					'message' => 'Internal Server Error',
				],
				'body'     => '',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::get_all_brand_post_types();

		remove_filter( 'pre_http_request', $filter );

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['sites'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( '500', $result['errors'][0]['message'] );
	}

	/**
	 * Malformed JSON from shared sites is recorded under `errors`.
	 */
	public function test_get_all_brand_post_types_reports_invalid_json(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
			]
		);

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, 'site-a.example.com' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => 'not-json',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = Remote_Client::get_all_brand_post_types();

		remove_filter( 'pre_http_request', $filter );

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['sites'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'invalid response', $result['errors'][0]['message'] );
	}
}
