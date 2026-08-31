<?php
/**
 * Plugin Name: OneSearch - E2E Helper
 * Description: Test-only REST endpoints that let the Playwright suite seed and clear OneSearch options. Mapped into the wp-env test environments only.
 * Version: 1.0.0
 * Author: rtCamp
 * License: GPL-2.0-or-later
 *
 * @package OneSearch\Dev
 */

declare( strict_types = 1 );

namespace OneSearch\E2E_Helper;

use OneSearch\Encryptor;
use OneSearch\Modules\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const REST_NAMESPACE = 'onesearch-e2e/v1';

/**
 * The options the suite is allowed to read, seed and clear.
 *
 * Seeding `onesearch_site_type` is the reason this helper exists at all: the
 * option is registered with an enum that rejects an empty value, so the
 * onboarding state cannot be restored through `/wp/v2/settings`.
 */
const MANAGED_OPTIONS = [
	'onesearch_algolia_credentials',
	'onesearch_consumer_api_key',
	'onesearch_indexable_entities',
	'onesearch_parent_site_url',
	'onesearch_shared_sites',
	'onesearch_site_type',
	'onesearch_sites_search_settings',
];

/**
 * Ensure pretty permalinks.
 *
 * The plugin's admin bundles call `home_url( '/wp-json/' )` directly, which
 * only resolves when a permalink structure is set. A fresh wp-env install has
 * none, so set one once and flush the rules.
 */
add_action(
	'init',
	static function (): void {
		if ( '' !== (string) get_option( 'permalink_structure', '' ) ) {
			return;
		}

		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return;
		}

		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules();
	},
	PHP_INT_MAX
);

add_action(
	'rest_api_init',
	static function (): void {
		$permission_callback = static fn (): bool => current_user_can( 'manage_options' );

		register_rest_route(
			REST_NAMESPACE,
			'/state',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => __NAMESPACE__ . '\\get_state',
					'permission_callback' => $permission_callback,
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => __NAMESPACE__ . '\\set_state',
					'permission_callback' => $permission_callback,
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => __NAMESPACE__ . '\\reset_state',
					'permission_callback' => $permission_callback,
				],
			]
		);
	}
);

/**
 * Return the current value of every managed option.
 */
function get_state(): \WP_REST_Response {
	$options = [];

	foreach ( MANAGED_OPTIONS as $option ) {
		$options[ $option ] = get_option( $option, null );
	}

	return new \WP_REST_Response(
		[
			'success' => true,
			'options' => $options,
			'api_key' => read_api_key(),
		]
	);
}

/**
 * Decrypt the brand site's own API key, or an empty string when it has none.
 *
 * The governing site has to be seeded with the same key the brand site holds,
 * and the stored value is ciphertext. Unlike `Settings::get_api_key()` this
 * never generates a missing key, so a spec can assert that visiting the
 * connection screen is what creates one.
 */
function read_api_key(): string {
	$stored = get_option( 'onesearch_consumer_api_key', '' );

	if ( ! is_string( $stored ) || '' === $stored || ! class_exists( Encryptor::class ) ) {
		return '';
	}

	$decrypted = Encryptor::decrypt( $stored );

	return is_string( $decrypted ) ? $decrypted : '';
}

/**
 * Seed managed options.
 *
 * A `null` value deletes the option. Options are written with the plugin's
 * own `update_option_*` listeners detached, so seeding is pure data and never
 * triggers an Algolia round trip.
 *
 * @param \WP_REST_Request $request Request object with a JSON body.
 */
function set_state( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	$params = $request->get_json_params();

	if ( ! is_array( $params ) ) {
		return new \WP_Error( 'onesearch_e2e_invalid_body', 'Expected a JSON object.', [ 'status' => 400 ] );
	}

	$options = $params['options'] ?? [];

	if ( ! is_array( $options ) ) {
		return new \WP_Error( 'onesearch_e2e_invalid_options', 'Expected `options` to be an object.', [ 'status' => 400 ] );
	}

	$unknown = array_diff( array_keys( $options ), MANAGED_OPTIONS );

	if ( ! empty( $unknown ) ) {
		return new \WP_Error(
			'onesearch_e2e_unknown_option',
			sprintf( 'Unmanaged option(s): %s.', implode( ', ', $unknown ) ),
			[ 'status' => 400 ]
		);
	}

	detach_option_listeners();

	foreach ( $options as $option => $value ) {
		write_option( (string) $option, $value );
	}

	return get_state();
}

/**
 * Delete every managed option.
 */
function reset_state(): \WP_REST_Response {
	detach_option_listeners();

	foreach ( MANAGED_OPTIONS as $option ) {
		delete_option( $option );
	}

	return get_state();
}

/**
 * Write a single option, deleting it when the value is `null`.
 *
 * API keys are stored encrypted on both sides of the handshake, so seeding one
 * has to encrypt too: brand sites go through the plugin's own setter, and the
 * brand site's own key goes through the encryptor directly. A plaintext key
 * reads back as an empty string and every token comparison then fails.
 *
 * @param string $option The option name.
 * @param mixed  $value  The value to store, or `null` to delete.
 */
function write_option( string $option, $value ): void {
	if ( null === $value ) {
		delete_option( $option );
		return;
	}

	if ( 'onesearch_shared_sites' === $option && is_array( $value ) && class_exists( Settings::class ) ) {
		Settings::set_shared_sites( $value );
		return;
	}

	if ( 'onesearch_consumer_api_key' === $option && is_string( $value ) && class_exists( Encryptor::class ) ) {
		$encrypted = Encryptor::encrypt( $value );

		if ( is_string( $encrypted ) ) {
			update_option( $option, $encrypted, false );
			return;
		}
	}

	update_option( $option, $value );
}

/**
 * Detach the plugin's `update_option_*` listeners for the duration of the request.
 *
 * Seeding state should not fire the side effects (index deletion, cache purges)
 * that a real settings change would, since those reach out to Algolia.
 */
function detach_option_listeners(): void {
	foreach ( MANAGED_OPTIONS as $option ) {
		remove_all_actions( 'update_option_' . $option );
		remove_all_actions( 'add_option_' . $option );
	}
}
