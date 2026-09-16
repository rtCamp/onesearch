<?php
/**
 * Admin notices for disconnections that could not be delivered to the paired site.
 *
 * @package OneSearch\Modules\Settings
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Settings;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Utils;

/**
 * Class - Disconnect_Notices
 */
final class Disconnect_Notices implements Registrable {
	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'render_pending_disconnect_notices' ] );
		add_action( 'admin_post_onesearch_retry_disconnect_notices', [ $this, 'handle_retry_disconnect_notices' ] );
		add_action( 'admin_notices', [ $this, 'render_pending_governing_disconnect_notice' ] );
		add_action( 'admin_post_onesearch_retry_governing_disconnect', [ $this, 'handle_retry_governing_disconnect' ] );
	}

	/**
	 * Warns the admin when the governing site could not be told this brand site disconnected,
	 * with a button to retry - this is never retried automatically.
	 */
	public function render_pending_governing_disconnect_notice(): void {
		if ( ! Settings::is_consumer_site() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pending = Governing_Data_Handler::get_pending_governing_disconnect();

		if ( null === $pending ) {
			return;
		}

		// Pairing with the same governing site again makes the recorded disconnect moot.
		if ( Governing_Data_Handler::is_pending_governing_disconnect_stale( $pending['url'] ) ) {
			return;
		}

		echo '<div class="notice notice-warning">';
		self::render_disconnect_retry_row(
			sprintf(
				/* translators: %s: governing site URL. */
				__( 'The governing site "%s" could not be notified that this site disconnected, and may still list this site as connected.', 'onesearch' ),
				$pending['url']
			),
			'onesearch_retry_governing_disconnect'
		);
		echo '</div>';
	}

	/**
	 * Handles the "Retry" button on the pending-governing-disconnect admin notice.
	 */
	public function handle_retry_governing_disconnect(): void {
		self::handle_retry_action(
			'onesearch_retry_governing_disconnect',
			[ Governing_Data_Handler::class, 'retry_pending_governing_disconnect' ]
		);
	}

	/**
	 * Warns the admin when a brand site could not be told it was disconnected, with a
	 * button to retry - these are never retried automatically.
	 */
	public function render_pending_disconnect_notices(): void {
		if ( ! Settings::is_governing_site() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pending = Governing_Data_Handler::get_pending_disconnect_notices();

		if ( empty( $pending ) ) {
			return;
		}

		echo '<div class="notice notice-warning">';

		foreach ( $pending as $site_url => $notice ) {
			self::render_disconnect_retry_row(
				sprintf(
					/* translators: %s: brand site name. */
					__( 'The "%s" couldn\'t be notified that it was disconnected.', 'onesearch' ),
					$notice['name']
				),
				'onesearch_retry_disconnect_notices',
				[ 'site_url' => $site_url ]
			);
		}

		echo '</div>';
	}

	/**
	 * Handles the "Retry" button on the pending-disconnect-notices admin notice.
	 */
	public function handle_retry_disconnect_notices(): void {
		self::handle_retry_action(
			'onesearch_retry_disconnect_notices',
			static function (): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by handle_retry_action() before this runs.
				$site_url = isset( $_POST['site_url'] ) ? Utils::normalize_url( esc_url_raw( wp_unslash( $_POST['site_url'] ) ) ) : '';

				if ( ! empty( $site_url ) ) {
					Governing_Data_Handler::retry_pending_disconnect_notice( $site_url );
				}
			}
		);
	}

	/**
	 * Renders one message-plus-Retry-button row for a pending-disconnect admin notice.
	 *
	 * @param string               $message      The (untranslated-escaped) notice text.
	 * @param string               $nonce_action Nonce action, reused as the admin-post `action`.
	 * @param array<string,string> $hidden_fields Extra hidden `<input>` fields the handler needs.
	 */
	private static function render_disconnect_retry_row( string $message, string $nonce_action, array $hidden_fields = [] ): void {
		// A <p> auto-closes as soon as a <form> follows it, which would break the layout; use a <div> instead.
		echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">';

		printf( '<p style="margin:0;">%s</p>', esc_html( $message ) );

		$hidden_inputs = sprintf( '<input type="hidden" name="action" value="%s" />', esc_attr( $nonce_action ) );
		foreach ( $hidden_fields as $name => $value ) {
			$hidden_inputs .= sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $value ) );
		}

		printf(
			'<form method="post" action="%1$s" style="margin:0;">%2$s%3$s<button type="submit" class="button button-secondary">%4$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( $nonce_action, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-generated markup, not user input.
			$hidden_inputs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_attr()'d pieces above.
			esc_html__( 'Retry', 'onesearch' )
		);

		echo '</div>';
	}

	/**
	 * Shared guard/redirect scaffolding for the pending-disconnect "Retry" admin-post handlers.
	 *
	 * @param string   $nonce_action Nonce action to verify against the request.
	 * @param callable $retry        Called with no arguments to perform the retry.
	 */
	private static function handle_retry_action( string $nonce_action, callable $retry ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'onesearch' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $nonce_action );

		$retry();

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
