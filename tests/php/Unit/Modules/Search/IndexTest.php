<?php
/**
 * Index unit tests.
 *
 * @package OneSearch\Tests\Unit\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Search;

use OneSearch\Modules\Search\Index;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia as AlgoliaSDK;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Index class.
 */
#[CoversClass( \OneSearch\Modules\Search\Index::class )]
final class IndexTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		AlgoliaSDK::resetHttpClient();

		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		parent::tearDown();
	}

	// ── get_index ───────────────────────────────────────────────────────

	/**
	 * Returns WP_Error when Algolia credentials are missing.
	 */
	public function test_get_index_returns_error_without_credentials(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$index  = new Index();
		$result = $index->get_index();

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_credentials_missing', $result->get_error_code() );
		$this->assertSame( 'Algolia admin credentials missing.', $result->get_error_message() );
	}

	/**
	 * Returns SearchIndex when credentials are valid.
	 */
	public function test_get_index_returns_search_index_with_valid_credentials(): void {
		self::set_governing_credentials();

		$index  = new Index();
		$result = $index->get_index();

		$this->assertInstanceOf( \OneSearch\Vendor\Algolia\AlgoliaSearch\SearchIndex::class, $result );
	}

	/**
	 * Caches the index instance on subsequent calls.
	 */
	public function test_get_index_returns_same_instance_on_second_call(): void {
		self::set_governing_credentials();

		$index  = new Index();
		$first  = $index->get_index();
		$second = $index->get_index();

		$this->assertSame( $first, $second );
	}

	// ── delete_index ────────────────────────────────────────────────────

	/**
	 * Returns WP_Error when credentials are missing.
	 */
	public function test_delete_index_returns_error_without_credentials(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$result = ( new Index() )->delete_index();

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_credentials_missing', $result->get_error_code() );
	}

	/**
	 * Returns true for delete_index with valid credentials.
	 */
	public function test_delete_index_returns_true_with_valid_credentials(): void {
		$this->set_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$result = ( new Index() )->delete_index();

		$this->assertTrue( $result );
		$this->assertNotEmpty( $recorded_paths );
	}

	// ── delete_by ───────────────────────────────────────────────────────

	/**
	 * Returns WP_Error when credentials are missing.
	 */
	public function test_delete_by_returns_error_without_credentials(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$result = ( new Index() )->delete_by( [ 'filters' => 'site_url:"http://test.com"' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_credentials_missing', $result->get_error_code() );
	}

	/**
	 * Returns true for delete_by with valid credentials.
	 */
	public function test_delete_by_returns_true_with_valid_credentials(): void {
		$this->set_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$result = ( new Index() )->delete_by( [ 'filters' => 'site_url:"http://test.com"' ] );

		$this->assertTrue( $result );
		$this->assertNotEmpty( $recorded_paths );
	}

	/**
	 * Returns WP_Error when SDK throws during delete_by failure.
	 */
	public function test_delete_by_returns_error_when_sdk_throws(): void {
		$this->set_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths, null, '/deleteBy' );

		$result = ( new Index() )->delete_by( [ 'filters' => 'site_url:"http://test.com"' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'onesearch_algolia_delete_by_failed', $result->get_error_code() );
	}

	// ── save_records ────────────────────────────────────────────────────

	/**
	 * Returns WP_Error when credentials are missing.
	 */
	public function test_save_records_returns_error_without_credentials(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$result = ( new Index() )->save_records( [] );

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_credentials_missing', $result->get_error_code() );
	}

	/**
	 * Returns true for save_records with valid credentials.
	 */
	public function test_save_records_returns_true_with_valid_credentials(): void {
		$this->set_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$result = ( new Index() )->save_records(
			[
				[
					'objectID'   => '1',
					'post_title' => 'Test',
				],
			]
		);

		$this->assertTrue( $result );
		$this->assertNotEmpty( $recorded_paths );
	}

	/**
	 * Returns WP_Error when SDK throws during save_records.
	 */
	public function test_save_records_returns_error_when_sdk_throws(): void {
		$this->set_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths, null, '/batch' );

		$result = ( new Index() )->save_records(
			[
				[
					'objectID'   => '1',
					'post_title' => 'Test',
				],
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'onesearch_algolia_save_records_failed', $result->get_error_code() );
	}

	// ── search ──────────────────────────────────────────────────────────

	/**
	 * Returns WP_Error when credentials are missing.
	 */
	public function test_search_returns_error_without_credentials(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$result = ( new Index() )->search( 'test query' );

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_credentials_missing', $result->get_error_code() );
	}

	/**
	 * Returns search payload for search with valid credentials.
	 */
	public function test_search_returns_results_with_valid_credentials(): void {
		$this->set_governing_credentials();

		$recorded_paths = [];

		$this->mock_algolia_http_client( $recorded_paths );

		$result = ( new Index() )->search( 'test query' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hits', $result );
		$this->assertSame( '1', $result['hits'][0]['objectID'] ?? '' );
		$this->assertNotEmpty( $recorded_paths );
	}

	/**
	 * Returns WP_Error when SDK throws during search.
	 */
	public function test_search_returns_error_when_sdk_throws(): void {
		$this->set_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths, null, '/query' );

		$result = ( new Index() )->search( 'test query' );

		$this->assertWPError( $result );
		$this->assertSame( 'onesearch_algolia_search_failed', $result->get_error_code() );
	}

	/**
	 * Returns WP_Error when SDK throws while setting index settings.
	 */
	public function test_delete_by_returns_set_settings_error_when_sdk_throws_on_settings(): void {
		$this->set_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths, null, '/settings' );

		$result = ( new Index() )->delete_by( [ 'filters' => 'site_url:"http://test.com"' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_set_settings_failed', $result->get_error_code() );
	}

	// ── index_all_posts ─────────────────────────────────────────────────

	/**
	 * Returns WP_Error when delete_by fails (no credentials).
	 */
	public function test_index_all_posts_returns_error_when_delete_fails(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$result = ( new Index() )->index_all_posts( [ 'post' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_credentials_missing', $result->get_error_code() );
	}

	/**
	 * Returns true when index_all_posts is called with no post types and valid credentials.
	 */
	public function test_index_all_posts_returns_true_with_valid_credentials_and_no_post_types(): void {
		$this->set_governing_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$result = ( new Index() )->index_all_posts( [] );

		$this->assertTrue( $result );
		$this->assertNotEmpty( $recorded_paths );
	}

	// ── helpers ────────────────────────────────────────────────────────

	/**
	 * Set governing-site context with valid Algolia credentials.
	 */
	private function set_governing_credentials(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);
	}
}
