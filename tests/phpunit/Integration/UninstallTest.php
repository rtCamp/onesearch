<?php
/**
 * Uninstall tests.
 *
 * @package OneSearch\Tests\Integration
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration;

use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Runs uninstall.php, which is a script rather than a class.
 */
#[CoversNothing]
final class UninstallTest extends TestCase {
	/**
	 * Uninstalling removes the options that hold the connection between the sites.
	 */
	public function test_uninstall_deletes_the_connection_options(): void {
		$options = [
			Settings::OPTION_CONSUMER_PARENT_SITE_URL,
			Settings::OPTION_CONSUMER_API_KEY,
		];

		foreach ( $options as $option ) {
			update_option( $option, 'value' );
		}

		// Nothing to clean up in Algolia or at a governing site.
		delete_option( Settings::OPTION_SITE_TYPE );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'onesearch/onesearch.php' );
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant, WordPressVIPMinimum.Files.IncludingFile.NotAbsolutePath -- Runs the script under test.
		include dirname( __DIR__, 3 ) . '/uninstall.php';

		foreach ( $options as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' should be deleted.' );
		}
	}
}
