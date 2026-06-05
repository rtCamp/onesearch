<?php
/**
 * Search integration unit tests.
 *
 * @package OneSearch\Tests\Unit\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Search;

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
		$remote_post->guid                  = 'https://remote.example.com/posts/17/';

		$wp_query->posts = [ $remote_post ];

		$search = new Search();
		$result = $search->get_post_type_permalink( 'https://example.com/local/', -18 );

		$this->assertSame( 'https://remote.example.com/posts/17/', $result );
	}

	/**
	 * Returns default author name when search is not enabled.
	 *
	 * Primes $wp_query and sets a remote $post so that should_filter_query() and
	 * the negative-ID guard both pass — ensuring the default is returned solely
	 * because search is disabled, not because of a missing query or local post.
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

		// Now disable search — this is the sole reason the default should be returned.
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

		$post     = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID = -10;
		$post->onesearch_remote_post_author_display_name = 'Remote Author';

		$search = new Search();
		$result = $search->get_post_author( 'Default Author' );

		$this->assertSame( 'Remote Author', $result );
	}

	/**
	 * Returns default author link when search is not enabled.
	 *
	 * Primes query and remote $post so the only exit condition is search being disabled.
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
		$post->onesearch_remote_post_author_link = 'https://remote.example.com/authors/john/';

		$search = new Search();
		$result = $search->get_post_author_link( 'https://example.com/author/admin/' );

		$this->assertSame( 'https://remote.example.com/authors/john/', $result );
	}

	/**
	 * Returns default avatar URL when search is not enabled.
	 *
	 * Primes query and remote $post so the only exit condition is search being disabled.
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
		$post->onesearch_remote_post_author_gravatar = 'https://remote.example.com/avatar.jpg';

		$search = new Search();
		$result = $search->get_post_author_avatar( 'https://example.com/avatar.jpg' );

		$this->assertSame( 'https://remote.example.com/avatar.jpg', $result );
	}

	/**
	 * Returns default term link when search is not enabled.
	 *
	 * Primes query and remote $post so the only exit condition is search being disabled.
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
	 * Sets a negative-ID $post with a guid so the only exit condition is
	 * search being disabled, not a missing/local post.
	 */
	public function test_filter_render_block_returns_original_when_search_disabled(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting up test global state.
		global $post;

		$post       = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID   = -99;
		$post->guid = 'https://remote.example.com/post/99/';

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

		$post       = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID   = -14;
		$post->guid = 'https://remote.example.com/post/14/';

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

		$post               = new \WP_Post( new \stdClass() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post->ID           = -15;
		$post->guid         = 'https://remote.example.com/post/15/';
		$post->post_excerpt = 'Remote excerpt body';

		$search  = new Search();
		$content = '<div class="wp-block-post-excerpt"><p>Old excerpt</p></div>';
		$block   = [ 'blockName' => 'core/post-excerpt' ];

		$result = $search->filter_render_block( $content, $block );

		$this->assertStringContainsString( 'Remote excerpt body', $result );
		$this->assertStringNotContainsString( 'Old excerpt', $result );
	}

	/**
	 * Returns rankingScore directly when present in _rankingInfo.
	 */
	public function test_compute_algolia_score_returns_ranking_score_when_present(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'compute_algolia_score' );

		$hit = [ '_rankingInfo' => [ 'rankingScore' => 0.85 ] ];

		$this->assertSame( 0.85, $method->invoke( $search, $hit ) );
	}

	/**
	 * Derives a composite score when rankingScore is absent.
	 */
	public function test_compute_algolia_score_falls_back_to_composite_formula(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'compute_algolia_score' );

		// userScore=3, words=5, nbTypos=2, proximityDistance=100, geoDistance=5000.
		// => 3*1e6 + 5*1e3 - 2*1e4 - 100 - 5000/1000 = 2_984_895.
		$hit = [
			'_rankingInfo' => [
				'nbTypos'           => 2,
				'words'             => 5,
				'proximityDistance' => 100,
				'userScore'         => 3,
				'geoDistance'       => 5000,
			],
		];

		$this->assertSame( 2984895.0, $method->invoke( $search, $hit ) );
	}

	/**
	 * Returns 0.0 when _rankingInfo is absent entirely.
	 */
	public function test_compute_algolia_score_defaults_to_zero_without_ranking_info(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'compute_algolia_score' );

		$this->assertSame( 0.0, $method->invoke( $search, [] ) );
	}

	/**
	 * Extracts field→value map from _highlightResult.
	 */
	public function test_extract_algolia_highlights_extracts_highlight_result(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'extract_algolia_highlights' );

		$record = [
			'_highlightResult' => [
				'post_title'   => [ 'value' => 'Highlighted <em>Title</em>' ],
				'post_content' => [ 'value' => 'Highlighted <em>Content</em>' ],
			],
		];

		$result = $method->invoke( $search, $record );

		$this->assertCount( 2, $result );
		$this->assertSame( 'Highlighted <em>Title</em>', $result['post_title'] );
		$this->assertSame( 'Highlighted <em>Content</em>', $result['post_content'] );
	}

	/**
	 * Falls back to _snippetResult when _highlightResult is absent.
	 */
	public function test_extract_algolia_highlights_falls_back_to_snippet_result(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'extract_algolia_highlights' );

		$record = [
			'_snippetResult' => [
				'post_title' => [ 'value' => 'Snippet <em>Title</em>' ],
			],
		];

		$result = $method->invoke( $search, $record );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Snippet <em>Title</em>', $result['post_title'] );
	}

	/**
	 * Highlights take precedence over snippets when both contain the same field.
	 */
	public function test_extract_algolia_highlights_highlight_takes_precedence_over_snippet(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'extract_algolia_highlights' );

		$record = [
			'_highlightResult' => [
				'post_title' => [ 'value' => 'Highlight Value' ],
			],
			'_snippetResult'   => [
				'post_title' => [ 'value' => 'Snippet Value' ],
			],
		];

		$result = $method->invoke( $search, $record );

		$this->assertSame( 'Highlight Value', $result['post_title'] );
	}

	/**
	 * Skips fields that lack a value key in both highlight and snippet results.
	 */
	public function test_extract_algolia_highlights_requires_value_key(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'extract_algolia_highlights' );

		$record = [
			'_highlightResult' => [
				'post_title'   => [ 'value' => 'Good' ],
				'post_content' => [ 'matchLevel' => 'full' ],
			],
			'_snippetResult'   => [
				'post_content' => [ 'matchLevel' => 'partial' ],
				'post_excerpt' => [ 'value' => 'Snippet Excerpt' ],
			],
		];

		$result = $method->invoke( $search, $record );

		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 'post_title', $result );
		$this->assertArrayHasKey( 'post_excerpt', $result );
		$this->assertArrayNotHasKey( 'post_content', $result );
	}

	/**
	 * Returns null when the record is missing the post_id key.
	 */
	public function test_build_post_from_record_returns_null_without_post_id(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'build_post_from_record' );

		$this->assertNull(
			$method->invoke( $search, [ 'site_url' => 'https://remote.example.com/' ] )
		);
	}

	/**
	 * Returns null when the record is missing the site_url key.
	 */
	public function test_build_post_from_record_returns_null_without_site_url(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'build_post_from_record' );

		$this->assertNull(
			$method->invoke( $search, [ 'post_id' => 42 ] )
		);
	}

	/**
	 * Returns the real WP_Post when the record site_url equals the local site.
	 */
	public function test_build_post_from_record_returns_local_post_when_site_matches(): void {
		$post_id = self::factory()->post->create();
		$post    = get_post( $post_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'build_post_from_record' );

		$site_url = Utils::normalize_url( get_site_url() );

		$result = $method->invoke(
			$search,
			[
				'post_id'   => $post_id,
				'site_url'  => $site_url,
				'site_name' => 'Local Site',
			]
		);

		$this->assertInstanceOf( \WP_Post::class, $result );
		$this->assertSame( $post_id, $result->ID );
		$this->assertSame( $site_url, $result->onesearch_site_url );
		$this->assertSame( 'Local Site', $result->onesearch_site_name );
	}

	/**
	 * Creates a remote post with negative ID and all author properties set.
	 */
	public function test_build_post_from_record_creates_remote_post_with_author_data(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'build_post_from_record' );

		$result = $method->invoke(
			$search,
			[
				'post_id'           => 15,
				'site_url'          => 'https://remote.example.com/',
				'permalink'         => 'https://remote.example.com/posts/15/',
				'content'           => 'Post body',
				'post_excerpt'      => 'Excerpt text',
				'post_name'         => 'remote-post',
				'post_title'        => 'Remote Post',
				'post_type'         => 'page',
				'site_name'         => 'Remote Site',
				'post_date_gmt'     => 1710000000,
				'post_modified_gmt' => 1710100000,
				'post_author_data'  => [
					'author_id'           => 7,
					'author_display_name' => 'Remote Author',
					'author_posts_url'    => 'https://remote.example.com/author/ra/',
					'author_avatar'       => 'https://remote.example.com/avatar.jpg',
				],
			]
		);

		$this->assertInstanceOf( \WP_Post::class, $result );
		$this->assertSame( -16, $result->ID );
		$this->assertSame( 'Remote Post', $result->post_title );
		$this->assertSame( 'page', $result->post_type );
		$this->assertSame( 'https://remote.example.com/posts/15/', $result->guid );
		$this->assertSame( 15, $result->onesearch_original_id );
		$this->assertSame( 'https://remote.example.com/', $result->onesearch_site_url );
		$this->assertSame( 'Remote Site', $result->onesearch_site_name );
		$this->assertSame( '-1007', $result->post_author );
		$this->assertSame( 'Remote Author', $result->onesearch_remote_post_author_display_name );
		$this->assertSame( 'https://remote.example.com/author/ra/', $result->onesearch_remote_post_author_link );
		$this->assertSame( 'https://remote.example.com/avatar.jpg', $result->onesearch_remote_post_author_gravatar );
	}

	/**
	 * Creates a remote post without author data and still gets taxonomy metadata.
	 */
	public function test_build_post_from_record_creates_remote_post_without_author(): void {
		$search = new Search();
		$method = new \ReflectionMethod( Search::class, 'build_post_from_record' );

		$result = $method->invoke(
			$search,
			[
				'post_id'           => 8,
				'site_url'          => 'https://other.example.com/',
				'post_title'        => 'No Author Post',
				'post_type'         => 'post',
				'permalink'         => 'https://other.example.com/posts/8/',
				'post_date_gmt'     => 1710000000,
				'post_modified_gmt' => 1710000000,
				'taxonomies'        => [
					[
						'taxonomy'    => 'category',
						'term_id'     => 3,
						'slug'        => 'news',
						'name'        => 'News',
						'term_link'   => 'https://other.example.com/category/news/',
						'count'       => 5,
						'description' => 'News category',
						'parent'      => 0,
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Post::class, $result );
		$this->assertSame( -9, $result->ID );
		$this->assertFalse( isset( $result->onesearch_remote_post_author_display_name ) );
		$this->assertCount( 1, $result->onesearch_remote_taxonomies );
		$this->assertSame( 'News', $result->onesearch_remote_taxonomies[0]['name'] );
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
