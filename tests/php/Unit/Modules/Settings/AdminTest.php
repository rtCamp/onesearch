<?php
/**
 * Admin settings screen unit tests.
 *
 * @package OneSearch\Tests\Unit\Modules\Settings
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Settings;

use OneSearch\Modules\Settings\Admin;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the settings admin screen.
 */
#[CoversClass( \OneSearch\Modules\Settings\Admin::class )]
final class AdminTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'dashboard' );

		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		$this->remove_menu_entries();

		parent::tearDown();
	}

	/**
	 * Ensures the class can be instantiated and hook methods can be called without error.
	 */
	public function test_class_instantiation(): void {
		$admin = new Admin();

		$admin->register_hooks();
		$admin->enqueue_scripts( Admin::SCREEN_ID );

		// If we made it this far with no errors, we are good.
		$this->assertTrue( true );
	}

	/**
	 * Ensures the top-level menu page is registered.
	 */
	public function test_add_admin_menu_registers_menu_page(): void {
		( new Admin() )->add_admin_menu();

		$this->assertTrue( $this->menu_contains_slug( Admin::MENU_SLUG ) );
	}

	/**
	 * Ensures the settings submenu page is registered.
	 */
	public function test_add_submenu_registers_submenu_page(): void {
		$admin = new Admin();

		$admin->add_admin_menu();
		$admin->add_submenu();

		$this->assertArrayHasKey( Admin::MENU_SLUG, $GLOBALS['submenu'] );
		$this->assertTrue( $this->submenu_contains_slug( Admin::MENU_SLUG, Admin::SCREEN_ID ) );
	}

	/**
	 * Tests that the default submenu is removed when conditions are met.
	 */
	public function test_remove_default_submenu_removes_submenu_when_conditions_met(): void {
		$admin = new Admin();
		$admin->add_admin_menu();
		$admin->add_submenu();

		// Test return early if governing site with shared sites, as submenu should not be removed.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		update_option(
			Settings::OPTION_GOVERNING_SHARED_SITES,
			[
				[
					'url'     => 'https://example.com',
					'name'    => 'Test',
					'api_key' => '',
				],
			]
		);
		$admin->remove_default_submenu();

		$this->assertTrue( $this->submenu_contains_slug( Admin::MENU_SLUG, Admin::MENU_SLUG ) );

		// Test with no shared sites.
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		$admin->remove_default_submenu();

		$this->assertArrayHasKey( Admin::MENU_SLUG, $GLOBALS['submenu'] );
		$this->assertFalse( $this->submenu_contains_slug( Admin::MENU_SLUG, Admin::MENU_SLUG ) );
	}

	/**
	 * Ensures the screen callback renders the settings mount point.
	 */
	public function test_screen_callback_outputs_expected_html(): void {
		ob_start();
		( new Admin() )->screen_callback();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'onesearch-settings-page', $output );
		$this->assertStringContainsString( 'wrap', $output );
	}

	/**
	 * Ensures the plugin action links include a settings link.
	 */
	public function test_add_action_links_appends_settings_link(): void {
		$links = ( new Admin() )->add_action_links( [] );

		$this->assertCount( 1, $links );
		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertStringContainsString( 'admin.php?page=' . Admin::SCREEN_ID, $links[0] );

		// Test that the method does not error when given invalid input.
		$this->setExpectedIncorrectUsage( Admin::class . '::add_action_links' );

		( new Admin() )->add_action_links( 'invalid' );
	}

	/**
	 * Ensures admin body classes are returned as a string.
	 */
	public function test_add_body_classes_returns_classes_string(): void {
		set_current_screen( 'plugins.php' );
		$admin = new Admin();

		$classes = $admin->add_body_classes( '' );

		$this->assertIsString( $classes );
		$this->assertStringContainsString( 'onesearch-site-selection-modal', $classes );
		$this->assertStringContainsString( 'onesearch-missing-brand-sites', $classes );

		// Test with already configured which should remove the missing-brand-sites class and keep the modal class.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		update_option(
			Settings::OPTION_GOVERNING_SHARED_SITES,
			[
				[
					'url'     => 'https://example.com',
					'name'    => 'Test',
					'api_key' => '',
				],
			]
		);
		$classes = $admin->add_body_classes( '' );
		$this->assertIsString( $classes );
		$this->assertStringNotContainsString( 'onesearch-site-selection-modal', $classes );
		$this->assertStringNotContainsString( 'onesearch-missing-brand-sites', $classes );

		// Test with a bad current screen which should return the original classes unmodified.
		set_current_screen( 'not-a-real-screen' );
		$classes = $admin->add_body_classes( 'original-classes' );
		$this->assertSame( 'original-classes', $classes );
	}

	/**
	 * Ensures the site-selection modal renders when onboarding is needed.
	 */
	public function test_inject_site_selection_modal_outputs_html_when_conditions_met(): void {
		set_current_screen( 'plugins.php' );

		ob_start();
		( new Admin() )->inject_site_selection_modal();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'onesearch-site-selection-modal', $output );
		$this->assertStringContainsString( 'onesearch-modal', $output );
	}

	/**
	 * Ensures the site-selection modal does not render after setup.
	 */
	public function test_inject_site_selection_modal_outputs_nothing_when_site_type_set(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		set_current_screen( 'plugins.php' );

		ob_start();
		( new Admin() )->inject_site_selection_modal();
		$output = (string) ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	/**
	 * Checks whether a menu slug is present in the admin menu.
	 *
	 * @param string $slug Menu slug.
	 */
	private function menu_contains_slug( string $slug ): bool {
		foreach ( (array) $GLOBALS['menu'] as $menu_item ) {
			if ( isset( $menu_item[2] ) && $slug === $menu_item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether a submenu slug is present for a menu slug.
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
