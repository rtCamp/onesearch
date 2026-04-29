<?php
/**
 * Asset registration unit tests.
 *
 * @package OneSearch\Tests\Unit\Modules\Core
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Core;

use OneSearch\Modules\Core\Assets;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for asset registration and localization.
 */
#[CoversClass( \OneSearch\Modules\Core\Assets::class )]
final class AssetsTest extends TestCase {
	/**
	 * Tests no errors on class instantiation.
	 */
	public function test_assets_class_instantiation(): void {
		$assets = new Assets();

		$assets->register_hooks();
		$assets->register_assets();
		$assets->enqueue_scripts();

		$this->assertTrue( true );
	}

	/**
	 * Ensures plugin scripts receive a defer attribute.
	 */
	public function test_defer_scripts_adds_defer_for_plugin_handles(): void {
		$assets = new Assets();
		$tag    = '<script src="https://example.com/script.js"></script>';

		$actual = $assets->defer_scripts( $tag, Assets::SETTINGS_SCRIPT_HANDLE );

		$this->assertSame( '<script defer src="https://example.com/script.js"></script>', $actual );
	}

	/**
	 * Ensures unrelated script handles are unchanged.
	 */
	public function test_defer_scripts_does_not_modify_other_handles(): void {
		$assets = new Assets();
		$tag    = '<script src="https://example.com/script.js"></script>';

		$this->assertSame( $tag, $assets->defer_scripts( $tag, 'other-handle' ) );
	}

	/**
	 * Ensures defer is not duplicated on existing script tags.
	 */
	public function test_defer_scripts_does_not_duplicate_defer_attribute(): void {
		$assets = new Assets();
		$tag    = '<script defer src="https://example.com/script.js"></script>';

		$this->assertSame( $tag, $assets->defer_scripts( $tag, Assets::SEARCH_SCRIPT_HANDLE ) );
	}

	/**
	 * Ensures localized data exposes the expected keys.
	 */
	public function test_get_localized_data_returns_expected_keys(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		$data = Assets::get_localized_data();

		$this->assertArrayHasKey( 'currentSiteUrl', $data );
		$this->assertArrayHasKey( 'indexableEntities', $data );
		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertArrayHasKey( 'api_key', $data );
		$this->assertArrayHasKey( 'restNamespace', $data );
		$this->assertArrayHasKey( 'restUrl', $data );
		$this->assertArrayHasKey( 'setupUrl', $data );
		$this->assertArrayHasKey( 'sharedSites', $data );
		$this->assertArrayHasKey( 'siteType', $data );
	}
}
