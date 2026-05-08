<?php
/**
 * Search integration unit tests.
 *
 * @package OneSearch\Tests\Unit\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Search;

use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Search;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
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
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Resetting test state.
		global $post, $wp_query, $wp_the_query;

		$post         = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
		$wp_the_query = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable

		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		parent::tearDown();
	}

	// ── register_hooks ──────────────────────────────────────────────────

	/**
	 * Ensures register_hooks adds the posts_pre_query filter.
	 */
	public function test_register_hooks_adds_posts_pre_query_filter(): void {
		$search = new Search();
		$search->register_hooks();

		$this->assertNotFalse( has_filter( 'posts_pre_query', [ $search, 'get_algolia_results' ] ) );
	}

	/**
	 * Ensures register_hooks adds permalink filters.
	 */
	public function test_register_hooks_adds_permalink_filters(): void {
		$search = new Search();
		$search->register_hooks();

		$this->assertNotFalse( has_filter( 'post_link', [ $search, 'get_post_type_permalink' ] ) );
		$this->assertNotFalse( has_filter( 'page_link', [ $search, 'get_post_type_permalink' ] ) );
		$this->assertNotFalse( has_filter( 'post_type_link', [ $search, 'get_post_type_permalink' ] ) );
		$this->assertNotFalse( has_filter( 'page_type_link', [ $search, 'get_post_type_permalink' ] ) );
		$this->assertNotFalse( has_filter( 'attachment_link', [ $search, 'get_post_type_permalink' ] ) );
	}

	/**
	 * Ensures register_hooks adds author data filters.
	 */
	public function test_register_hooks_adds_author_filters(): void {
		$search = new Search();
		$search->register_hooks();

		$this->assertNotFalse( has_filter( 'get_the_author_display_name', [ $search, 'get_post_author' ] ) );
		$this->assertNotFalse( has_filter( 'author_link', [ $search, 'get_post_author_link' ] ) );
		$this->assertNotFalse( has_filter( 'get_avatar_url', [ $search, 'get_post_author_avatar' ] ) );
	}

	/**
	 * Ensures register_hooks adds term/taxonomy filters.
	 */
	public function test_register_hooks_adds_term_filters(): void {
		$search = new Search();
		$search->register_hooks();

		$this->assertNotFalse( has_filter( 'term_link', [ $search, 'get_term_link' ] ) );
		$this->assertNotFalse( has_filter( 'category_link', [ $search, 'get_category_link' ] ) );
		$this->assertNotFalse( has_filter( 'tag_link', [ $search, 'get_tag_link' ] ) );
		$this->assertNotFalse( has_filter( 'get_the_terms', [ $search, 'get_post_terms' ] ) );
		$this->assertNotFalse( has_filter( 'wp_get_post_terms', [ $search, 'get_post_terms' ] ) );
	}

	/**
	 * Ensures register_hooks adds block render filter.
	 */
	public function test_register_hooks_adds_render_block_filter(): void {
		$search = new Search();
		$search->register_hooks();

		$this->assertNotFalse( has_filter( 'render_block', [ $search, 'filter_render_block' ] ) );
	}

	// ── get_algolia_results ─────────────────────────────────────────────

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

	// ── get_post_type_permalink ─────────────────────────────────────────

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

	// ── get_post_author ─────────────────────────────────────────────────

	/**
	 * Returns default author name when search is not enabled.
	 */
	public function test_get_post_author_returns_default_when_search_disabled(): void {
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

	// ── get_post_author_link ────────────────────────────────────────────

	/**
	 * Returns default author link when search is not enabled.
	 */
	public function test_get_post_author_link_returns_default_when_search_disabled(): void {
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

	// ── get_post_author_avatar ──────────────────────────────────────────

	/**
	 * Returns default avatar URL when search is not enabled.
	 */
	public function test_get_post_author_avatar_returns_default_when_search_disabled(): void {
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

	// ── get_term_link ───────────────────────────────────────────────────

	/**
	 * Returns default term link when search is not enabled.
	 */
	public function test_get_term_link_returns_default_when_search_disabled(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search = new Search();
		$result = $search->get_term_link( 'https://example.com/category/news/', 1, 'category' );

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

	// ── get_category_link ───────────────────────────────────────────────

	/**
	 * Delegates to get_term_link with 'category' taxonomy.
	 */
	public function test_get_category_link_returns_default_when_search_disabled(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search = new Search();
		$result = $search->get_category_link( 'https://example.com/category/tech/', 5 );

		$this->assertSame( 'https://example.com/category/tech/', $result );
	}

	// ── get_tag_link ────────────────────────────────────────────────────

	/**
	 * Delegates to get_term_link with 'post_tag' taxonomy.
	 */
	public function test_get_tag_link_returns_default_when_search_disabled(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$search = new Search();
		$result = $search->get_tag_link( 'https://example.com/tag/php/', 3 );

		$this->assertSame( 'https://example.com/tag/php/', $result );
	}

	// ── get_post_terms ──────────────────────────────────────────────────

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

	// ── filter_render_block ─────────────────────────────────────────────

	/**
	 * Returns unchanged block content when search is not enabled.
	 */
	public function test_filter_render_block_returns_original_when_search_disabled(): void {
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
	 * Enables Algolia for the governing site in options.
	 */
	private function enable_search_for_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		$site_url = Utils::normalize_url( get_site_url() );
		update_option(
			Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS,
			[
				$site_url => [
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
