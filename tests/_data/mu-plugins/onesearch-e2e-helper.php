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
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\Support\Mock_Algolia_Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const REST_NAMESPACE = 'onesearch-e2e/v1';

/**
 * The options the suite is allowed to read, seed and clear.
 *
 * `onesearch_site_type` is why this helper exists: its registered enum rejects
 * an empty value, so `/wp/v2/settings` can never restore the unset state.
 */
const MANAGED_OPTIONS = [
	'onesearch_algolia_credentials',
	'onesearch_consumer_api_key',
	'onesearch_indexable_entities',
	'onesearch_parent_site_url',
	'onesearch_proxy_attachment_id',
	'onesearch_shared_sites',
	'onesearch_site_type',
	'onesearch_sites_search_settings',
];

/**
 * Transients the plugin caches state in, cleared alongside the options.
 *
 * The brand config is held for a week, so one spec's value would outlive the
 * whole run. Read from the plugin's constant so a rename cannot break the reset.
 *
 * Returns nothing while the plugin is deactivated — the activation spec has such
 * a window, and nothing can have written the transient during it.
 *
 * @return list<string>
 */
function managed_transients(): array {
	if ( ! class_exists( Governing_Data_Handler::class ) ) {
		return [];
	}

	return [ Governing_Data_Handler::TRANSIENT_KEY ];
}

/**
 * How the mock Algolia transport should behave, set through the state endpoint.
 */
const ALGOLIA_MODE_OPTION = 'onesearch_e2e_algolia_mode';

/**
 * The mode the current request should use.
 *
 * The double is autoloaded by the plugin, so it is out of reach while the plugin
 * is deactivated — see `managed_transients()`. Read the stored value first so a
 * reset in that window answers instead of fataling on a class constant.
 */
function algolia_mode(): string {
	$stored = get_option( ALGOLIA_MODE_OPTION, '' );

	if ( is_string( $stored ) && '' !== $stored ) {
		return $stored;
	}

	return class_exists( Mock_Algolia_Http_Client::class ) ? Mock_Algolia_Http_Client::MODE_OK : '';
}

/**
 * The modes the state endpoint accepts.
 *
 * @return list<string>
 */
function algolia_modes(): array {
	return class_exists( Mock_Algolia_Http_Client::class ) ? Mock_Algolia_Http_Client::MODES : [];
}

/**
 * Replace the Algolia SDK's transport with the shared test double.
 *
 * Shared with PHPUnit, so the response shapes are defined once. Installing it at
 * the SDK's `setHttpClient()` seam leaves the plugin's own REST routes real.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( Mock_Algolia_Http_Client::class ) ) {
			return;
		}

		// The smoke suite opts out so it can exercise the real service.
		if ( Mock_Algolia_Http_Client::MODE_LIVE === algolia_mode() ) {
			return;
		}

		// Held by reference for the client's lifetime; the suite does not read it.
		$recorded_paths = [];

		\OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia::setHttpClient(
			new Mock_Algolia_Http_Client( $recorded_paths, null, null, __NAMESPACE__ . '\\algolia_mode' )
		);
	},
	PHP_INT_MAX
);

/**
 * Ensure pretty permalinks.
 *
 * The admin bundles call `home_url( '/wp-json/' )` directly, which only resolves
 * with a permalink structure set. A fresh wp-env install has none.
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
			'options'      => $options,
			'algolia_mode' => algolia_mode(),
		]
	);
}

/**
 * Seed managed options. A `null` value deletes the option.
 *
 * Written with the plugin's `update_option_*` listeners detached, so seeding is
 * pure data and never triggers an Algolia round trip.
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

	$mode = null;

	if ( array_key_exists( 'algolia_mode', $params ) ) {
		$mode = (string) $params['algolia_mode'];

		if ( ! in_array( $mode, algolia_modes(), true ) ) {
			return new \WP_Error(
				'onesearch_e2e_unknown_algolia_mode',
				sprintf( 'Unknown Algolia mode: %s.', $mode ),
				[ 'status' => 400 ]
			);
		}
	}

	detach_option_listeners();

	foreach ( $options as $option => $value ) {
		write_option( (string) $option, $value );
	}

	if ( null !== $mode ) {
		update_option( ALGOLIA_MODE_OPTION, $mode, false );
	}

	return get_state();
}

/**
 * Delete every managed option and transient.
 */
function reset_state(): \WP_REST_Response {
	detach_option_listeners();

	foreach ( MANAGED_OPTIONS as $option ) {
		delete_option( $option );
	}

	foreach ( managed_transients() as $transient ) {
		delete_transient( $transient );
	}

	delete_option( ALGOLIA_MODE_OPTION );

	return get_state();
}

/**
 * Write a single option, deleting it when the value is `null`.
 *
 * Secrets are stored encrypted, so seeding one has to encrypt too. Writing
 * plaintext would not fail loudly — `Encryptor::decrypt()` returns anything it
 * cannot base64-decode unchanged — so the suite would silently stop exercising
 * encryption. A failed encryption throws instead.
 *
 * @param string $option The option name.
 * @param mixed  $value  The value to store, or `null` to delete.
 *
 * @throws \RuntimeException When a value that must be encrypted cannot be.
 */
function write_option( string $option, $value ): void {
	if ( null === $value ) {
		delete_option( $option );
		return;
	}

	if ( 'onesearch_shared_sites' === $option && is_array( $value ) ) {
		// `update_option()` reports false for an unchanged value, so the result cannot tell a no-op from a failure.
		Settings::set_shared_sites( $value );
		return;
	}

	if ( 'onesearch_algolia_credentials' === $option && is_array( $value ) ) {
		// The setter encrypts the write key, so the plugin's own reader is what decrypts it again.
		Search_Settings::set_algolia_credentials( $value );

		if ( ! empty( $value['write_key'] ) && empty( Search_Settings::get_algolia_credentials()['write_key'] ) ) {
			throw new \RuntimeException( 'Could not encrypt the seeded Algolia write key.' );
		}

		return;
	}

	if ( 'onesearch_consumer_api_key' === $option && is_string( $value ) ) {
		$encrypted = Encryptor::encrypt( $value );

		if ( ! is_string( $encrypted ) ) {
			throw new \RuntimeException( 'Could not encrypt the seeded API key.' );
		}

		update_option( $option, $encrypted, false );
		return;
	}

	update_option( $option, $value );
}

/**
 * Detach the plugin's `update_option_*` listeners for this request.
 *
 * A real settings change purges caches and deletes indices, which reach out to
 * Algolia. Seeding should not.
 */
function detach_option_listeners(): void {
	foreach ( MANAGED_OPTIONS as $option ) {
		remove_all_actions( 'update_option_' . $option );
		remove_all_actions( 'add_option_' . $option );
	}
}
