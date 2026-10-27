<?php
/**
 * Plugin Name: OneSearch - Localhost Helper
 * Description: Rewrites localhost URLs to host.docker.internal for inter-container HTTP requests in wp-env Docker environments. Only affects OneSearch routes and the WP-Cron and Action Scheduler loopbacks. In the test environments (ONESEARCH_MOCK_ALGOLIA), also stubs the Algolia SDK and records its requests.
 * Version: 1.0.0
 * Author: rtCamp
 * License: GPL-2.0-or-later
 *
 * @package OneSearch\Dev
 */

declare( strict_types = 1 );

namespace OneSearch\Localhost_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Bypass URL validation for onesearch endpoints.
add_filter( // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args
	'http_request_args',
	static function ( array $args, string $url ): array {
		if ( ! str_contains( $url, 'onesearch/v1' ) ) {
			return $args;
		}

		$args['reject_unsafe_urls'] = false;
		return $args;
	},
	PHP_INT_MAX,
	2,
);

// Reroute localhost requests to host.docker.internal for OneSearch routes and the WP-Cron and Action Scheduler loopbacks.
add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		if ( ! str_contains( $url, '://localhost' ) ) {
			return $preempt;
		}

		if ( ! str_contains( $url, 'onesearch/v1' ) && ! str_contains( $url, '/wp-cron.php' ) && ! str_contains( $url, '/wp-admin/admin-ajax.php' ) ) {
			return $preempt;
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return $preempt;
		}

		$original_host = $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '' );

		$rewritten_url = str_replace( '://localhost', '://host.docker.internal', $url );

		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = [];
		}
		$args['headers']['Host'] = $original_host;
		return wp_remote_request( $rewritten_url, $args );
	},
	PHP_INT_MAX,
	3,
);

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
