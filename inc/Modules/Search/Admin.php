<?php
/**
 * Registers the Admin settings used to control the search module.
 *
 * @package OneSearch\Modules\Settings
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Modules\Core\Assets;
use OneSearch\Modules\Settings\Settings;

/**
 * Class - Admin
 */
final class Admin implements Registrable {
	/**
	 * The menu slug for the admin menu.
	 *
	 * @todo replace with a cross-plugin menu.
	 */
	public const MENU_SLUG = 'onesearch';

	/**
	 * The screen ID for the settings page.
	 */
	public const SCREEN_ID = self::MENU_SLUG;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( ! Settings::is_governing_site() ) {
			return;
		}
		add_action( 'admin_menu', [ $this, 'add_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Register the settings page.
	 */
	public function add_submenu(): void {
		// Register the "Indices and Search" submenu for governing sites.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Indices and Search', 'onesearch' ),
			__( 'Indices and Search', 'onesearch' ),
			'manage_options',
			self::MENU_SLUG, // Reuse the main menu slug.
			[ $this, 'screen_callback' ],
			1, // Put this submenu at the top.
		);
	}

	/**
	 * Admin page content callback.
	 */
	public function screen_callback(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Settings', 'onesearch' ); ?></h1>
			<div id="onesearch-search-settings"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( strpos( $hook, self::SCREEN_ID ) === false ) {
			return;
		}

		wp_localize_script( Assets::SEARCH_SCRIPT_HANDLE, 'OneSearchSettings', Assets::get_localized_data() );

		wp_enqueue_script( Assets::SEARCH_SCRIPT_HANDLE );
	}
}
