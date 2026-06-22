<?php // phpcs:disable -- This is a stub file
/**
 * Extends WP_Query and WP_Post with OneSearch dynamic properties for PHPStan analysis.
 */

namespace {
	/**
	 * Extended WP_Query with OneSearch dynamic properties.
	 *
	 * @property bool $is_algolia_search Whether this is an Algolia-powered search query.
	 */
	class WP_Query {
	}

	/**
	 * Extended WP_Post with OneSearch dynamic properties.
	 *
	 * @property array<string, string> $onesearch_algolia_highlights Highlighted search snippets from Algolia.
	 * @property string                 $onesearch_site_url URL of the site this post belongs to.
	 * @property string                 $onesearch_site_name Name of the site this post belongs to.
	 * @property string                 $onesearch_remote_post_author_display_name Remote author display name.
	 * @property string                 $onesearch_remote_post_author_link Remote author posts URL.
	 * @property string                 $onesearch_remote_post_author_gravatar Remote author avatar URL.
	 * @property int                    $onesearch_original_id Original post ID on remote site.
	 * @property array<string, mixed>   $onesearch_remote_taxonomies Taxonomies from remote site.
	 */
	class WP_Post {
	}
}
