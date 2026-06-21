<?php
/**
 * Search admin screen unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Core\Assets;
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
		wp_dequeue_script( Assets::SEARCH_SCRIPT_HANDLE );
		wp_deregister_script( Assets::SEARCH_SCRIPT_HANDLE );

		$this->delete_test_options();

		$this->remove_menu_entries();

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
	 * Ensures add_submenu registers the submenu and search page.
	 */
	public function test_add_submenu_registers_indices_and_search_page(): void {
		$admin = new Admin();

		add_menu_page( 'OneSearch', 'OneSearch', 'manage_options', Admin::MENU_SLUG, '__return_null' );
		$admin->add_submenu();

		$this->assertArrayHasKey( Admin::MENU_SLUG, $GLOBALS['submenu'] );
		$this->assertTrue( $this->submenu_contains_slug( Admin::MENU_SLUG, Admin::MENU_SLUG ) );
		$this->assertSame( 'Indices and Search', $GLOBALS['submenu'][ Admin::MENU_SLUG ][0][0] );
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
	 * Ensures enqueue_scripts enqueues the search script on the search page.
	 */
	public function test_enqueue_scripts_enqueues_on_search_page(): void {
		wp_register_script( Assets::SEARCH_SCRIPT_HANDLE, 'https://example.com/search.js', [], '1.0.0', true );

		( new Admin() )->enqueue_scripts( 'toplevel_page_' . Admin::SCREEN_ID );

		$this->assertTrue( wp_script_is( Assets::SEARCH_SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Ensures enqueue_scripts skips other admin pages.
	 */
	public function test_enqueue_scripts_skips_on_other_pages(): void {
		wp_register_script( Assets::SEARCH_SCRIPT_HANDLE, 'https://example.com/search.js', [], '1.0.0', true );

		( new Admin() )->enqueue_scripts( 'edit.php' );

		$this->assertFalse( wp_script_is( Assets::SEARCH_SCRIPT_HANDLE, 'enqueued' ) );
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

	/**
	 * Checks whether the submenu contains a slug.
	 *
	 * @param string $menu_slug    Parent menu slug.
	 * @param string $submenu_slug Submenu slug.
	 */
	private function submenu_contains_slug( string $menu_slug, string $submenu_slug ): bool {
		foreach ( (array) ( $GLOBALS['submenu'][ $menu_slug ] ?? [] ) as $submenu_item ) {
			if ( isset( $submenu_item[2] ) && $submenu_slug === $submenu_item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Removes menu entries registered by this test.
	 */
	private function remove_menu_entries(): void {
		foreach ( (array) ( $GLOBALS['menu'] ?? [] ) as $index => $menu_item ) {
			if ( isset( $menu_item[2] ) && Admin::MENU_SLUG === $menu_item[2] ) {
				unset( $GLOBALS['menu'][ $index ] );
			}
		}

		unset( $GLOBALS['submenu'][ Admin::MENU_SLUG ] );
	}
}
