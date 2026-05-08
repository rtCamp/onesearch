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
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);

		$index  = new Index();
		$result = $index->get_index();

		$this->assertInstanceOf( \OneSearch\Vendor\Algolia\AlgoliaSearch\SearchIndex::class, $result );
	}

	/**
	 * Caches the index instance on subsequent calls.
	 */
	public function test_get_index_returns_same_instance_on_second_call(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);

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
}
