<?php
/**
 * Search integration unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Search\Search;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia as AlgoliaSDK;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Search class.
 */
#[CoversClass( \OneSearch\Modules\Search\Search::class )]
final class SearchTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		AlgoliaSDK::resetHttpClient();

		parent::tearDown();
	}

	/**
	 * Ensures class can be instantiated.
	 */
	public function test_class_instantiation(): void {
		$search = new Search();
		$search->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Returns original posts when search is not enabled.
	 */
	public function test_get_algolia_results_returns_original_posts_when_search_disabled(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );
		delete_option( Settings::OPTION_SITE_TYPE );

		$search   = new Search();
		$query    = new \WP_Query();
		$original = [ self::factory()->post->create_and_get() ];

		$result = $search->get_algolia_results( $original, $query );

		$this->assertSame( $original, $result );
	}

	/**
	 * Returns original posts when query is not a search query.
	 */
	public function test_get_algolia_results_returns_original_posts_for_non_search_query(): void {
		$this->enable_search_for_governing_site();

		$search = new Search();
		$query  = new \WP_Query();
		$query->init();

		$original = [ self::factory()->post->create_and_get() ];
		$result   = $search->get_algolia_results( $original, $query );

		$this->assertSame( $original, $result );
	}

	/**
	 * Returns original posts when the query is not a WP_Query instance.
	 */
	public function test_get_algolia_results_returns_original_posts_for_non_wp_query(): void {
		$search   = new Search();
		$original = [ self::factory()->post->create_and_get() ];

		// @phpstan-ignore argument.type
		$result = $search->get_algolia_results( $original, 'not-a-query' );

		$this->assertSame( $original, $result );
	}

	/**
	 * Returns mapped remote posts when search is enabled and query is a main search query.
	 */
	public function test_get_algolia_results_returns_remote_posts_when_search_enabled(): void {
		$this->enable_search_for_governing_site();
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);

		$recorded_paths = [];
		$this->mock_algolia_http_client(
			$recorded_paths,
			static function ( string $path ): string {
				if ( str_contains( $path, '/query' ) ) {
					return wp_json_encode(
						[
							'hits'        => [
								[
									'objectID'          => '17',
									'site_post_id'      => '17',
									'post_id'           => 17,
									'post_title'        => 'Remote Post',
									'post_type'         => 'post',
									'permalink'         => 'https://remote.example.com/posts/17/',
									'site_url'          => 'https://remote.example.com/',
									'site_name'         => 'Remote',
									'total_chunks'      => 1,
									'post_date_gmt'     => 1710000000,
									'post_modified_gmt' => 1710000000,
								],
							],
							'nbHits'      => 1,
							'page'        => 0,
							'hitsPerPage' => 10,
						]
					) ?: '{}';
				}

				if ( str_contains( $path, '/task/' ) ) {
					return '{"status":"published","pendingTask":false}';
				}

				return '{"taskID":1,"updatedAt":"2024-01-01T00:00:00.000Z"}';
			}
		);

		$this->prime_main_search_query( 'remote test' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reading query prepared by helper.
		global $wp_query;

		$search = new Search();
		$result = $search->get_algolia_results( [], $wp_query );

		$this->assertNotEmpty( $result );
		$this->assertInstanceOf( \WP_Post::class, $result[0] );
		$this->assertSame( -18, $result[0]->ID );
		$this->assertSame( 'https://remote.example.com/posts/17/', $result[0]->guid );
		$this->assertSame( 1, $wp_query->post_count );
		$this->assertSame( 1, $wp_query->found_posts );
		$this->assertTrue( (bool) ( $wp_query->is_algolia_search ?? false ) );
		$this->assertNotEmpty( $recorded_paths );
	}

	/**
	 * Sorts hits by Algolia ranking score (descending) without raising notices.
	 */
	public function test_get_algolia_results_sorts_hits_by_ranking_score(): void {
		$this->enable_search_for_governing_site();
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);

		$recorded_paths = [];
		$this->mock_algolia_http_client(
			$recorded_paths,
			static function ( string $path ): string {
				if ( str_contains( $path, '/query' ) ) {
					return wp_json_encode(
						[
							'hits'        => [
								[
									'objectID'          => '20',
									'site_post_id'      => '20',
									'post_id'           => 20,
									'post_title'        => 'Middle Score',
									'post_type'         => 'post',
									'permalink'         => 'https://remote.example.com/posts/20/',
									'site_url'          => 'https://remote.example.com/',
									'site_name'         => 'Remote',
									'total_chunks'      => 1,
									'post_date_gmt'     => 1710000000,
									'post_modified_gmt' => 1710000000,
									'_rankingInfo'      => [ 'rankingScore' => 0.5 ],
								],
								[
									'objectID'          => '30',
									'site_post_id'      => '30',
									'post_id'           => 30,
									'post_title'        => 'Lowest Score',
									'post_type'         => 'post',
									'permalink'         => 'https://remote.example.com/posts/30/',
									'site_url'          => 'https://remote.example.com/',
									'site_name'         => 'Remote',
									'total_chunks'      => 1,
									'post_date_gmt'     => 1710000000,
									'post_modified_gmt' => 1710000000,
									'_rankingInfo'      => [ 'rankingScore' => 0.1 ],
								],
								[
									'objectID'          => '40',
									'site_post_id'      => '40',
									'post_id'           => 40,
									'post_title'        => 'Highest Score',
									'post_type'         => 'post',
									'permalink'         => 'https://remote.example.com/posts/40/',
									'site_url'          => 'https://remote.example.com/',
									'site_name'         => 'Remote',
									'total_chunks'      => 1,
									'post_date_gmt'     => 1710000000,
									'post_modified_gmt' => 1710000000,
									'_rankingInfo'      => [ 'rankingScore' => 0.9 ],
								],
							],
							'nbHits'      => 3,
							'page'        => 0,
							'hitsPerPage' => 10,
						]
					) ?: '{}';
				}

				if ( str_contains( $path, '/task/' ) ) {
					return '{"status":"published","pendingTask":false}';
				}

				return '{"taskID":1,"updatedAt":"2024-01-01T00:00:00.000Z"}';
			}
		);

		$this->prime_main_search_query( 'remote test' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reading query prepared by helper.
		global $wp_query;

		$search = new Search();
		$result = $search->get_algolia_results( [], $wp_query );

		$this->assertCount( 3, $result );
		$this->assertSame( 'Highest Score', $result[0]->post_title );
		$this->assertSame( 'Middle Score', $result[1]->post_title );
		$this->assertSame( 'Lowest Score', $result[2]->post_title );
	}

	/**
	 * Returns default permalink for local posts (positive ID).
	 */
	public function test_get_post_type_permalink_returns_default_for_local_post(): void {
		$search = new Search();
		$post   = self::factory()->post->create_and_get();

		$result = $search->get_post_type_permalink( 'https://example.com/test/', $post );

		$this->assertSame( 'https://example.com/test/', $result );
	}

	/**
	 * Returns remote permalink for placeholder posts during enabled search.
	 */
	public function test_get_post_type_permalink_returns_remote_for_placeholder_post(): void {
		$this->enable_search_for_governing_site();
		$this->prime_main_search_query( 'test query' );

		global $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$remote_post                        = new \WP_Post( new \stdClass() );
		$remote_post->onesearch_original_id = 17;
		$remote_post->ID                    = -18;
		$remote_post->guid                  = 'https://remote.example.com/posts/17/';

		$wp_query->posts = [ $remote_post ];

		$search = new Search();
		$result = $search->get_post_type_permalink( 'https://example.com/local/', -18 );

		$this->assertSame( 'https://remote.example.com/posts/17/', $result );
	}

	/**
	 * Returns the default author name when search is not enabled.
	 *
	 * Sets otherwise valid query/post context so disabled search is the only unmet
	 * precondition.
	 */
	public function test_get_post_author_returns_default_when_search_disabled(): void {
		// Set up everything search needs EXCEPT the search settings option itself.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post     = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID = -99;
		$post->onesearch_remote_post_author_display_name = 'Remote Author';

		// Now disable search; this is the sole reason the default should be returned.
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search = new Search();
		$result = $search->get_post_author( 'Default Author' );

		$this->assertSame( 'Default Author', $result );
	}

	/**
	 * Returns remote author name when search is enabled for remote posts.
	 */
	public function test_get_post_author_returns_remote_when_search_enabled(): void {
		$this->enable_search_for_governing_site();
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                        = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                    = -10;
		$post->onesearch_original_id = 9;
		$post->onesearch_remote_post_author_display_name = 'Remote Author';

		$search = new Search();
		$result = $search->get_post_author( 'Default Author' );

		$this->assertSame( 'Remote Author', $result );
	}

	/**
	 * Returns the default author link when search is not enabled.
	 *
	 * Sets otherwise valid query/post context so disabled search is the only unmet
	 * precondition.
	 */
	public function test_get_post_author_link_returns_default_when_search_disabled(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                                    = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                                = -99;
		$post->onesearch_remote_post_author_link = 'https://remote.example.com/authors/john/';

		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search = new Search();
		$result = $search->get_post_author_link( 'https://example.com/author/admin/' );

		$this->assertSame( 'https://example.com/author/admin/', $result );
	}

	/**
	 * Returns remote author link when search is enabled for remote posts.
	 */
	public function test_get_post_author_link_returns_remote_when_search_enabled(): void {
		$this->enable_search_for_governing_site();
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                                    = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                                = -11;
		$post->onesearch_original_id             = 10;
		$post->onesearch_remote_post_author_link = 'https://remote.example.com/authors/john/';

		$search = new Search();
		$result = $search->get_post_author_link( 'https://example.com/author/admin/' );

		$this->assertSame( 'https://remote.example.com/authors/john/', $result );
	}

	/**
	 * Returns the default avatar URL when search is not enabled.
	 *
	 * Sets otherwise valid query/post context so disabled search is the only unmet
	 * precondition.
	 */
	public function test_get_post_author_avatar_returns_default_when_search_disabled(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                                        = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                                    = -99;
		$post->onesearch_remote_post_author_gravatar = 'https://remote.example.com/avatar.jpg';

		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search = new Search();
		$result = $search->get_post_author_avatar( 'https://example.com/avatar.jpg' );

		$this->assertSame( 'https://example.com/avatar.jpg', $result );
	}

	/**
	 * Returns remote author avatar when search is enabled for remote posts.
	 */
	public function test_get_post_author_avatar_returns_remote_when_search_enabled(): void {
		$this->enable_search_for_governing_site();
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                                        = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                                    = -12;
		$post->onesearch_original_id                 = 11;
		$post->onesearch_remote_post_author_gravatar = 'https://remote.example.com/avatar.jpg';

		$search = new Search();
		$result = $search->get_post_author_avatar( 'https://example.com/avatar.jpg' );

		$this->assertSame( 'https://remote.example.com/avatar.jpg', $result );
	}

	/**
	 * Returns the default term link when search is not enabled.
	 *
	 * Sets otherwise valid query/post context so disabled search is the only unmet
	 * precondition.
	 */
	public function test_get_term_link_returns_default_when_search_disabled(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                              = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                          = -99;
		$post->onesearch_remote_taxonomies = [
			[
				'taxonomy'  => 'category',
				'term_id'   => 7,
				'slug'      => 'news',
				'term_link' => 'https://remote.example.com/category/news/',
			],
		];

		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search = new Search();
		$result = $search->get_term_link( 'https://example.com/category/news/', 7, 'category' );

		$this->assertSame( 'https://example.com/category/news/', $result );
	}

	/**
	 * Returns remote term link when search is enabled and taxonomy data exists.
	 */
	public function test_get_term_link_returns_remote_when_search_enabled(): void {
		$this->enable_search_for_governing_site();
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                              = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                          = -13;
		$post->onesearch_original_id       = 12;
		$post->onesearch_remote_taxonomies = [
			[
				'taxonomy'  => 'category',
				'term_id'   => 7,
				'slug'      => 'news',
				'term_link' => 'https://remote.example.com/category/news/',
			],
		];

		$search = new Search();
		$result = $search->get_term_link( 'https://example.com/category/news/', 7, 'category' );

		$this->assertSame( 'https://remote.example.com/category/news/', $result );
	}

	/**
	 * Delegates to get_term_link with 'category' taxonomy.
	 */
	public function test_get_category_link_returns_default_when_search_disabled(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search = new Search();
		$result = $search->get_category_link( 'https://example.com/category/tech/', 5 );

		$this->assertSame( 'https://example.com/category/tech/', $result );
	}

	/**
	 * Delegates to get_term_link with 'category' taxonomy for enabled remote search.
	 */
	public function test_get_category_link_returns_remote_when_search_enabled(): void {
		$this->enable_search_for_governing_site();
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                              = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                          = -105;
		$post->onesearch_original_id       = 104;
		$post->onesearch_remote_taxonomies = [
			[
				'taxonomy'  => 'category',
				'term_id'   => 5,
				'slug'      => 'tech',
				'term_link' => 'https://remote.example.com/category/tech/',
			],
		];

		$search = new Search();
		$result = $search->get_category_link( 'https://example.com/category/tech/', 5 );

		$this->assertSame( 'https://remote.example.com/category/tech/', $result );
	}

	/**
	 * Delegates to get_term_link with 'post_tag' taxonomy.
	 */
	public function test_get_tag_link_returns_default_when_search_disabled(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search = new Search();
		$result = $search->get_tag_link( 'https://example.com/tag/php/', 3 );

		$this->assertSame( 'https://example.com/tag/php/', $result );
	}

	/**
	 * Delegates to get_term_link with 'post_tag' taxonomy for enabled remote search.
	 */
	public function test_get_tag_link_returns_remote_when_search_enabled(): void {
		$this->enable_search_for_governing_site();
		$this->prime_main_search_query( 'test query' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                              = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                          = -106;
		$post->onesearch_original_id       = 105;
		$post->onesearch_remote_taxonomies = [
			[
				'taxonomy'  => 'post_tag',
				'term_id'   => 3,
				'slug'      => 'php',
				'term_link' => 'https://remote.example.com/tag/php/',
			],
		];

		$search = new Search();
		$result = $search->get_tag_link( 'https://example.com/tag/php/', 3 );

		$this->assertSame( 'https://remote.example.com/tag/php/', $result );
	}

	/**
	 * Returns original terms when search is not enabled.
	 */
	public function test_get_post_terms_returns_original_when_search_disabled(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search   = new Search();
		$original = [ new \WP_Term( new \stdClass() ) ];

		$result = $search->get_post_terms( $original, 1, 'category' );
		$this->assertSame( $original, $result );
	}

	/**
	 * Returns mapped terms for enabled search when remote taxonomy metadata exists.
	 */
	public function test_get_post_terms_returns_remote_terms_when_search_enabled(): void {
		$this->enable_search_for_governing_site();
		$this->prime_main_search_query( 'test query' );

		$post_id = self::factory()->post->create();
		$post    = get_post( $post_id );

		$this->assertInstanceOf( \WP_Post::class, $post );

		$post->onesearch_remote_taxonomies = [
			[
				'taxonomy'    => 'category',
				'term_id'     => 22,
				'slug'        => 'remote-news',
				'name'        => 'Remote News',
				'term_link'   => 'https://remote.example.com/category/remote-news/',
				'count'       => 3,
				'description' => 'Remote category',
				'parent'      => 0,
			],
		];

		wp_cache_set( $post_id, $post, 'posts' );

		$search = new Search();
		$result = $search->get_post_terms( [], $post_id, 'category' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertInstanceOf( \WP_Term::class, $result[0] );
		$this->assertSame( 'Remote News', $result[0]->name );
		$this->assertSame( 'category', $result[0]->taxonomy );
		$this->assertSame( 22, $result[0]->term_id );
	}

	/**
	 * Returns unchanged block content when search is not enabled.
	 *
	 * Sets otherwise valid remote-post context so disabled search is the only unmet
	 * precondition.
	 */
	public function test_filter_render_block_returns_original_when_search_disabled(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                        = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                    = -99;
		$post->onesearch_original_id = 98;
		$post->guid                  = 'https://remote.example.com/post/99/';

		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search  = new Search();
		$content = '<h2><a href="https://example.com/old/">Title</a></h2>';
		$block   = [ 'blockName' => 'core/post-title' ];

		$result = $search->filter_render_block( $content, $block );

		$this->assertSame( $content, $result );
	}

	/**
	 * Rewrites title block link for remote posts when search is enabled.
	 */
	public function test_filter_render_block_rewrites_title_link_for_remote_post(): void {
		$this->enable_search_for_governing_site();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                        = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                    = -14;
		$post->onesearch_original_id = 13;
		$post->guid                  = 'https://remote.example.com/post/14/';

		$search  = new Search();
		$content = '<h2><a href="https://example.com/old/">Title</a></h2>';
		$block   = [ 'blockName' => 'core/post-title' ];

		$result = $search->filter_render_block( $content, $block );

		$this->assertStringContainsString( 'https://remote.example.com/post/14/', $result );
		$this->assertStringNotContainsString( 'https://example.com/old/', $result );
	}

	/**
	 * Rewrites excerpt block text for remote posts when search is enabled.
	 */
	public function test_filter_render_block_rewrites_excerpt_for_remote_post(): void {
		$this->enable_search_for_governing_site();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post                        = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID                    = -15;
		$post->onesearch_original_id = 14;
		$post->guid                  = 'https://remote.example.com/post/15/';
		$post->post_excerpt          = 'Remote excerpt body';

		$search  = new Search();
		$content = '<div class="wp-block-post-excerpt"><p>Old excerpt</p></div>';
		$block   = [ 'blockName' => 'core/post-excerpt' ];

		$result = $search->filter_render_block( $content, $block );

		$this->assertStringContainsString( 'Remote excerpt body', $result );
		$this->assertStringNotContainsString( 'Old excerpt', $result );
	}

	/**
	 * Passes the meta value through unchanged for local posts (non-negative IDs).
	 */
	public function test_get_remote_thumbnail_id_passes_through_for_local_post(): void {
		$search = new Search();

		$result = $search->get_remote_thumbnail_id( 'original-value', 123, '_thumbnail_id', true );

		$this->assertSame( 'original-value', $result );
	}

	/**
	 * Points a remote post's _thumbnail_id at the shared proxy attachment.
	 */
	public function test_get_remote_thumbnail_id_returns_proxy_for_remote_post(): void {
		$remote                        = new \WP_Post( new \stdClass() );
		$remote->ID                    = -18;
		$remote->onesearch_original_id = 17;
		$remote->post_title            = 'Remote Post';
		$remote->onesearch_thumbnail   = [
			'url'    => 'https://remote.example.com/uploads/img-300x200.jpeg',
			'width'  => 300,
			'height' => 200,
		];

		$search = new Search();
		$this->set_private_property( $search, 'remote_posts_map', [ -18 => $remote ] );

		$proxy_id = (int) $search->get_remote_thumbnail_id( null, -18, '_thumbnail_id', true );

		$proxy_post = get_post( $proxy_id );
		$this->assertGreaterThan( 0, $proxy_id );
		$this->assertInstanceOf( \WP_Post::class, $proxy_post );
		$this->assertSame( 'attachment', $proxy_post->post_type );
	}

	/**
	 * Featured image: resolves a regular remote post's thumbnail to the remote file.
	 */
	public function test_get_remote_attachment_image_src_uses_remote_file_for_featured_image(): void {
		global $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Ensure no attachment-as-result context.
		$post = null;

		$remote                        = new \WP_Post( new \stdClass() );
		$remote->ID                    = -18;
		$remote->onesearch_original_id = 17;
		$remote->post_title            = 'Remote Post';
		$remote->onesearch_thumbnail   = [
			'url'    => 'https://remote.example.com/uploads/img-300x200.jpeg',
			'width'  => 300,
			'height' => 200,
		];

		$search = new Search();
		$this->set_private_property( $search, 'remote_posts_map', [ -18 => $remote ] );

		// Drives the featured-image path: records current_remote_post + proxy id.
		$proxy_id = (int) $search->get_remote_thumbnail_id( null, -18, '_thumbnail_id', true );

		$src = $search->get_remote_attachment_image_src( false, $proxy_id, 'medium', false );

		$this->assertSame(
			[ 'https://remote.example.com/uploads/img-300x200.jpeg', 300, 200, false ],
			$src
		);
	}

	/**
	 * Resolves the in-loop post's thumbnail even after another remote post's
	 * _thumbnail_id was requested, guarding the shared current_remote_post slot
	 * from being overwritten before the image renders.
	 */
	public function test_get_remote_attachment_image_src_prefers_in_loop_post_over_last_recorded(): void {
		$first                        = new \WP_Post( new \stdClass() );
		$first->ID                    = -18;
		$first->onesearch_original_id = 17;
		$first->post_title            = 'First Remote';
		$first->onesearch_thumbnail   = [
			'url'    => 'https://remote.example.com/uploads/first-300x200.jpeg',
			'width'  => 300,
			'height' => 200,
		];

		$second                        = new \WP_Post( new \stdClass() );
		$second->ID                    = -20;
		$second->onesearch_original_id = 19;
		$second->post_title            = 'Second Remote';
		$second->onesearch_thumbnail   = [
			'url'    => 'https://remote.example.com/uploads/second-640x480.jpeg',
			'width'  => 640,
			'height' => 480,
		];

		$search = new Search();
		$this->set_private_property(
			$search,
			'remote_posts_map',
			[
				-18 => $first,
				-20 => $second,
			]
		);

		// Both posts' _thumbnail_id are requested before any image renders, so the
		// shared current_remote_post slot now points at $second.
		$proxy_id = (int) $search->get_remote_thumbnail_id( null, -18, '_thumbnail_id', true );
		$search->get_remote_thumbnail_id( null, -20, '_thumbnail_id', true );

		// $first is the post actually being rendered in the loop.
		global $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the in-loop post.
		$post = $first;

		$src = $search->get_remote_attachment_image_src( false, $proxy_id, 'medium', false );

		$this->assertSame(
			[ 'https://remote.example.com/uploads/first-300x200.jpeg', 300, 200, false ],
			$src
		);
	}

	/**
	 * Leaves the image data untouched for attachments that are not ours.
	 */
	public function test_get_remote_attachment_image_src_passes_through_for_local_attachment(): void {
		$search   = new Search();
		$original = [ 'https://example.com/local.jpg', 100, 100, false ];

		$result = $search->get_remote_attachment_image_src( $original, 99, 'medium', false );

		$this->assertSame( $original, $result );
	}

	/**
	 * Attachment result: resolves the proxy URL to the remote file via the global post.
	 */
	public function test_get_remote_attachment_url_uses_remote_file_for_attachment_result(): void {
		$proxy_id = 4242;

		global $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The attachment result is the post being rendered.
		$post                        = new \WP_Post( new \stdClass() );
		$post->ID                    = $proxy_id;
		$post->post_type             = 'attachment';
		$post->onesearch_original_id = 8;
		$post->post_title            = 'Remote Image';
		$post->onesearch_thumbnail   = [
			'url'    => 'https://remote.example.com/uploads/photo-300x200.jpeg',
			'width'  => 300,
			'height' => 200,
		];

		$search = new Search();
		$this->set_private_property( $search, 'proxy_attachment_id', $proxy_id );

		$url = $search->get_remote_attachment_url( 'https://example.com/local-file.jpg', $proxy_id );

		$this->assertSame( 'https://remote.example.com/uploads/photo-300x200.jpeg', $url );
	}

	/**
	 * Leaves the URL untouched for attachment IDs that are not the proxy.
	 */
	public function test_get_remote_attachment_url_passes_through_for_local_attachment(): void {
		$search = new Search();
		$this->set_private_property( $search, 'proxy_attachment_id', 4242 );

		$url = $search->get_remote_attachment_url( 'https://example.com/local.jpg', 99 );

		$this->assertSame( 'https://example.com/local.jpg', $url );
	}

	/**
	 * Sets remote alt text and strips local srcset/sizes for a proxy attachment.
	 */
	public function test_filter_remote_attachment_image_attributes_sets_alt_and_strips_srcset(): void {
		$proxy_id = 4242;

		global $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The attachment result is the post being rendered.
		$post                        = new \WP_Post( new \stdClass() );
		$post->ID                    = $proxy_id;
		$post->post_type             = 'attachment';
		$post->onesearch_original_id = 8;
		$post->post_title            = 'Remote Image';
		$post->onesearch_thumbnail   = [
			'url'    => 'https://remote.example.com/uploads/photo.jpeg',
			'width'  => 300,
			'height' => 200,
		];

		$search = new Search();
		$this->set_private_property( $search, 'proxy_attachment_id', $proxy_id );

		$attr = $search->filter_remote_attachment_image_attributes(
			[
				'srcset' => 'https://example.com/local-300w.jpg 300w',
				'sizes'  => '(max-width: 300px) 100vw, 300px',
				'alt'    => '',
			],
			$post,
			'medium'
		);

		$this->assertArrayNotHasKey( 'srcset', $attr );
		$this->assertArrayNotHasKey( 'sizes', $attr );
		$this->assertSame( 'Remote Image', $attr['alt'] );
	}

	/**
	 * Sets a private property on a Search instance for focused test setup.
	 *
	 * @param \OneSearch\Modules\Search\Search $search The Search instance.
	 * @param string                           $prop   The private property name.
	 * @param mixed                            $value  The value to assign.
	 */
	private function set_private_property( Search $search, string $prop, $value ): void {
		$reflection = new \ReflectionProperty( Search::class, $prop );
		$reflection->setAccessible( true );
		$reflection->setValue( $search, $value );
	}

	/**
	 * Enables Algolia for the governing site in options.
	 */
	private function enable_search_for_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$site_url               = Utils::normalize_url( get_site_url() );
		$site_url_with_trailing = trailingslashit( get_site_url() );
		update_option(
			Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS,
			[
				$site_url               => [
					'algolia_enabled'  => true,
					'searchable_sites' => [ $site_url ],
				],
				$site_url_with_trailing => [
					'algolia_enabled'  => true,
					'searchable_sites' => [ $site_url ],
				],
			]
		);
	}

	/**
	 * Prime the global main query as a frontend search query.
	 *
	 * @param string $term Search term.
	 */
	private function prime_main_search_query( string $term ): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Setting up test global state.
		global $wp_query, $wp_the_query;

		set_current_screen( 'front' );

		$wp_query     = new \WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable

		$wp_query->query( [ 's' => $term ] );
	}
}
