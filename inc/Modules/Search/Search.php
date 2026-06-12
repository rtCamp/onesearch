<?php
/**
 * Integrates Algolia results into WordPress search.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Contracts\Interfaces\Registrable;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - Search
 *
 * @phpstan-import-type PostRecord from \OneSearch\Modules\Search\Post_Record
 */
final class Search implements Registrable {
	/**
	 * The number of items to refetch when hydrating records to posts.
	 */
	private const CHUNK_BATCH_SIZE = 20;

	/**
	 * The instance of our Index
	 *
	 * @var \OneSearch\Modules\Search\Index|null
	 */
	private ?\OneSearch\Modules\Search\Index $index = null;

	/**
	 * Whether to filter the search results.
	 *
	 * @var bool|null
	 */
	private ?bool $is_search_enabled = null;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		// Hook the results.
		add_filter( 'posts_pre_query', [ $this, 'get_algolia_results' ], 10, 2 );

		// Map permalinks and author/category/tag data for remote posts.
		add_filter( 'page_link', [ $this, 'get_post_type_permalink' ], 10, 2 );
		add_filter( 'post_link', [ $this, 'get_post_type_permalink' ], 10, 2 );
		add_filter( 'post_type_link', [ $this, 'get_post_type_permalink' ], 10, 2 );
		add_filter( 'page_type_link', [ $this, 'get_post_type_permalink' ], 10, 2 );
		add_filter( 'attachment_link', [ $this, 'get_post_type_permalink' ], 10, 2 );

		// Author data.
		add_filter( 'get_the_author_display_name', [ $this, 'get_post_author' ], 10 );
		add_filter( 'author_link', [ $this, 'get_post_author_link' ], 10 );
		add_filter( 'get_avatar_url', [ $this, 'get_post_author_avatar' ], 10 );

		// Term and taxonomy link handling for remote objects.
		add_filter( 'term_link', [ $this, 'get_term_link' ], 10, 3 );
		add_filter( 'category_link', [ $this, 'get_category_link' ], 10, 2 );
		add_filter( 'tag_link', [ $this, 'get_tag_link' ], 10, 2 );
		add_filter( 'get_the_terms', [ $this, 'get_post_terms' ], 10, 3 );
		add_filter( 'wp_get_post_terms', [ $this, 'get_post_terms' ], 10, 3 );

		// Image handling for remote posts.
		add_filter( 'wp_get_attachment_image_src', [ $this, 'get_remote_attachment_image_src' ], 10, 4 );
		add_filter( 'post_thumbnail_html', [ $this, 'get_remote_post_thumbnail_html' ], 10, 5 );
		add_filter( 'the_content', [ $this, 'replace_missing_attachment_in_content' ], 11 );

		// Block-theme compatibility: fix remote permalinks/excerpts in rendered blocks.
		add_filter( 'render_block', [ $this, 'filter_render_block' ], 10, 2 );
	}

	/**
	 * Hooks onto 'pre_get_posts' to modify the search query to use Algolia results.
	 *
	 * @param ?\WP_Post[] $posts Current posts.
	 * @param \WP_Query   $query Current query.
	 *
	 * @return ?\WP_Post[] Modified posts.
	 */
	public function get_algolia_results( $posts, $query ) {
		if ( ! $this->is_search_enabled() || ! $query instanceof \WP_Query || ! $this->should_filter_query( $query ) ) {
			return $posts;
		}

		$results = $this->execute_algolia_search( $query );
		/** @var PostRecord[] $records */
		$records = $results['hits'] ?? [];

		/**
		 * Filters whether the entire post should be reconstructed from Algolia.
		 *
		 * @param bool      $should_reconstruct Whether to reconstruct the entire post from Algolia. Default true.
		 * @param \WP_Query $query              The WP_Query instance.
		 */
		$should_reconstruct_posts = apply_filters( 'onesearch_reconstruct_chunked_on_search', true, $query );

		$posts_to_return = $this->build_posts_from_records( $records, $should_reconstruct_posts );

		$query->post_count        = count( $posts_to_return );
		$query->found_posts       = isset( $results['nbHits'] ) ? (int) $results['nbHits'] : $query->post_count;
		$query->is_algolia_search = true;

		return $posts_to_return;
	}

	/**
	 * Return the correct permalink for local or remote posts.
	 *
	 * For remote posts we store a negative ID and the original ID separately.
	 * This method locates the remote item and returns its GUID.
	 *
	 * @param string       $permalink Default permalink.
	 * @param int|\WP_Post $post      Post object or ID.
	 *
	 * @return string
	 */
	public function get_post_type_permalink( $permalink, $post ) {
		global $wp_query;
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : $post;

		if ( ! $this->is_search_enabled() || $post_id >= 0 ) {
			return $permalink;
		}

		if ( ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $permalink;
		}

		$original_post_id = absint( $post_id + 1 );
		$all_found_posts  = $wp_query->posts;

		foreach ( $all_found_posts as $post ) {
			// For remote placeholders we set onesearch_original_id.
			if ( ! $post instanceof \WP_Post || ! property_exists( $post, 'onesearch_original_id' ) || absint( $post->onesearch_original_id ) !== $original_post_id ) {
				continue;
			}

			return $post->guid;
		}

		return $permalink;
	}

	/**
	 * Author display name mapping for remote posts.
	 *
	 * @param string $author_name Default display name.
	 *
	 * @return string
	 */
	public function get_post_author( $author_name ) {
		global $wp_query, $post;

		if ( ! $this->is_search_enabled() || ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $author_name;
		}

		if ( ! isset( $post->ID ) || (int) $post->ID >= 0 ) {
			return $author_name;
		}

		return $post->onesearch_remote_post_author_display_name ?? $author_name;
	}

	/**
	 * Author link mapping for remote posts.
	 *
	 * @param string $author_link      Default author link.
	 *
	 * @return string
	 */
	public function get_post_author_link( $author_link ) {
		global $wp_query, $post;

		if ( ! $this->is_search_enabled() || ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $author_link;
		}

		if ( ! isset( $post->ID ) || (int) $post->ID >= 0 ) {
			return $author_link;
		}

		return $post->onesearch_remote_post_author_link ?? $author_link;
	}

	/**
	 * Author avatar URL mapping for remote posts.
	 *
	 * @param string $avatar_url Default avatar URL.
	 *
	 * @return string
	 */
	public function get_post_author_avatar( $avatar_url ) {
		global $wp_query, $post;

		if ( ! $this->is_search_enabled() || ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $avatar_url;
		}

		if ( ! isset( $post->ID ) || (int) $post->ID >= 0 ) {
			return $avatar_url;
		}

		return $post->onesearch_remote_post_author_gravatar ?? $avatar_url;
	}

	/**
	 * Resolve term link for remote posts using stored taxonomy metadata.
	 *
	 * @param string     $term_link Default term link.
	 * @param int|string $term Term ID or slug (as provided by WP).
	 * @param string     $taxonomy  Taxonomy name.
	 *
	 * @return string
	 */
	public function get_term_link( $term_link, $term, $taxonomy ) {
		global $wp_query, $post;

		if ( ! $this->is_search_enabled() || ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $term_link;
		}

		if ( ! isset( $post->ID ) || (int) $post->ID >= 0 || ! isset( $post->onesearch_remote_taxonomies ) ) {
			return $term_link;
		}

		foreach ( $post->onesearch_remote_taxonomies as $tax_data ) {
			if ( ! isset( $tax_data['taxonomy'], $tax_data['term_id'], $tax_data['slug'], $tax_data['term_link'] ) ) {
				continue;
			}

			if ( $tax_data['taxonomy'] !== $taxonomy ) {
				continue;
			}

			if ( $tax_data['term_id'] !== (int) $term && $tax_data['slug'] !== $term ) {
				continue;
			}

			return $tax_data['term_link'];
		}

		return $term_link;
	}

	/**
	 * Category link helper for remote posts.
	 *
	 * @param string $cat_link    Default link.
	 * @param int    $category_id Category ID.
	 *
	 * @return string
	 */
	public function get_category_link( $cat_link, $category_id ) {
		return $this->get_term_link( $cat_link, $category_id, 'category' );
	}

	/**
	 * Tag link helper for remote posts.
	 *
	 * @param string $tag_link Default link.
	 * @param int    $tag_id   Tag ID.
	 *
	 * @return string
	 */
	public function get_tag_link( $tag_link, $tag_id ) {
		return $this->get_term_link( $tag_link, $tag_id, 'post_tag' );
	}

	/**
	 * Populate terms for remote posts from stored taxonomy metadata.
	 *
	 * @param array|\WP_Term[]|false $terms    Default terms.
	 * @param int                    $post_id  Post ID (negative for remote).
	 * @param string                 $taxonomy Taxonomy.
	 *
	 * @return array|\WP_Term[]|false
	 */
	public function get_post_terms( $terms, $post_id, $taxonomy ) {
		global $wp_query;

		if ( ! $this->is_search_enabled() || ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $terms;
		}

		$post = get_post( $post_id );

		if ( ! $post || ! isset( $post->onesearch_remote_taxonomies ) ) {
			return $terms;
		}

		$filtered_terms = [];

		foreach ( $post->onesearch_remote_taxonomies as $tax_data ) {
			if ( $tax_data['taxonomy'] !== $taxonomy ) {
				continue;
			}

			$fake_term                   = new \WP_Term( new \stdClass() );
			$fake_term->count            = $tax_data['count'];
			$fake_term->description      = $tax_data['description'];
			$fake_term->name             = $tax_data['name'];
			$fake_term->parent           = $tax_data['parent'];
			$fake_term->slug             = $tax_data['slug'];
			$fake_term->taxonomy         = $tax_data['taxonomy'];
			$fake_term->term_id          = $tax_data['term_id'];
			$fake_term->term_taxonomy_id = $tax_data['term_id'];
			$fake_term->term_group       = 0;

			$filtered_terms[] = $fake_term;
		}

		return ! empty( $filtered_terms ) ? $filtered_terms : $terms;
	}

	/**
	 * Adjust block output for remote posts (block themes).
	 *
	 * Ensures that for remote posts (negative IDs) the post title block
	 * links to the remote permalink, and the excerpt block shows the
	 * Algolia-provided excerpt.
	 *
	 * @param string              $block_content Rendered block HTML.
	 * @param array<string,mixed> $block         Block data.
	 *
	 * @return string
	 */
	public function filter_render_block( $block_content, $block ) {
		global $post;

		if ( ! $this->is_search_enabled() || ! $post instanceof \WP_Post || (int) $post->ID >= 0 || empty( $post->guid ) ) {
			return $block_content;
		}

		$block_name = $block['blockName'] ?? '';

		// Fix permalink for block-based post titles.
		if ( 'core/post-title' === $block_name && false !== strpos( $block_content, 'href=' ) ) {
			$remote_url    = esc_url( $post->guid );
			$block_content = (string) preg_replace( '#href="[^"]*"#', 'href="' . $remote_url . '"', $block_content, 1 );
		}

		// Ensure excerpt block uses our remote excerpt when present.
		if ( 'core/post-excerpt' === $block_name && ! empty( $post->post_excerpt ) ) {
			if ( preg_match( '#<p[^>]*>.*?</p>#s', $block_content ) ) {
				$excerpt_html  = wpautop( wp_kses_post( $post->post_excerpt ) );
				$block_content = (string) preg_replace( '#<p[^>]*>.*?</p>#s', $excerpt_html, $block_content, 1 );
			}
		}

		// Replace featured image block for remote posts with thumbnail from Algolia.
		if ( 'core/post-featured-image' === $block_name && property_exists( $post, 'onesearch_thumbnail' ) && ! empty( $post->onesearch_thumbnail ) ) {
			$thumbnail = $post->onesearch_thumbnail;

			if ( ! empty( $thumbnail['url'] ) ) {
				$block_content = (string) preg_replace(
					'#(<img[^>]*\bsrc)="[^"]*"([^>]*>)#si',
					'$1="' . esc_url( $thumbnail['url'] ) . '"$2',
					$block_content,
					1
				);
				$block_content = (string) preg_replace(
					'#\s*(?:srcset|sizes)="[^"]*"#',
					'',
					$block_content
				);
			}
		}

		return $block_content;
	}

	/**
	 * Provide remote attachment image src for posts from other sites.
	 *
	 * When an attachment post type is from a remote site, the image data
	 * doesn't exist in the local media library. This filter serves the
	 * thumbnail URL stored in the Algolia record instead.
	 *
	 * @param array{0: string, 1: int, 2: int, 3: bool}|false $image           Array of image data or false.
	 * @param int                                             $attachment_id  Attachment post ID.
	 * @param string|int[]                                    $size           Image size.
	 * @param bool                                            $icon           Whether to use icon fallback.
	 *
	 * @return array{0: string, 1: int, 2: int, 3: bool}|false Array of image data or false.
	 */
	public function get_remote_attachment_image_src( $image, $attachment_id, $size, $icon ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		global $wp_query;

		if ( ! $this->is_search_enabled() || ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $image;
		}

		if ( $attachment_id >= 0 ) {
			return $image;
		}

		$remote_post = $this->find_remote_post_by_id( (int) $attachment_id );

		if ( ! $remote_post || ! property_exists( $remote_post, 'onesearch_thumbnail' ) || empty( $remote_post->onesearch_thumbnail ) ) {
			return $image;
		}

		$thumbnail = $remote_post->onesearch_thumbnail;

		if ( empty( $thumbnail['url'] ) ) {
			return $image;
		}

		return [
			$thumbnail['url'],
			$thumbnail['width'] ?? 0,
			$thumbnail['height'] ?? 0,
			false,
		];
	}

	/**
	 * Provide remote post thumbnail HTML for posts from other sites.
	 *
	 * When a post's featured image is from a remote site, the thumbnail
	 * data doesn't exist locally. This filter builds the <img> tag from
	 * the Algolia record's thumbnail data.
	 *
	 * @param string                       $html              The post thumbnail HTML.
	 * @param int                          $post_id           The post ID.
	 * @param int|string                   $post_thumbnail_id The thumbnail ID or empty string.
	 * @param string|int[]                 $size              Image size.
	 * @param string|array<string, string> $attr              Query string or array of attributes.
	 *
	 * @return string The post thumbnail HTML.
	 */
	public function get_remote_post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size, $attr ): string { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		global $wp_query;

		if ( ! $this->is_search_enabled() || ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $html;
		}

		if ( $post_id >= 0 ) {
			return $html;
		}

		$remote_post = $this->find_remote_post_by_id( (int) $post_id );

		if ( ! $remote_post || ! property_exists( $remote_post, 'onesearch_thumbnail' ) || empty( $remote_post->onesearch_thumbnail ) ) {
			return $html;
		}

		$thumbnail = $remote_post->onesearch_thumbnail;

		if ( empty( $thumbnail['url'] ) ) {
			return $html;
		}

		$width  = $thumbnail['width'] ?? 0;
		$height = $thumbnail['height'] ?? 0;

		$hwstring    = $width && $height ? sprintf( ' width="%d" height="%d"', $width, $height ) : '';
		$attr_string = '';

		if ( is_string( $attr ) && ! empty( $attr ) ) {
			$attr_string = ' ' . ltrim( $attr );
		}

		if ( is_array( $attr ) ) {
			foreach ( $attr as $name => $value ) {
				if ( 'class' === $name ) {
					$attr_string .= sprintf( ' class="%s"', esc_attr( $value ) );
				} else {
					$attr_string .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
				}
			}
		}

		return sprintf(
			'<img src="%s" alt="%s"%s%s />',
			esc_url( $thumbnail['url'] ),
			esc_attr( $remote_post->post_title ),
			$hwstring,
			$attr_string
		);
	}

	/**
	 * Find a remote post in the current query results by its (negative) ID.
	 *
	 * @param int $post_id The post ID (negative for remote posts).
	 *
	 * @return \WP_Post|null The remote post or null if not found.
	 */
	private function find_remote_post_by_id( int $post_id ): ?\WP_Post {
		global $wp_query, $post;

		// Check if the global post matches (common in template rendering).
		if ( $post instanceof \WP_Post && (int) $post->ID === $post_id ) {
			return $post;
		}

		if ( ! $wp_query instanceof \WP_Query || empty( $wp_query->posts ) ) {
			return null;
		}

		foreach ( $wp_query->posts as $p ) {
			if ( $p instanceof \WP_Post && (int) $p->ID === $post_id ) {
				return $p;
			}
		}

		return null;
	}

	/**
	 * Replace "Missing Attachment" text in the_content for remote attachment posts.
	 *
	 * WordPress core's prepend_attachment() filter on the_content calls
	 * wp_get_attachment_link() which returns "Missing Attachment" when
	 * get_post() fails for a negative (remote) post ID. This filter
	 * runs after prepend_attachment (priority 10) and replaces that
	 * output with the actual thumbnail from the Algolia record.
	 *
	 * @param string $content The post content.
	 *
	 * @return string The filtered content.
	 */
	public function replace_missing_attachment_in_content( string $content ): string {
		global $post, $wp_query;

		if ( ! $this->is_search_enabled() || ! $post instanceof \WP_Post || ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $content;
		}

		if ( (int) $post->ID >= 0 ) {
			return $content;
		}

		if ( 'attachment' !== $post->post_type ) {
			return $content;
		}

		if ( ! property_exists( $post, 'onesearch_thumbnail' ) || empty( $post->onesearch_thumbnail ) ) {
			return $content;
		}

		$thumbnail = $post->onesearch_thumbnail;

		if ( empty( $thumbnail['url'] ) ) {
			return $content;
		}

		$width    = $thumbnail['width'] ?? 0;
		$height   = $thumbnail['height'] ?? 0;
		$hwstring = $width && $height ? sprintf( ' width="%d" height="%d"', $width, $height ) : '';
		$img_html = sprintf(
			'<img src="%s" alt="%s"%s />',
			esc_url( $thumbnail['url'] ),
			esc_attr( $post->post_title ),
			$hwstring
		);

		$link_html = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $post->guid ),
			$img_html
		);

		$attachment_html = '<p class="attachment">' . $link_html . '</p>';

		// Replace the "Missing Attachment" paragraph added by prepend_attachment.
		$content = (string) preg_replace(
			'#<p\s+class=["\']attachment["\']\s*>.*?</p>#s',
			$attachment_html,
			$content,
			1
		);

		return $content;
	}

	/**
	 * Gets the Algolia SearchIndex from our Index class.
	 *
	 * @todo this is likely temporary until this class is refactored.
	 */
	private function get_index(): \OneSearch\Modules\Search\Index {
		if ( ! $this->index instanceof \OneSearch\Modules\Search\Index ) {
			$this->index = new \OneSearch\Modules\Search\Index();
		}

		return $this->index;
	}

	/**
	 * Whether Algolia search is enabled for the current site.
	 */
	private function is_search_enabled(): bool {
		if ( isset( $this->is_search_enabled ) ) {
			return $this->is_search_enabled;
		}

		$search_config = null;
		if ( ! Settings::is_consumer_site() ) {
			$all_sites = Search_Settings::get_search_settings();

			$search_config = $all_sites[ Utils::normalize_url( get_site_url() ) ] ?? null;
		} else {
			$brand_config  = Governing_Data_Handler::get_brand_config();
			$search_config = is_array( $brand_config ) && ! empty( $brand_config['search_settings'] ) ? $brand_config['search_settings'] : null;
		}

		$this->is_search_enabled = is_array( $search_config ) && ! empty( $search_config['algolia_enabled'] ) && ! empty( $search_config['searchable_sites'] );

		return $this->is_search_enabled;
	}

	/**
	 * Whether a WP_Query should be filtered to use Algolia results.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 */
	private function should_filter_query( \WP_Query $query ): bool {
		return $query->is_search() &&
			$query->is_main_query() &&
			! empty( $query->get( 's' ) ) &&
			! is_admin() &&
			( empty( $query->query['post_type'] ) || 'wp_template' !== $query->query['post_type'] );
	}

	/**
	 * Execute Algolia search and return the sorted hits.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return array<string,mixed> Algolia search results.
	 */
	private function execute_algolia_search( \WP_Query $query ): array {
		$site_urls = $this->get_searchable_site_urls();
		if ( empty( $site_urls ) ) {
			return [];
		}

		$params = $this->prepare_search_params( $query, $site_urls );

		$results = $this->get_index()->search( $query->get( 's' ), $params );

		if ( is_wp_error( $results ) || empty( $results['hits'] ) || ! is_array( $results['hits'] ) ) {
			return [];
		}

		$hits = $results['hits'];

		// Sort hits by Algolia ranking score descending.
		usort(
			$hits,
			function ( $a, $b ) {
				return $this->compute_algolia_score( $b ) <=> $this->compute_algolia_score( $a );
			}
		);

		$results['hits'] = $hits;

		return $results;
	}

	/**
	 * Prepare the search parameter for Algolia
	 *
	 * @param \WP_Query $query            The WP_Query instance.
	 * @param string[]  $searchable_sites List of searchable site URLs.
	 *
	 * @return array<string,mixed>
	 */
	private function prepare_search_params( \WP_Query $query, array $searchable_sites ): array {
		$current_page   = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		$default_params = array_merge(
			$this->get_default_search_params(),
			[
				'page'        => $current_page - 1,
				'hitsPerPage' => (int) $query->get( 'posts_per_page', get_option( 'posts_per_page' ) ),
			]
		);

		// Add the Post Type filters.
		if ( ! empty( $query->get( 'post_type' ) ) && 'any' !== $query->get( 'post_type' ) ) {
			$post_types        = is_string( $query->get( 'post_type' ) ) ? [ $query->get( 'post_type' ) ] : (array) $query->get( 'post_type' );
			$post_type_filters = array_map(
				static fn ( string $post_type ) => sprintf( 'post_type:"%s"', $post_type ),
				$post_types
			);

			$default_params['filters'] = implode( ' OR ', $post_type_filters );
		}

		// Add Site URL filters.
		$site_url_filters     = array_map(
			static fn ( string $site_url ) => sprintf( 'site_url:"%s"', Utils::normalize_url( $site_url ) ),
			$searchable_sites
		);
		$site_url_filters_str = implode( ' OR ', $site_url_filters );

		$default_params['filters'] = isset( $default_params['filters'] )
			? '(' . $default_params['filters'] . ') AND (' . $site_url_filters_str . ')'
			: $site_url_filters_str;

		/**
		 * Filter Algolia search parameters (facets, filters, etc.).
		 *
		 * @param array<string,mixed> $search_params Default search params.
		 * @param \WP_Query $query  Query context.
		 */
		return apply_filters( 'onesearch_algolia_search_params', $default_params, $query );
	}

	/**
	 * Get the default Algolia search params.
	 *
	 * @return array<string, mixed>
	 */
	private function get_default_search_params(): array {
		return [
			'attributesToHighlight' => [ 'post_title', 'content', 'post_excerpt' ],
			'distinct'              => true,
			'highlightPreTag'       => '<span class="algolia-highlight">',
			'highlightPostTag'      => '</span>',
			'getRankingInfo'        => true,
			'typoTolerance'         => 'min',
			'minWordSizefor1Typo'   => 3,
			'minWordSizefor2Typos'  => 6,
			'ignorePlurals'         => true,
			'removeStopWords'       => true,
			'queryType'             => 'prefixAll',
			'optionalWords'         => [ 'the', 'of', 'guide' ],
		];
	}

	/**
	 * Return the list of searchable sites for the current site.
	 *
	 * @return string[] Array of searchable site URLs.
	 */
	private function get_searchable_site_urls(): array {
		// Parent: use local data.
		if ( Settings::is_governing_site() ) {
			$search_config  = Search_Settings::get_search_settings();
			$selected_sites = $search_config[ trailingslashit( get_site_url() ) ] ?? [];
			return $selected_sites['searchable_sites'] ?? [];
		}

		// Brand: intersect local selection with governing-available sites.
		$brand_config = Governing_Data_Handler::get_brand_config();

		return is_array( $brand_config ) && ! empty( $brand_config['search_settings']['searchable_sites'] ) ? $brand_config['search_settings']['searchable_sites'] : [];
	}

	/**
	 * Build a comparable score from Algolia _rankingInfo.
	 *
	 * @param array<string,mixed> $hit Algolia hit.
	 *
	 * @return float ranking score
	 */
	private function compute_algolia_score( array $hit ): float {
		$r = $hit['_rankingInfo'] ?? [];

		// If Algolia provides rankingScore, prefer it.
		if ( isset( $r['rankingScore'] ) ) {
			return (float) $r['rankingScore'];
		}

		// Otherwise, derive a reasonable composite. Tune weights to your ranking.
		$nb_typos           = (int) ( $r['nbTypos'] ?? 0 );
		$words              = (int) ( $r['words'] ?? 0 );
		$proximity_distance = (int) ( $r['proximityDistance'] ?? 0 );
		$user_score         = (int) ( $r['userScore'] ?? 0 );
		$geo_distance       = (int) ( $r['geoDistance'] ?? 0 );

		// Higher is better. Penalize typos/proximity/geo distance.
		return ( $user_score * 1_000_000 )
		+ ( $words * 1_000 )
		- ( $nb_typos * 10_000 )
		- $proximity_distance
		- ( $geo_distance / 1000.0 );
	}

	/**
	 * Builds WP_Post objects from Algolia records.
	 *
	 * @param PostRecord[] $records             Algolia records.
	 * @param bool         $should_reconstruct  Whether to reconstruct the entire post from Algolia.
	 *
	 * @return \WP_Post[] Array of WP_Post objects.
	 */
	private function build_posts_from_records( array $records, bool $should_reconstruct ): array {
		if ( $should_reconstruct ) {
			$records = $this->get_all_chunks_for_records( $records );
		}

		$posts = [];

		foreach ( $records as $record ) {
			$post = $this->build_post_from_record( $record );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$post->onesearch_algolia_highlights = $this->extract_algolia_highlights( $record );

			$posts[] = $post;
		}

		return $posts;
	}

	/**
	 * Gets all the related chunks for the given records.
	 *
	 * @param PostRecord[] $records Algolia records.
	 * @return PostRecord[]
	 */
	private function get_all_chunks_for_records( array $records ): array {
		$records_to_return = [];
		$ids_to_fetch      = [];
		$site_url          = Utils::normalize_url( get_site_url() );

		// Go through the records and see which need chunking.
		foreach ( $records as $record ) {
			if ( ! isset( $record['site_url'], $record['site_post_id'] ) ) {
				continue;
			}

			// If the record is local, we'll use the local copy later.
			if ( Utils::normalize_url( $record['site_url'] ) === $site_url ) {
				$records_to_return[ $record['site_post_id'] ] = $record;
				continue;
			}

			// If there's no chunking, just add the record.
			if ( empty( $record['total_chunks'] ) || 1 >= (int) $record['total_chunks'] ) {
				$records_to_return[ $record['site_post_id'] ] = $record;
				continue;
			}

			// Preserve the order of the record that needs chunking.
			$records_to_return[ $record['site_post_id'] ] = null;
			$ids_to_fetch[]                               = $record['site_post_id'];
		}

		// Return early if no chunking is needed.
		$ids = array_filter( array_unique( $ids_to_fetch ) );
		if ( empty( $ids ) ) {
			return array_values( array_filter( $records_to_return ) );
		}

		// Split into chunks and fetch.
		$groups       = array_chunk( $ids, self::CHUNK_BATCH_SIZE );
		$grouped_hits = [];
		foreach ( $groups as $group ) {
			// Build the filter.
			$filters    = array_map(
				static fn ( string $id ) => sprintf( 'site_post_id:%s', $id ),
				$group
			);
			$filter_str = implode( ' OR ', $filters );

			$results = $this->get_index()->search(
				'',
				[
					'filters'     => $filter_str,
					'hitsPerPage' => 1000,
					'distinct'    => false,
				]
			);

			if ( is_wp_error( $results ) || empty( $results['hits'] ) || ! is_array( $results['hits'] ) ) {
				// Skip this group on error and continue with others.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- @todo we need visibility until we have a proper Logger.
				error_log( 'OneSearch: Error fetching chunks from Algolia: ' . ( is_wp_error( $results ) ? $results->get_error_message() : 'No hits returned' ) );
				continue;
			}

			$hits = $results['hits'];
			foreach ( $hits as $hit ) {
				if ( ! isset( $hit['site_post_id'] ) ) {
					continue;
				}
				$grouped_hits[ $hit['site_post_id'] ][] = $hit;
			}
		}

		// Merge the chunks.
		foreach ( $grouped_hits as $hits ) {
			usort(
				$hits,
				static function ( $a, $b ) {
					return ( (int) $a['chunk_index'] ) <=> ( (int) $b['chunk_index'] );
				}
			);

			$full_record  = $hits[0];
			$full_content = '';

			foreach ( $hits as $hit ) {
				$full_content .= $hit['content'] ?? '';
			}

			$full_record['content'] = $full_content;

			$records_to_return[ $full_record['site_post_id'] ] = $full_record;
		}

		return array_values( $records_to_return );
	}

	/**
	 * Mocks a WP_Post object from an Algolia record.
	 *
	 * @param array<string,mixed> $record Algolia record.
	 * @phpstan-param PostRecord $record
	 *
	 * @return ?\WP_Post Mocked WP_Post object.
	 */
	private function build_post_from_record( array $record ): ?\WP_Post {
		if ( ! isset( $record['post_id'] ) || ! isset( $record['site_url'] ) ) {
			return null;
		}

		$site_url = isset( $record['site_url'] ) ? Utils::normalize_url( $record['site_url'] ) : '';

		// If sites are local, use the local post.
		if ( Utils::normalize_url( get_site_url() ) === $site_url ) {
			$post = get_post( (int) $record['post_id'] );

			if ( ! $post instanceof \WP_Post ) {
				return null;
			}

			$post->onesearch_site_url  = $site_url;
			$post->onesearch_site_name = $record['site_name'] ?? '';

			return $post;
		}

		// Mock the WP_Post object.
		$post = new \WP_Post( new \stdClass() );
		// Ensure negative ID to avoid conflicts with local posts.
		$post->ID                = -1 - absint( $record['post_id'] );
		$post->filter            = 'raw';
		$post->guid              = $record['permalink'] ?? '';
		$post->post_content      = $record['content'] ?? '';
		$post->post_excerpt      = $record['post_excerpt'] ?? '';
		$post->post_name         = $record['post_name'] ?? '';
		$post->post_status       = 'publish';
		$post->post_title        = $record['post_title'] ?? '';
		$post->post_type         = $record['post_type'] ?? '';
		$post->post_date_gmt     = isset( $record['post_date_gmt'] ) ? (string) wp_date( 'Y-m-d H:i:s', $record['post_date_gmt'] ) : '';
		$post->post_modified_gmt = isset( $record['post_modified_gmt'] ) ? (string) wp_date( 'Y-m-d H:i:s', $record['post_modified_gmt'] ) : '';

		// Get the post_date and modified_date from the GMT values.
		$post->post_date     = get_date_from_gmt( $post->post_date_gmt );
		$post->post_modified = get_date_from_gmt( $post->post_modified_gmt );

		// Set negative author ID to avoid conflicts.
		if ( isset( $record['post_author_data'] ) ) {
			$post->post_author                               = (string) ( -1000 - absint( $record['post_author_data']['author_id'] ) );
			$post->onesearch_remote_post_author_display_name = $record['post_author_data']['author_display_name'] ?? '';
			$post->onesearch_remote_post_author_link         = $record['post_author_data']['author_posts_url'] ?? '';
			$post->onesearch_remote_post_author_gravatar     = $record['post_author_data']['author_avatar'] ?? '';
		}

		// Set Custom OneSearch properties.
		$post->onesearch_original_id       = $record['post_id'];
		$post->onesearch_remote_taxonomies = $record['taxonomies'] ?? [];
		$post->onesearch_site_url          = $record['site_url'] ?? '';
		$post->onesearch_site_name         = $record['site_name'] ?? '';
		$post->onesearch_thumbnail         = $record['thumbnail'] ?? [];

		return $post;
	}

	/**
	 * Extract highlighting data from Algolia response.
	 *
	 * @param array<string,mixed> $record Algolia search hit.
	 *
	 * @return array<string,string> Map of field names to highlighted values.
	 */
	private function extract_algolia_highlights( array $record ): array {
		$highlights = [];

		$highlight_result = $record['_highlightResult'] ?? [];

		foreach ( $highlight_result as $field => $highlight_data ) {
			if ( ! isset( $highlight_data['value'] ) ) {
				continue;
			}

			$highlights[ $field ] = $highlight_data['value'];
		}

		$snippet_result = $record['_snippetResult'] ?? [];
		foreach ( $snippet_result as $field => $snippet_data ) {
			if ( ! isset( $snippet_data['value'] ) || ! empty( $highlights[ $field ] ) ) {
				continue;
			}

			$highlights[ $field ] = $snippet_data['value'];
		}

		return $highlights;
	}
}
