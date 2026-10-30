<?php
/**
 * Plugin Name: OneSearch - Algolia Helper
 * Description: Stubs the Algolia SDK in the test environments (ONESEARCH_MOCK_ALGOLIA) and records its requests for the E2E tests.
 * Version: 1.0.0
 * Author: rtCamp
 * License: GPL-2.0-or-later
 *
 * @package OneSearch\Dev
 */

declare( strict_types = 1 );

namespace OneSearch\Algolia_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ALGOLIA_REQUESTS_OPTION        = 'onesearch_e2e_algolia_requests';
const ALGOLIA_SEARCH_RESPONSE_OPTION = 'onesearch_e2e_algolia_search_response';

/**
 * Whether this is a test environment, which has no Algolia account.
 */
function is_algolia_mocked(): bool {
	return defined( 'ONESEARCH_MOCK_ALGOLIA' ) && ONESEARCH_MOCK_ALGOLIA && class_exists( \OneSearch\Tests\Support\Mock_Algolia_Http_Client::class );
}

/**
 * The Algolia requests made during this page load, shared by every SDK client it creates.
 *
 * @return array<int, array{path: string, body: string}>
 */
function &algolia_requests(): array {
	static $requests = [];
	return $requests;
}

// E2E tests read the recorded requests and seed the search response through core's `/wp/v2/settings`, as JSON strings.
add_action(
	'init',
	static function (): void {
		if ( ! is_algolia_mocked() ) {
			return;
		}

		foreach ( [ ALGOLIA_REQUESTS_OPTION, ALGOLIA_SEARCH_RESPONSE_OPTION ] as $option ) {
			register_setting(
				'onesearch_e2e',
				$option,
				[
					'type'         => 'string',
					'show_in_rest' => true,
				]
			);
		}

		// Only brand sites have it, so a test can tell the post types a governing site fetched from its brand sites apart from its own.
		if ( 'brand-site' === get_option( 'onesearch_site_type' ) ) {
			register_post_type(
				'onesearch_e2e_item',
				[
					'label'  => 'Brand Items',
					'public' => true,
				]
			);
		}
	}
);

// Answer the Algolia SDK from canned payloads in the test environments.
add_filter(
	'onesearch_algolia_http_client',
	static function ( $client ) {
		if ( ! is_algolia_mocked() ) {
			return $client;
		}

		$search_response = (string) get_option( ALGOLIA_SEARCH_RESPONSE_OPTION, '' );

		$paths    = [];
		$requests = &algolia_requests();
		return new \OneSearch\Tests\Support\Mock_Algolia_Http_Client(
			$paths,
			static fn ( string $path ): ?string => '' !== $search_response && str_ends_with( $path, '/query' ) ? $search_response : null,
			null,
			$requests
		);
	}
);

// Last, since the plugin syncs media on `shutdown` too.
add_action(
	'shutdown',
	static function (): void {
		$requests = &algolia_requests();
		if ( [] === $requests ) {
			return;
		}

		$recorded = json_decode( (string) get_option( ALGOLIA_REQUESTS_OPTION, '' ), true );
		update_option( ALGOLIA_REQUESTS_OPTION, (string) wp_json_encode( array_merge( is_array( $recorded ) ? $recorded : [], $requests ) ), false );
	},
	PHP_INT_MAX
);
