<?php
/**
 * Algolia unit tests.
 *
 * @package OneSearch\Tests\Unit\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Search;

use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Algolia;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Algolia module.
 */
#[CoversClass( \OneSearch\Modules\Search\Algolia::class )]
final class AlgoliaTest extends TestCase {
	/**
	 * Cleans up Algolia test state.
	 */
	protected function tearDown(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		parent::tearDown();
	}

	/**
	 * Ensures missing credentials return an error.
	 */
	public function test_get_index_returns_error_when_no_credentials(): void {
		$result = ( new Algolia() )->get_index();

		$this->assertWPError( $result );
	}

	/**
	 * Ensures an empty app ID returns an error.
	 */
	public function test_get_index_returns_error_when_empty_app_id(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => '',
				'write_key' => 'test-write-key',
			]
		);

		$result = ( new Algolia() )->get_index();

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_credentials_missing', $this->get_error_code( $result ) );
	}

	/**
	 * Ensures an empty write key returns an error.
	 */
	public function test_get_index_returns_error_when_empty_write_key(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'test-app-id',
				'write_key' => '',
			]
		);

		$result = ( new Algolia() )->get_index();

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_credentials_missing', $this->get_error_code( $result ) );
	}

	/**
	 * Ensures a missing site URL returns an error.
	 */
	public function test_get_index_returns_error_when_no_site_url(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		add_filter( 'pre_option_siteurl', '__return_empty_string' );

		$result = ( new Algolia() )->get_index();

		$this->assertWPError( $result );
		$this->assertSame( 'algolia_index_name_invalid', $this->get_error_code( $result ) );
	}

	/**
	 * Ensures a valid configuration returns an index instance.
	 */
	public function test_get_index_returns_search_index_when_credentials_valid(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);

		$result = ( new Algolia() )->get_index();

		$this->assertInstanceOf( \OneSearch\Vendor\Algolia\AlgoliaSearch\SearchIndex::class, $result );
	}

	/**
	 * Ensures the index name is generated in the expected format for governing sites.
	 */
	public function test_get_index_name_returns_expected_format_for_governing(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$site_url_filter = static fn (): string => 'https://example.org';
		add_filter( 'pre_option_siteurl', $site_url_filter );

		$result = $this->invoke_private_method( new Algolia(), 'get_index_name' );

		$this->assertSame( 'onesearch_example_org_wp_posts', $result );
	}

	/**
	 * Ensures an empty site URL results in an empty index name.
	 */
	public function test_get_index_name_returns_empty_for_empty_site_url(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$site_url_filter = static fn (): string => '';
		add_filter( 'pre_option_siteurl', $site_url_filter );

		$result = $this->invoke_private_method( new Algolia(), 'get_index_name' );

		$this->assertSame( '', $result );
	}

	/**
	 * Ensures the index name is generated in the expected format for consumer sites.
	 */
	public function test_get_index_name_returns_expected_format_for_consumer(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://brand.example.com' );

		$result = $this->invoke_private_method( new Algolia(), 'get_index_name' );

		$this->assertSame( 'onesearch_brand_example_com_wp_posts', $result );
	}

	/**
	 * Ensures an empty parent site URL results in an empty index name.
	 */
	public function test_get_algolia_credentials_returns_from_settings_for_governing(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'my_app',
				'write_key' => 'my_key',
			]
		);

		$result = $this->invoke_private_method( new Algolia(), 'get_algolia_credentials' );

		$this->assertSame(
			[
				'app_id'    => 'my_app',
				'write_key' => 'my_key',
			],
			$result
		);
	}

	/**
	 * Ensures credentials are fetched from the brand config for consumer sites.
	 */
	public function test_get_algolia_credentials_returns_from_brand_config_for_consumer(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://brand.example.com' );

		$cached_config = [
			'algolia_credentials' => [
				'app_id'    => 'brand_app',
				'write_key' => 'brand_key',
			],
			'search_settings'     => [
				'algolia_enabled'  => true,
				'searchable_sites' => [],
			],
			'indexable_entities'  => [],
			'available_sites'     => [],
		];

		$method = new \ReflectionMethod( Governing_Data_Handler::class, 'set_brand_config_cache' );
		$method->invoke( null, $cached_config );

		$result = $this->invoke_private_method( new Algolia(), 'get_algolia_credentials' );

		$this->assertSame(
			[
				'app_id'    => 'brand_app',
				'write_key' => 'brand_key',
			],
			$result
		);
	}

	/**
	 * Gets a WP_Error code if present.
	 *
	 * @param mixed $value Value under test.
	 */
	private function get_error_code( mixed $value ): ?string {
		return is_object( $value ) && method_exists( $value, 'get_error_code' )
			? $value->get_error_code()
			: null;
	}

	/**
	 * Invokes a private or protected method on an object.
	 *
	 * @param object            $instance Object instance.
	 * @param string            $method Method name.
	 * @param array<int, mixed> $args Optional arguments to pass to the method.
	 *
	 * @return mixed Method return value.
	 */
	private function invoke_private_method( object $instance, string $method, array $args = [] ): mixed {
		$ref = new \ReflectionClass( $instance );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $instance, $args );
	}
}
