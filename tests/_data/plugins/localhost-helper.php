<?php
/**
 * Plugin Name: OneSearch - Localhost Helper
 * Description: Rewrites localhost URLs to host.docker.internal for inter-container HTTP requests in wp-env Docker environments. Only affects URLs containing "onesearch/v1".
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: rtCamp
 * License: GPL-2.0-or-later
 *
 * @package OneSearch\Dev
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bypass URL validation for onesearch endpoints.
add_filter( // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args
	'http_request_args',
	static function ( array $args, string $url ): array {
		if ( false === strpos( $url, 'onesearch/v1' ) ) {
			return $args;
		}

		$args['reject_unsafe_urls'] = false;
		return $args;
	},
	PHP_INT_MAX,
	2,
);

// Reroute localhost requests to host.docker.internal for onesearch endpoints.
add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		if ( false === strpos( $url, 'onesearch/v1' ) || false === strpos( $url, '://localhost' ) ) {
			return $preempt;
		}

		$rewritten_url = str_replace( '://localhost', '://host.docker.internal', $url );

		// Temporarily remove this filter to avoid infinite recursion.
		remove_filter( 'pre_http_request', __FUNCTION__, PHP_INT_MAX );
		$result = wp_remote_request( $rewritten_url, $args );
		add_filter( 'pre_http_request', __FUNCTION__, PHP_INT_MAX, 3 );

		return $result;
	},
	PHP_INT_MAX,
	3,
);
