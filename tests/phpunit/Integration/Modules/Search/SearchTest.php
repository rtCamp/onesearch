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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Search class.
 */
#[CoversClass( \OneSearch\Modules\Search\Search::class )]
final class SearchTest extends TestCase {
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
		$search = new Search();
		$result = $this->run_search(
			$search,
			[
				'hits'   => [ self::get_remote_hit() ],
				'nbHits' => 1,
			]
		);

		$this->assertNotEmpty( $result );
		$this->assertInstanceOf( \WP_Post::class, $result[0] );
		// A positive ID that core resolves, and that stays exact in JavaScript.
		$this->assertGreaterThan( 0, $result[0]->ID );
		$this->assertLessThan( 2 ** 53, $result[0]->ID );
		$this->assertSame( 17, $result[0]->onesearch_original_id );
		$this->assertSame( 'https://remote.example.com/posts/17/', $result[0]->guid );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reading query prepared by helper.
		global $wp_query;

		$this->assertSame( 1, $wp_query->post_count );
		$this->assertSame( 1, $wp_query->found_posts );
		$this->assertTrue( (bool) ( $wp_query->is_algolia_search ?? false ) );

		// WordPress must not cache the synthetic IDs in the `post-queries` cache.
		$this->assertFalse( $wp_query->get( 'cache_results' ) );
	}

	/**
	 * Remote posts get unique IDs, and core resolves them from the object cache without reading local meta.
	 */
	public function test_remote_posts_get_unique_ids_that_core_can_resolve(): void {
		$local_id = self::factory()->post->create();
		update_post_meta( $local_id, '_thumbnail_id', '999' );

		$search = new Search();
		$result = $this->run_search(
			$search,
			[
				'hits' => [
					self::get_remote_hit( [ 'post_title' => 'First' ] ),
					// Same post ID on another site.
					self::get_remote_hit(
						[
							'objectID'   => 'other_17_0',
							'site_url'   => 'https://other.example.com/',
							'site_name'  => 'Other',
							'permalink'  => 'https://other.example.com/posts/17/',
							'post_title' => 'Second',
						]
					),
				],
			]
		);

		$this->assertCount( 2, $result );
		$this->assertNotSame( $result[0]->ID, $result[1]->ID );

		$resolved = get_post( $result[1]->ID );
		$this->assertInstanceOf( \WP_Post::class, $resolved );
		$this->assertSame( 'Second', $resolved->post_title );
		$this->assertSame( 'https://other.example.com/', $resolved->onesearch_site_url );

		// No meta of the local post leaks in, and no thumbnail without a record thumbnail.
		$this->assertSame( '', get_post_meta( $result[0]->ID, '_thumbnail_id', true ) );
		$this->assertSame( [], get_post_meta( $result[0]->ID, 'anything', false ) );
		$this->assertFalse( has_post_thumbnail( $result[0]->ID ) );
	}

	/**
	 * Pagination comes from Algolia's totals.
	 */
	public function test_get_algolia_results_sets_found_posts_and_max_num_pages(): void {
		$search = new Search();
		$this->run_search(
			$search,
			[
				'hits'    => [ self::get_remote_hit() ],
				'nbHits'  => 12,
				'nbPages' => 4,
			]
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reading query prepared by helper.
		global $wp_query;

		$this->assertSame( 12, $wp_query->found_posts );
		$this->assertSame( 4, $wp_query->max_num_pages );
	}

	/**
	 * Without `nbPages`, the number of pages is derived from the total and the page size.
	 */
	public function test_get_algolia_results_derives_max_num_pages(): void {
		update_option( 'posts_per_page', 10 );

		$search = new Search();
		$this->run_search(
			$search,
			[
				'hits'   => [ self::get_remote_hit() ],
				'nbHits' => 12,
			]
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reading query prepared by helper.
		global $wp_query;

		$this->assertSame( 12, $wp_query->found_posts );
		$this->assertSame( 2, $wp_query->max_num_pages );
	}

	/**
	 * WordPress runs its native search when Algolia can't answer.
	 */
	public function test_get_algolia_results_falls_back_when_algolia_errors(): void {
		$this->enable_search_with_credentials();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths, null, '/query' );

		// The plugin's own instance is hooked at boot, and would run the search while the query is primed.
		remove_all_filters( 'posts_pre_query' );

		$this->prime_main_search_query( 'remote test' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reading query prepared by helper.
		global $wp_query;

		$result = ( new Search() )->get_algolia_results( null, $wp_query );

		$this->assertNull( $result );
		$this->assertFalse( (bool) ( $wp_query->is_algolia_search ?? false ) );
	}

	/**
	 * Algolia's order is kept, and ranking info isn't requested.
	 */
	public function test_get_algolia_results_keeps_algolia_order_and_sends_expected_params(): void {
		update_option( 'posts_per_page', 7 );

		$requests = [];
		$search   = new Search();
		$result   = $this->run_search(
			$search,
			[
				'hits' => [
					self::get_remote_hit(
						[
							'post_id'      => 20,
							'post_title'   => 'First',
							'_rankingInfo' => [ 'rankingScore' => 0.1 ],
						]
					),
					self::get_remote_hit(
						[
							'post_id'      => 30,
							'post_title'   => 'Second',
							'_rankingInfo' => [ 'rankingScore' => 0.9 ],
						]
					),
				],
			],
			$requests
		);

		$this->assertSame( [ 'First', 'Second' ], wp_list_pluck( $result, 'post_title' ) );

		$params = json_decode( $requests[0]['body'], true );

		$this->assertIsArray( $params );
		$this->assertArrayNotHasKey( 'getRankingInfo', $params );
		$this->assertSame( 7, $params['hitsPerPage'] );
		// Local results are boosted when this site is searched.
		$this->assertSame( [ 'site_url:' . Utils::get_current_site_url() ], $params['optionalFilters'] );
	}

	/**
	 * `posts_per_page` is clamped for Algolia.
	 */
	public function test_get_algolia_results_clamps_hits_per_page(): void {
		update_option( 'posts_per_page', 12 );

		$this->enable_search_with_credentials();

		foreach ( [
			-1   => 12,
			0    => 12,
			5000 => 1000,
		] as $posts_per_page => $expected ) {
			$requests = [];
			$this->mock_search_response( [ 'hits' => [] ], $requests );
			$this->prime_main_search_query( 'remote test' );

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reading query prepared by helper.
			global $wp_query;

			$wp_query->set( 'posts_per_page', $posts_per_page );
			( new Search() )->get_algolia_results( null, $wp_query );

			$params = json_decode( $requests[0]['body'], true );

			$this->assertIsArray( $params );
			$this->assertSame( $expected, $params['hitsPerPage'], "posts_per_page={$posts_per_page}" );
		}
	}

	/**
	 * Any site with the write key can write records, so the remote fields that themes print are sanitized.
	 */
	public function test_remote_records_are_sanitized(): void {
		$result = $this->run_search(
			new Search(),
			[
				'hits' => [
					self::get_remote_hit(
						[
							'post_title'       => '<img src=x onerror=alert(1)>Evil <b>title</b>',
							'post_excerpt'     => '<script>alert(2)</script>Excerpt',
							'content'          => '<script>alert(3)</script><p onclick="x()">Hi <a href="javascript:alert(4)">l</a></p>',
							'permalink'        => 'javascript:alert(5)',
							'site_name'        => '<b>Evil</b> Site',
							'post_author_data' => [
								'author_id'           => 3,
								'author_display_name' => '<i>Mallory</i>',
								'author_posts_url'    => 'javascript:alert(6)',
								'author_avatar'       => 'data:text/html,x',
							],
						]
					),
				],
			]
		);

		$this->assertCount( 1, $result );
		$post = $result[0];

		$this->assertStringNotContainsString( 'onerror', $post->post_title );
		$this->assertStringContainsString( '<b>title</b>', $post->post_title );
		$this->assertStringNotContainsString( '<script', $post->post_excerpt );
		$this->assertStringNotContainsString( '<script', $post->post_content );
		$this->assertStringNotContainsString( 'onclick', $post->post_content );
		$this->assertStringNotContainsString( 'javascript:', $post->post_content );
		$this->assertSame( '', $post->guid );
		$this->assertSame( 'Evil Site', $post->onesearch_site_name );
		$this->assertSame( 'Mallory', $post->onesearch_remote_post_author_display_name );
		$this->assertSame( '', $post->onesearch_remote_post_author_link );
		$this->assertSame( '', $post->onesearch_remote_post_author_gravatar );
	}

	/**
	 * Local hits are loaded with one query, not one per post.
	 */
	public function test_local_hits_are_loaded_together(): void {
		$post_ids = self::factory()->post->create_many( 5 );
		$hits     = array_map(
			static fn ( int $post_id ): array => self::get_remote_hit(
				[
					'post_id'   => $post_id,
					'post_type' => 'post',
					'site_url'  => Utils::get_current_site_url(),
				]
			),
			$post_ids
		);
		wp_cache_flush_group( 'posts' );

		$single_post_queries = 0;
		add_filter(
			'query',
			static function ( string $query ) use ( &$single_post_queries ): string {
				global $wpdb;
				if ( str_starts_with( $query, "SELECT * FROM {$wpdb->posts} WHERE ID = " ) ) {
					++$single_post_queries;
				}
				return $query;
			}
		);

		$result = $this->run_search( new Search(), [ 'hits' => $hits ] );

		$this->assertCount( 5, $result );
		$this->assertSame( 0, $single_post_queries );
	}

	/**
	 * Remote term names, descriptions and links are sanitized when core asks for them.
	 */
	public function test_remote_terms_are_sanitized(): void {
		$result = $this->run_search(
			new Search(),
			[
				'hits' => [
					self::get_remote_hit(
						[
							'taxonomies' => [
								'category' => [
									[
										'term_id'     => 22,
										'name'        => '<script>alert(1)</script>News',
										'slug'        => 'news',
										'description' => '<p>About</p><script>alert(3)</script>',
										'term_link'   => 'javascript:alert(2)',
									],
								],
							],
						]
					),
				],
			]
		);

		$terms = get_the_terms( $result[0]->ID, 'category' );

		$this->assertIsArray( $terms );
		$this->assertSame( 'News', $terms[0]->name );
		$this->assertStringNotContainsString( '<script>', term_description( $terms[0] ) );
		$this->assertStringContainsString( '<p>About</p>', term_description( $terms[0] ) );
		$this->assertSame( '', get_term_link( $terms[0] ) );
	}

	/**
	 * Dates are built from the GMT timestamp, independent of the timezone.
	 */
	public function test_remote_post_dates_are_built_from_gmt_timestamps(): void {
		update_option( 'timezone_string', 'America/New_York' );

		$result = $this->run_search( new Search(), [ 'hits' => [ self::get_remote_hit( [ 'post_date_gmt' => 1710000000 ] ) ] ] );

		$this->assertSame( '2024-03-09 16:00:00', $result[0]->post_date_gmt );
		$this->assertSame( '2024-03-09 11:00:00', $result[0]->post_date );
	}

	/**
	 * Gets a search response with two remote posts by different authors.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_two_author_response(): array {
		$hit = static fn ( int $id, string $name ): array => self::get_remote_hit(
			[
				'objectID'         => 'remote_' . $id . '_0',
				'site_post_id'     => 'remote_' . $id,
				'post_id'          => $id,
				'post_author_data' => [
					'author_id'           => 3,
					'author_display_name' => $name,
					'author_posts_url'    => 'https://remote.example.com/author/' . strtolower( $name ) . '/',
					'author_avatar'       => 'https://cdn.example.net/' . strtolower( $name ) . '.png',
				],
			]
		);

		return [ 'hits' => [ $hit( 17, 'Alice' ), $hit( 18, 'Bob' ) ] ];
	}

	/**
	 * The author of a remote post comes through the classic author filters, keyed by its unique synthetic author ID.
	 */
	public function test_remote_author_is_used_by_core_author_functions(): void {
		$search = new Search();
		$result = $this->run_search( $search, self::get_two_author_response() );

		$this->assertNotFalse( has_filter( 'get_the_author_display_name', [ $search, 'get_post_author' ] ) );
		$this->assertNotFalse( has_filter( 'author_link', [ $search, 'get_post_author_link' ] ) );
		$this->assertNotFalse( has_filter( 'get_avatar_url', [ $search, 'get_post_author_avatar' ] ) );

		$alice = (int) $result[0]->post_author;
		$bob   = (int) $result[1]->post_author;

		// Unique per remote post, never a real user.
		$this->assertLessThan( 0, $alice );
		$this->assertLessThan( 0, $bob );
		$this->assertNotSame( $alice, $bob );

		// No global post is needed to resolve them.
		$GLOBALS['post'] = self::factory()->post->create_and_get(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertSame( 'Alice', get_the_author_meta( 'display_name', $alice ) );
		$this->assertSame( 'Bob', get_the_author_meta( 'display_name', (string) $bob ) );
		$this->assertSame( 'https://remote.example.com/author/alice/', get_author_posts_url( $alice ) );
		$this->assertSame( 'https://cdn.example.net/bob.png', get_avatar_url( $bob ) );
		$this->assertSame( 'https://cdn.example.net/alice.png', get_avatar_url( $result[0] ) );
	}

	/**
	 * Real users, emails and comments are never swapped for a remote author.
	 */
	public function test_remote_author_never_replaces_real_authors(): void {
		$search = new Search();
		$result = $this->run_search( $search, self::get_two_author_response() );

		$user_id = self::factory()->user->create( [ 'display_name' => 'Real User' ] );
		$local   = self::factory()->post->create_and_get( [ 'post_author' => $user_id ] );

		// Even in the loop, with a remote global post.
		$GLOBALS['post'] = $result[0]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->set_in_the_loop( true );

		$this->assertSame( 'Real User', get_the_author_meta( 'display_name', $user_id ) );
		$this->assertSame( 'Default', $search->get_post_author( 'Default', $user_id ) );
		$this->assertSame( 'link', $search->get_post_author_link( 'link', $user_id ) );
		$this->assertSame( 'avatar', $search->get_post_author_avatar( 'avatar', $user_id ) );
		$this->assertSame( 'avatar', $search->get_post_author_avatar( 'avatar', 'a@example.com' ) );
		$this->assertSame( 'avatar', $search->get_post_author_avatar( 'avatar', new \WP_User( $user_id ) ) );
		$this->assertSame( 'avatar', $search->get_post_author_avatar( 'avatar', new \WP_Comment( (object) [ 'comment_ID' => 1 ] ) ) );
		$this->assertSame( 'avatar', $search->get_post_author_avatar( 'avatar', $local ) );

		$this->set_in_the_loop( false );
	}

	/**
	 * Core's author template tags work for a remote post set up with `setup_postdata()`.
	 */
	public function test_remote_post_sets_up_authordata_for_template_tags(): void {
		$search = new Search();
		$result = $this->run_search( $search, self::get_two_author_response() );

		$this->set_in_the_loop( true );
		setup_postdata( $result[0] );

		$link = get_the_author_posts_link();
		$this->assertStringContainsString( 'https://remote.example.com/author/alice/', $link );
		$this->assertStringContainsString( 'Alice', $link );
		$this->assertSame( 'Alice', get_the_author() );
		$this->assertSame( (int) $result[0]->post_author, $GLOBALS['authordata']->ID );

		// A local post gets a real author again.
		$user_id = self::factory()->user->create( [ 'display_name' => 'Real User' ] );
		$local   = self::factory()->post->create_and_get( [ 'post_author' => $user_id ] );
		setup_postdata( $local );
		$this->assertSame( $user_id, (int) $GLOBALS['authordata']->ID );

		$this->set_in_the_loop( false );
		wp_reset_postdata();
	}

	/**
	 * The author blocks render the remote author for remote posts.
	 */
	public function test_author_blocks_render_remote_author(): void {
		$search = new Search();
		$result = $this->run_search( $search, self::get_two_author_response() );

		$context = [ 'postId' => $result[1]->ID ];

		$name = ( new \WP_Block(
			[
				'blockName' => 'core/post-author-name',
				'attrs'     => [ 'isLink' => true ],
			],
			$context
		) )->render();

		$this->assertStringContainsString( '>Bob</a>', $name );
		$this->assertStringContainsString( 'href="https://remote.example.com/author/bob/"', $name );

		$author = ( new \WP_Block(
			[
				'blockName' => 'core/post-author',
				'attrs'     => [
					'avatarSize' => 48,
					'isLink'     => true,
				],
			],
			$context
		) )->render();

		$this->assertStringContainsString( 'Bob', $author );
		$this->assertStringContainsString( 'https://cdn.example.net/bob.png', $author );
		$this->assertStringContainsString( 'href="https://remote.example.com/author/bob/"', $author );
	}

	/**
	 * Sets whether core thinks it's inside the main loop.
	 *
	 * @param bool $in_the_loop Whether to be in the loop.
	 */
	private function set_in_the_loop( bool $in_the_loop ): void {
		global $wp_query;

		$wp_query->in_the_loop = $in_the_loop;
	}

	/**
	 * Remote terms resolve through the post's synthetic ID, with their links, including where core looks a term up by ID.
	 */
	public function test_remote_terms_resolve_through_core(): void {
		$search = new Search();
		$result = $this->run_search(
			$search,
			[
				'hits' => [
					self::get_remote_hit(
						[
							'taxonomies' => [
								'category' => [
									[
										'term_id'   => 22,
										'name'      => 'Remote News',
										'slug'      => 'remote-news',
										'parent'    => 0,
										'term_link' => 'https://remote.example.com/category/remote-news/',
									],
								],
								'post_tag' => [
									[
										'term_id'   => 23,
										'name'      => 'Tag',
										'slug'      => 'tag',
										'parent'    => 0,
										'term_link' => 'https://remote.example.com/tag/tag/',
									],
								],
							],
						]
					),
				],
			]
		);

		$terms = get_the_terms( $result[0]->ID, 'category' );

		$this->assertIsArray( $terms );
		$this->assertCount( 1, $terms );
		$this->assertSame( 'Remote News', $terms[0]->name );
		$this->assertSame( 'category', $terms[0]->taxonomy );
		$this->assertSame( 0, $terms[0]->count );
		$this->assertSame( '', $terms[0]->description );
		$this->assertSame( 'https://remote.example.com/category/remote-news/', get_term_link( $terms[0] ) );

		// `the_category()` links each category by its ID.
		$this->assertStringContainsString( 'href="https://remote.example.com/category/remote-news/"', get_the_category_list( ', ', '', $result[0]->ID ) );

		$tags = get_the_terms( $result[0]->ID, 'post_tag' );
		$this->assertIsArray( $tags );
		$this->assertSame( 'Tag', $tags[0]->name );
		$this->assertStringContainsString( 'href="https://remote.example.com/tag/tag/"', (string) get_the_tag_list( '', ', ', '', $result[0]->ID ) );

		// A taxonomy the record has no terms in doesn't fall through to the database.
		global $wpdb;
		$queries = $wpdb->num_queries;
		$this->assertFalse( get_the_terms( $result[0]->ID, 'post_format' ) );
		$this->assertSame( $queries, $wpdb->num_queries );
	}

	/**
	 * A local term keeps its own link even when a remote post has a term with the same ID.
	 */
	public function test_local_term_links_stay_local(): void {
		$local = self::factory()->category->create_and_get( [ 'slug' => 'local-news' ] );
		$this->assertInstanceOf( \WP_Term::class, $local );

		$search = new Search();
		$result = $this->run_search(
			$search,
			[
				'hits' => [
					self::get_remote_hit(
						[
							'taxonomies' => [
								'category' => [
									[
										'term_id'   => $local->term_id,
										'name'      => 'Remote News',
										'slug'      => 'local-news',
										'parent'    => 0,
										'term_link' => 'https://remote.example.com/category/local-news/',
									],
								],
							],
						]
					),
				],
			]
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- What WP::register_globals() sets for the main query.
		$GLOBALS['post'] = $result[0];

		$this->assertStringNotContainsString( 'remote.example.com', (string) get_term_link( $local ) );
		$this->assertStringNotContainsString( 'remote.example.com', get_category_link( $local->term_id ) );
	}

	/**
	 * A remote post's featured image resolves to the remote file through core's thumbnail functions.
	 */
	public function test_remote_featured_image_resolves_through_core(): void {
		$thumbnail = [
			'url'    => 'https://remote.example.com/uploads/img.png',
			'width'  => 600,
			'height' => 400,
			'sizes'  => [
				'medium' => [
					'url'    => 'https://remote.example.com/uploads/img-300x200.png',
					'width'  => 300,
					'height' => 200,
				],
			],
		];

		$search = new Search();
		$result = $this->run_search( $search, [ 'hits' => [ self::get_remote_hit( [ 'thumbnail' => $thumbnail ] ) ] ] );

		$remote_id    = $result[0]->ID;
		$thumbnail_id = get_post_thumbnail_id( $remote_id );

		$this->assertTrue( has_post_thumbnail( $remote_id ) );
		$this->assertGreaterThan( 0, $thumbnail_id );
		$this->assertSame( [ (string) $thumbnail_id ], get_post_meta( $remote_id, '_thumbnail_id', false ) );

		$attachment = get_post( $thumbnail_id );
		$this->assertInstanceOf( \WP_Post::class, $attachment );
		$this->assertSame( 'attachment', $attachment->post_type );
		$this->assertSame( 'image/png', $attachment->post_mime_type );
		$this->assertSame( $remote_id, $attachment->post_parent );
		$this->assertSame( 'https://remote.example.com/uploads/img.png', $attachment->guid );

		$this->assertSame( 'https://remote.example.com/uploads/img.png', wp_get_attachment_url( $thumbnail_id ) );
		$this->assertSame(
			[ 'https://remote.example.com/uploads/img-300x200.png', 300, 200, true ],
			wp_get_attachment_image_src( $thumbnail_id, 'medium' )
		);

		$html = get_the_post_thumbnail( $remote_id, 'medium' );
		$this->assertStringContainsString( 'https://remote.example.com/uploads/img-300x200.png', $html );
		$this->assertStringNotContainsString( 'srcset', $html );
		$this->assertStringContainsString( 'alt="Remote Post"', $html );

		// The companion attachment links back to the remote post.
		$this->assertSame( 'https://remote.example.com/posts/17/', get_attachment_link( $thumbnail_id ) );
	}

	/**
	 * No local attachment is created while searching.
	 */
	public function test_searching_does_not_write_to_the_database(): void {
		global $wpdb;

		$search = new Search();
		$this->run_search(
			$search,
			[
				'hits' => [
					self::get_remote_hit(
						[
							'post_type' => 'attachment',
							'thumbnail' => [ 'url' => 'https://remote.example.com/a.jpg' ],
						]
					),
				],
			]
		);

		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertFalse( get_option( 'onesearch_proxy_attachment_id' ) );
	}

	/**
	 * The proxy attachment that 1.1.x created is deleted.
	 */
	public function test_delete_legacy_proxy_attachment_deletes_the_proxy(): void {
		$proxy_id = self::factory()->attachment->create( [ 'post_name' => 'onesearch-remote-attachment-proxy' ] );
		update_option( 'onesearch_proxy_attachment_id', $proxy_id );

		( new Search() )->delete_legacy_proxy_attachment();

		$this->assertNull( get_post( $proxy_id ) );
		$this->assertFalse( get_option( 'onesearch_proxy_attachment_id' ) );
	}

	/**
	 * An attachment that isn't the proxy is kept, even when the option points at it.
	 */
	public function test_delete_legacy_proxy_attachment_keeps_other_attachments(): void {
		$attachment_id = self::factory()->attachment->create( [ 'post_name' => 'holiday-photo' ] );
		update_option( 'onesearch_proxy_attachment_id', $attachment_id );

		( new Search() )->delete_legacy_proxy_attachment();

		$this->assertInstanceOf( \WP_Post::class, get_post( $attachment_id ) );
		$this->assertFalse( get_option( 'onesearch_proxy_attachment_id' ) );
	}

	/**
	 * A remote attachment result is served from its own file.
	 */
	public function test_remote_attachment_results_resolve_to_their_file(): void {
		$thumbnail = [
			'url'    => 'https://remote.example.com/uploads/doc.jpeg',
			'width'  => 800,
			'height' => 600,
		];

		$search = new Search();
		$result = $this->run_search(
			$search,
			[
				'hits' => [
					self::get_remote_hit(
						[
							'post_type' => 'attachment',
							'thumbnail' => $thumbnail,
						]
					),
				],
			]
		);

		$id = $result[0]->ID;

		$this->assertSame( 'https://remote.example.com/uploads/doc.jpeg', wp_get_attachment_url( $id ) );
		$this->assertSame(
			[ 'https://remote.example.com/uploads/doc.jpeg', 800, 600, false ],
			wp_get_attachment_image_src( $id, 'full' )
		);
		$this->assertFalse( has_post_thumbnail( $id ) );
	}

	/**
	 * Returns the remote permalink for remote posts by their synthetic ID.
	 */
	public function test_get_post_type_permalink_returns_remote_for_synthetic_id(): void {
		$search = new Search();
		$result = $this->run_search( $search, [ 'hits' => [ self::get_remote_hit() ] ] );

		$id = $result[0]->ID;

		$this->assertSame( 'https://remote.example.com/posts/17/', $search->get_post_type_permalink( 'https://example.com/local/', $id ) );
		$this->assertSame( 'https://remote.example.com/posts/17/', $search->get_post_type_permalink( 'https://example.com/local/', get_post( $id ) ) );
		$this->assertSame( 'https://remote.example.com/posts/17/', get_permalink( $id ) );

		// A local post is left alone.
		$local = self::factory()->post->create_and_get();
		$this->assertSame( 'https://example.com/local/', $search->get_post_type_permalink( 'https://example.com/local/', $local ) );
	}

	/**
	 * The post blocks of a block theme render remote posts, and the title links to the remote post through the core permalink filters.
	 */
	public function test_post_blocks_render_remote_posts(): void {
		$search = new Search();
		$result = $this->run_search(
			$search,
			[
				'hits' => [
					self::get_remote_hit(
						[
							'post_excerpt' => 'Remote excerpt',
							'thumbnail'    => [ 'url' => 'https://remote.example.com/uploads/img.png' ],
						]
					),
				],
			]
		);

		// The Post Template block sets up each post before rendering its inner blocks.
		$GLOBALS['post'] = $result[0]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$render = static fn ( string $name, array $attrs ): string => ( new \WP_Block(
			[
				'blockName' => $name,
				'attrs'     => $attrs,
			],
			[ 'postId' => $result[0]->ID ]
		) )->render();

		$title = $render( 'core/post-title', [ 'isLink' => true ] );
		$this->assertStringContainsString( 'href="https://remote.example.com/posts/17/"', $title );
		$this->assertStringContainsString( '>Remote Post</a>', $title );

		$this->assertStringContainsString( 'src="https://remote.example.com/uploads/img.png"', $render( 'core/post-featured-image', [] ) );
		$this->assertStringContainsString( 'Remote excerpt', $render( 'core/post-excerpt', [] ) );
	}

	/**
	 * A remote post split into chunks gets its full content, and keeps the highlights of the chunk that matched.
	 */
	public function test_get_algolia_results_joins_chunked_remote_posts(): void {
		$this->enable_search_with_credentials();

		$matched = self::get_remote_chunk( 1, '… middle' );
		$chunks  = [ self::get_remote_chunk( 2, '… last' ), $matched, self::get_remote_chunk( 0, 'first' ) ];

		$matched['_highlightResult'] = [ 'content' => [ 'value' => '… <em>middle</em>' ] ];

		$recorded_paths = [];
		$this->mock_algolia_http_client(
			$recorded_paths,
			static function () use ( $matched, $chunks ): string {
				static $queries = 0;

				// The search, then the refetch of the post's chunks.
				return (string) wp_json_encode( [ 'hits' => 0 === $queries++ ? [ $matched ] : $chunks ] );
			}
		);

		$posts = $this->run_main_search_query();

		$this->assertCount( 1, $posts );
		$this->assertSame( 'first middle last', $posts[0]->post_content );
		$this->assertSame( [ 'content' => '… <em>middle</em>' ], $posts[0]->onesearch_algolia_highlights );
	}

	/**
	 * A remote post whose chunks are only partly fetched keeps the chunk that matched.
	 */
	public function test_get_algolia_results_keeps_matched_chunk_when_chunks_are_missing(): void {
		$this->enable_search_with_credentials();

		$matched = self::get_remote_chunk( 1, '… middle' );
		$chunks  = [ self::get_remote_chunk( 0, 'first' ), $matched ];

		$recorded_paths = [];
		$this->mock_algolia_http_client(
			$recorded_paths,
			static function () use ( $matched, $chunks ): string {
				static $queries = 0;

				// The search, then the refetch of the post's chunks, which is missing the last one.
				return (string) wp_json_encode( [ 'hits' => 0 === $queries++ ? [ $matched ] : $chunks ] );
			}
		);

		$posts = $this->run_main_search_query();

		$this->assertCount( 1, $posts );
		$this->assertSame( '… middle', $posts[0]->post_content );
	}

	/**
	 * A remote post whose chunks can't be fetched keeps the chunk that matched.
	 */
	public function test_get_algolia_results_keeps_matched_chunk_when_refetch_fails(): void {
		$this->enable_search_with_credentials();

		$matched = self::get_remote_chunk( 1, '… middle' );

		$recorded_paths = [];
		$this->mock_algolia_http_client(
			$recorded_paths,
			static function () use ( $matched ): string {
				static $queries = 0;

				// The search succeeds, then the refetch of the post's chunks gets an invalid response.
				return 0 === $queries++ ? (string) wp_json_encode( [ 'hits' => [ $matched ] ] ) : 'not json';
			}
		);

		$posts = $this->run_main_search_query();

		$this->assertCount( 1, $posts );
		$this->assertSame( '… middle', $posts[0]->post_content );
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
	 * Builds an attachment-result Search primed with a multi-size thumbnail.
	 *
	 * @param int $attachment_id The synthetic attachment ID.
	 *
	 * @return \OneSearch\Modules\Search\Search The primed Search instance.
	 */
	private function make_sized_attachment_search( int $attachment_id ): Search {
		$post                        = new \WP_Post( new \stdClass() );
		$post->ID                    = $attachment_id;
		$post->post_type             = 'attachment';
		$post->onesearch_original_id = 8;
		$post->post_title            = 'Remote <em>Image</em>';
		$post->onesearch_thumbnail   = [
			'url'    => 'https://remote.example.com/uploads/photo.jpeg',
			'width'  => 1600,
			'height' => 1200,
			'sizes'  => [
				'thumbnail' => [
					'url'    => 'https://remote.example.com/uploads/photo-150x150.jpeg',
					'width'  => 150,
					'height' => 150,
				],
				'medium'    => [
					'url'    => 'https://remote.example.com/uploads/photo-300x225.jpeg',
					'width'  => 300,
					'height' => 225,
				],
			],
		];

		$search = new Search();
		$this->set_private_property( $search, 'remote_posts_map', [ $attachment_id => $post ] );

		return $search;
	}

	/**
	 * Returns the matching named-size variant when the record carries a size map.
	 */
	public function test_get_remote_attachment_image_src_returns_named_size(): void {
		$attachment_id = 4_000_042;
		$search        = $this->make_sized_attachment_search( $attachment_id );

		$src = $search->get_remote_attachment_image_src( false, $attachment_id, 'medium', false );

		$this->assertSame(
			[ 'https://remote.example.com/uploads/photo-300x225.jpeg', 300, 225, true ],
			$src
		);
	}

	/**
	 * Falls back to the full image for the 'full' size and reports it as such.
	 */
	public function test_get_remote_attachment_image_src_returns_full_for_full_size(): void {
		$attachment_id = 4_000_042;
		$search        = $this->make_sized_attachment_search( $attachment_id );

		$src = $search->get_remote_attachment_image_src( false, $attachment_id, 'full', false );

		$this->assertSame(
			[ 'https://remote.example.com/uploads/photo.jpeg', 1600, 1200, false ],
			$src
		);
	}

	/**
	 * Picks the smallest variant that covers a requested [width, height].
	 */
	public function test_get_remote_attachment_image_src_best_fits_dimension_array(): void {
		$attachment_id = 4_000_042;
		$search        = $this->make_sized_attachment_search( $attachment_id );

		// 200x200 fits within medium (300x225) but not thumbnail (150x150).
		$src = $search->get_remote_attachment_image_src( false, $attachment_id, [ 200, 200 ], false );

		$this->assertSame(
			[ 'https://remote.example.com/uploads/photo-300x225.jpeg', 300, 225, true ],
			$src
		);
	}

	/**
	 * Uses the full image when no variant is large enough for the request.
	 */
	public function test_get_remote_attachment_image_src_uses_full_when_no_variant_fits(): void {
		$attachment_id = 4_000_042;
		$search        = $this->make_sized_attachment_search( $attachment_id );

		$src = $search->get_remote_attachment_image_src( false, $attachment_id, [ 1200, 900 ], false );

		$this->assertSame(
			[ 'https://remote.example.com/uploads/photo.jpeg', 1600, 1200, false ],
			$src
		);
	}

	/**
	 * Leaves the image data untouched for attachments that are not ours.
	 */
	public function test_get_remote_attachment_image_src_passes_through_for_local_attachment(): void {
		$search   = new Search();
		$original = [ 'https://example.com/local.jpg', 100, 100, false ];

		$this->assertSame( $original, $search->get_remote_attachment_image_src( $original, 99, 'medium', false ) );
	}

	/**
	 * Attachment result: resolves the URL to the remote file.
	 */
	public function test_get_remote_attachment_url_uses_remote_file_for_attachment_result(): void {
		$attachment_id = 4_000_042;
		$search        = $this->make_sized_attachment_search( $attachment_id );

		$url = $search->get_remote_attachment_url( 'https://example.com/local-file.jpg', $attachment_id );

		$this->assertSame( 'https://remote.example.com/uploads/photo.jpeg', $url );
	}

	/**
	 * Leaves the URL untouched for attachment IDs that are not remote.
	 */
	public function test_get_remote_attachment_url_passes_through_for_local_attachment(): void {
		$search = $this->make_sized_attachment_search( 4_000_042 );

		$url = $search->get_remote_attachment_url( 'https://example.com/local.jpg', 99 );

		$this->assertSame( 'https://example.com/local.jpg', $url );
	}

	/**
	 * Sets the remote alt text for a remote attachment.
	 */
	public function test_filter_remote_attachment_image_attributes_sets_alt(): void {
		$attachment_id = 4_000_042;
		$search        = $this->make_sized_attachment_search( $attachment_id );

		$attachment     = new \WP_Post( new \stdClass() );
		$attachment->ID = $attachment_id;

		$attr = $search->filter_remote_attachment_image_attributes( [ 'alt' => '' ], $attachment, 'medium' );

		// Markup is stripped, and core escapes the attribute itself.
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

	/**
	 * Enables Algolia search for the governing site, with credentials.
	 */
	private function enable_search_with_credentials(): void {
		$this->enable_search_for_governing_site();
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);
	}

	/**
	 * Runs a main search query through Algolia.
	 *
	 * @return \WP_Post[] The results.
	 */
	private function run_main_search_query(): array {
		$this->prime_main_search_query( 'remote test' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reading query prepared by helper.
		global $wp_query;

		return ( new Search() )->get_algolia_results( [], $wp_query ) ?? [];
	}

	/**
	 * Mocks the Algolia search response.
	 *
	 * @param array<string, mixed>                          $response The response body.
	 * @param array<int, array{path: string, body: string}> $requests Collects the requests.
	 */
	private function mock_search_response( array $response, array &$requests = [] ): void {
		$recorded_paths = [];

		$this->mock_algolia_http_client(
			$recorded_paths,
			static fn (): string => (string) wp_json_encode( $response ),
			null,
			$requests
		);
	}

	/**
	 * Runs a main search query through Algolia with the given response, then registers the hooks like WordPress would.
	 *
	 * @param \OneSearch\Modules\Search\Search              $search   The Search instance.
	 * @param array<string, mixed>                          $response The Algolia response.
	 * @param array<int, array{path: string, body: string}> $requests Collects the requests.
	 *
	 * @return \WP_Post[]|null The results.
	 */
	private function run_search( Search $search, array $response, array &$requests = [] ): ?array {
		$this->enable_search_with_credentials();
		$this->mock_search_response( $response, $requests );
		$this->prime_main_search_query( 'remote test' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reading query prepared by helper.
		global $wp_query;

		$posts = $search->get_algolia_results( null, $wp_query );

		$search->register_hooks();

		return $posts;
	}

	/**
	 * Gets a search hit of a remote post.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_remote_hit( array $overrides = [] ): array {
		return array_merge(
			[
				'objectID'          => 'remote_17_0',
				'site_post_id'      => 'remote_17',
				'post_id'           => 17,
				'post_title'        => 'Remote Post',
				'post_type'         => 'post',
				'permalink'         => 'https://remote.example.com/posts/17/',
				'site_url'          => 'https://remote.example.com/',
				'site_name'         => 'Remote',
				'content'           => 'Remote content',
				'chunk_index'       => 0,
				'total_chunks'      => 1,
				'post_date_gmt'     => 1710000000,
				'post_modified_gmt' => 1710000000,
			],
			$overrides
		);
	}

	/**
	 * Gets a chunk record of a remote post that's split into three chunks.
	 *
	 * @param int    $chunk_index The chunk index.
	 * @param string $content     The chunk content.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_remote_chunk( int $chunk_index, string $content ): array {
		return [
			'objectID'          => 'remote_17_' . $chunk_index,
			'site_post_id'      => 'remote_17',
			'post_id'           => 17,
			'post_title'        => 'Remote Post',
			'post_type'         => 'post',
			'permalink'         => 'https://remote.example.com/posts/17/',
			'site_url'          => 'https://remote.example.com/',
			'site_name'         => 'Remote',
			'content'           => $content,
			'chunk_index'       => $chunk_index,
			'total_chunks'      => 3,
			'post_date_gmt'     => 1710000000,
			'post_modified_gmt' => 1710000000,
		];
	}
}
