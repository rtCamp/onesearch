<?php
/**
 * Search admin screen unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Search\Admin;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the search admin screen.
 */
#[CoversClass( \OneSearch\Modules\Search\Admin::class )]
final class AdminTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'dashboard' );

		$this->delete_test_options();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		$this->delete_test_options();

		parent::tearDown();
	}

	/**
	 * Ensures the class can be instantiated and hook methods can be called without error.
	 */
	public function test_class_instantiation(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'app-id',
				'write_key' => 'write-key',
			]
		);

		$admin = new Admin();

		$admin->register_hooks();
		$admin->enqueue_scripts( Admin::SCREEN_ID );

		$this->assertTrue( true );
	}

	/**
	 * Ensures register_hooks skips when the site is not governing.
	 */
	public function test_register_hooks_skips_when_not_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'app-id',
				'write_key' => 'write-key',
			]
		);

		$admin = new Admin();

		$admin->register_hooks();

		$this->assertFalse( has_action( 'admin_menu', [ $admin, 'add_submenu' ] ) );
		$this->assertFalse( has_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_scripts' ] ) );
	}

	/**
	 * Ensures the screen callback renders the search mount point.
	 */
	public function test_screen_callback_outputs_expected_html(): void {
		ob_start();
		( new Admin() )->screen_callback();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'onesearch-search-settings', $output );
		$this->assertStringContainsString( 'wrap', $output );
	}

	/**
	 * Removes persisted options used by the tests.
	 */
	private function delete_test_options(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
	}
}
