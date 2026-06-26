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
	 * Option key storing the ID of the shared "proxy" attachment.
	 */
	private const PROXY_ATTACHMENT_OPTION = 'onesearch_proxy_attachment_id';

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
	 * Cached ID of the proxy attachment, or null if not yet resolved.
	 */
	private ?int $proxy_attachment_id = null;

	/**
	 * Map of remote (negative) post IDs to their mocked WP_Post objects, used by
	 * the attachment hooks to resolve the remote post for the current request.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $remote_posts_map = [];

	/**
	 * The remote post whose `_thumbnail_id` was most recently requested.
	 */
	private ?\WP_Post $current_remote_post = null;

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
		add_filter( 'get_post_metadata', [ $this, 'get_remote_thumbnail_id' ], 10, 4 );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'get_remote_attachment_image_src' ], 10, 4 );
		add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filter_remote_attachment_image_attributes' ], 10, 3 );
		add_filter( 'wp_get_attachment_url', [ $this, 'get_remote_attachment_url' ], 10, 2 );

		// Keep the proxy attachment hidden from the media library.
		add_action( 'pre_get_posts', [ $this, 'exclude_proxy_from_media_library' ] );
		add_filter( 'ajax_query_attachments_args', [ $this, 'exclude_proxy_from_attachments_ajax' ] );

		// Block-theme compatibility: fix remote permalinks/excerpts in rendered blocks.
		add_filter( 'render_block', [ $this, 'filter_render_block' ], 10, 2 );
	}

	/**
	 * Whether a post is a remote (Algolia-mocked) search result.
	 *
	 * Remote results are the only posts that carry `onesearch_original_id`
	 * (local-site results return early in build_post_from_record() without it),
	 * so this identifies them regardless of whether their ID is the negative
	 * placeholder (regular posts) or the positive proxy ID (attachments).
	 *
	 * @param mixed $post The candidate post.
	 *
	 * @return bool True when the post is a remote result.
	 */
	private function is_remote_post( $post ): bool {
		return $post instanceof \WP_Post && property_exists( $post, 'onesearch_original_id' );
	}

	/**
	 * Provide the remote file URL when core asks for a remote attachment's URL.
	 *
	 * A remote attachment search result is assigned the proxy attachment's real
	 * (positive) ID, so core's wp_get_attachment_url() resolves the post and
	 * reaches this filter (unlike a negative ID, which it rejects before any
	 * filter runs). We return the remote file URL so prepend_attachment() /
	 * wp_get_attachment_link() render natively without replacing their output.
	 *
	 * @param string $url           The attachment URL.
	 * @param int    $attachment_id The attachment post ID.
	 *
	 * @return string The remote URL when this is a remote attachment, else $url.
	 */
	public function get_remote_attachment_url( $url, $attachment_id ) {
		$remote_post = $this->resolve_remote_attachment_post( (int) $attachment_id );

		if ( $remote_post instanceof \WP_Post && ! empty( $remote_post->onesearch_thumbnail['url'] ) ) {
			return esc_url_raw( $remote_post->onesearch_thumbnail['url'] );
		}

		return $url;
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

		$this->register_remote_posts( $posts_to_return );

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

		if ( ! $this->is_search_enabled() || ! $wp_query instanceof \WP_Query || ! $this->should_filter_query( $wp_query ) ) {
			return $permalink;
		}

		$remote_post = $this->resolve_remote_post_for_permalink( $post );

		if ( $remote_post instanceof \WP_Post && ! empty( $remote_post->guid ) ) {
			return $remote_post->guid;
		}

		return $permalink;
	}

	/**
	 * Locate the remote post a permalink request belongs to.
	 *
	 * Some link filters pass the WP_Post object, others only an ID. Attachment
	 * results share a single proxy ID, so an ID alone can't identify them — for
	 * those we rely on the in-loop global post. Regular remote posts have unique
	 * negative IDs and can be matched by scanning the result set.
	 *
	 * @param int|\WP_Post $post Post object or ID from the link filter.
	 *
	 * @return \WP_Post|null The matching remote post, or null when not ours.
	 */
	private function resolve_remote_post_for_permalink( $post ): ?\WP_Post {
		global $wp_query;

		if ( $post instanceof \WP_Post ) {
			return $this->is_remote_post( $post ) ? $post : null;
		}

		$post_id     = (int) $post;
		$global_post = $GLOBALS['post'] ?? null;

		// Loop context (covers proxy-ID attachments): the global post is the one
		// being rendered and matches the requested ID.
		if ( $this->is_remote_post( $global_post ) && (int) $global_post->ID === $post_id ) {
			return $global_post;
		}

		// Regular remote posts have unique negative IDs; match by scanning results.
		if ( $post_id < 0 ) {
			foreach ( $wp_query->posts as $found ) {
				if ( $this->is_remote_post( $found ) && (int) $found->ID === $post_id ) {
					return $found;
				}
			}
		}

		return null;
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

		if ( ! $this->is_remote_post( $post ) ) {
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

		if ( ! $this->is_remote_post( $post ) ) {
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

		if ( ! $this->is_remote_post( $post ) ) {
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

		if ( ! $this->is_remote_post( $post ) || ! isset( $post->onesearch_remote_taxonomies ) ) {
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

		if ( ! $this->is_search_enabled() || ! $this->is_remote_post( $post ) || empty( $post->guid ) ) {
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

		return $block_content;
	}

	/**
	 * Register the remote posts for the current request.
	 *
	 * Builds the negative-ID -> WP_Post map used by the attachment hooks. Remote
	 * posts are mocked WP_Post objects with negative IDs, so they are not in the
	 * database and cannot be resolved via get_post(); the filters that handle
	 * them read from this map (and the global $post) instead.
	 *
	 * @param \WP_Post[] $posts The posts returned for the query.
	 */
	private function register_remote_posts( array $posts ): void {
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || (int) $post->ID >= 0 ) {
				continue;
			}

			$this->remote_posts_map[ (int) $post->ID ] = $post;
		}
	}

	/**
	 * Resolve a remote post's `_thumbnail_id` to the shared proxy attachment.
	 *
	 * Remote posts carry a negative (non-existent) ID, so they have no real
	 * `_thumbnail_id`. We short-circuit the meta read to return the proxy
	 * attachment's ID, allowing WordPress' native thumbnail pipeline to run.
	 * The matching remote post is recorded so get_remote_attachment_image_src()
	 * knows which remote file the proxy should resolve to.
	 *
	 * @param mixed  $value     The pre-filtered meta value (null by default).
	 * @param int    $object_id The object ID the meta is requested for.
	 * @param string $meta_key  The meta key being requested.
	 * @param bool   $single    Whether a single value is requested.
	 *
	 * @return mixed The proxy attachment ID for remote thumbnails, else $value.
	 */
	public function get_remote_thumbnail_id( $value, $object_id, $meta_key, $single ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		if ( '_thumbnail_id' !== $meta_key || (int) $object_id >= 0 ) {
			return $value;
		}

		$remote_post = $this->remote_posts_map[ (int) $object_id ] ?? null;

		if ( ! $remote_post instanceof \WP_Post || empty( $remote_post->onesearch_thumbnail['url'] ) ) {
			return $value;
		}

		$proxy_id = $this->get_proxy_attachment_id();
		if ( ! $proxy_id ) {
			return $value;
		}

		// Record which remote file the proxy attachment should resolve to next.
		$this->current_remote_post = $remote_post;

		// get_metadata_raw() expects an array when $single is false.
		return $single ? (string) $proxy_id : [ (string) $proxy_id ];
	}

	/**
	 * Swap a remote attachment's image src for the actual remote file.
	 *
	 * Handles two cases, both keyed off the Algolia thumbnail data:
	 *  - the shared proxy attachment, assigned as a remote post's thumbnail; and
	 *  - a remote attachment search result, which is assigned the shared proxy ID
	 *    so core attachment functions resolve it natively.
	 * @param array{0: string, 1: int, 2: int, 3: bool}|false $image         Array of image data or false.
	 * @param int                                             $attachment_id Attachment post ID.
	 * @param string|int[]                                    $size          Image size.
	 * @param bool                                            $icon          Whether to use icon fallback.
	 *
	 * @return array{0: string, 1: int, 2: int, 3: bool}|false Array of image data or false.
	 */
	public function get_remote_attachment_image_src( $image, $attachment_id, $size, $icon ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		$remote_post = $this->resolve_remote_attachment_post( (int) $attachment_id );

		if ( ! $remote_post instanceof \WP_Post || empty( $remote_post->onesearch_thumbnail['url'] ) ) {
			return $image;
		}

		$thumbnail = $remote_post->onesearch_thumbnail;

		return [
			esc_url_raw( $thumbnail['url'] ),
			absint( $thumbnail['width'] ?? 0 ),
			absint( $thumbnail['height'] ?? 0 ),
			false,
		];
	}

	/**
	 * Adjust a remote attachment's image attributes for the remote file.
	 *
	 * Remote attachments (and the proxy) have no real metadata, so we set a
	 * meaningful alt text from the remote post and drop any srcset/sizes that
	 * would point at non-existent local files.
	 *
	 * @param array<string, string> $attr       Image attributes.
	 * @param \WP_Post              $attachment The attachment post.
	 * @param string|int[]          $size       Requested size.
	 *
	 * @return array<string, string> Filtered attributes.
	 */
	public function filter_remote_attachment_image_attributes( $attr, $attachment, $size ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		if ( ! $attachment instanceof \WP_Post ) {
			return $attr;
		}

		$remote_post = $this->resolve_remote_attachment_post( (int) $attachment->ID );

		if ( ! $remote_post instanceof \WP_Post ) {
			return $attr;
		}

		unset( $attr['srcset'], $attr['sizes'] );

		if ( empty( $attr['alt'] ) && ! empty( $remote_post->post_title ) ) {
			$attr['alt'] = esc_attr( $remote_post->post_title );
		}

		return $attr;
	}

	/**
	 * Resolve which remote post an attachment request belongs to.
	 *
	 * @param int $attachment_id The attachment ID being resolved.
	 *
	 * @return \WP_Post|null The matching remote post, or null if not ours.
	 */
	private function resolve_remote_attachment_post( int $attachment_id ): ?\WP_Post {
		// Only the shared proxy attachment is ever ours.
		if ( null === $this->proxy_attachment_id || $attachment_id !== $this->proxy_attachment_id ) {
			return null;
		}

		global $post;

		// Attachment-as-result path: the remote attachment that carries the proxy
		// ID is itself the post being rendered.
		if ( $post instanceof \WP_Post && 'attachment' === $post->post_type && ! empty( $post->onesearch_thumbnail['url'] ) ) {
			return $post;
		}

		// Featured-image path: the proxy stands in for a regular remote post's
		// thumbnail (recorded by get_remote_thumbnail_id()).
		return $this->current_remote_post;
	}

	/**
	 * Get the shared proxy attachment ID, creating it if necessary.
	 *
	 * A single fictitious attachment is created per site and reused for every
	 * remote post. It is recreated if the stored ID no longer points to a
	 * valid attachment (e.g. it was deleted).
	 *
	 * @return int The proxy attachment ID, or 0 on failure.
	 */
	private function get_proxy_attachment_id(): int {
		if ( null !== $this->proxy_attachment_id ) {
			return $this->proxy_attachment_id;
		}

		$stored = (int) get_option( self::PROXY_ATTACHMENT_OPTION, 0 );

		if ( $stored > 0 ) {
			$existing = get_post( $stored );
			if ( $existing instanceof \WP_Post && 'attachment' === $existing->post_type ) {
				$this->proxy_attachment_id = $stored;
				return $stored;
			}
		}

		$proxy_id = wp_insert_post(
			[
				'post_title'     => 'OneSearch Remote Attachment Proxy',
				'post_name'      => 'onesearch-remote-attachment-proxy',
				'post_status'    => 'private',
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_content'   => '',
				'post_excerpt'   => '',
			],
			true
		);

		if ( is_wp_error( $proxy_id ) || ! $proxy_id ) {
			$this->proxy_attachment_id = 0;
			return 0;
		}

		update_option( self::PROXY_ATTACHMENT_OPTION, $proxy_id, false );
		$this->proxy_attachment_id = (int) $proxy_id;

		return $this->proxy_attachment_id;
	}

	/**
	 * Exclude the proxy attachment from media library list queries.
	 *
	 * @param \WP_Query $query The query being run.
	 */
	public function exclude_proxy_from_media_library( \WP_Query $query ): void {
		if ( ! is_admin() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		$proxy_id = (int) get_option( self::PROXY_ATTACHMENT_OPTION, 0 );
		if ( ! $proxy_id ) {
			return;
		}

		$excluded   = (array) $query->get( 'post__not_in' );
		$excluded[] = $proxy_id;
		// phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts -- Intentionally filters admin media-library attachment queries (incl. the main upload.php query) to hide the single synthetic proxy attachment.
		$query->set( 'post__not_in', $excluded );
	}

	/**
	 * Exclude the proxy attachment from the media library grid (AJAX) query.
	 *
	 * @param array<string, mixed> $args The attachment query args.
	 *
	 * @return array<string, mixed> Filtered query args.
	 */
	public function exclude_proxy_from_attachments_ajax( $args ) {
		$proxy_id = (int) get_option( self::PROXY_ATTACHMENT_OPTION, 0 );
		if ( ! $proxy_id ) {
			return $args;
		}

		$excluded   = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : [];
		$excluded[] = $proxy_id;
		// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Excludes a single synthetic proxy attachment from the media grid; negligible cost.
		$args['post__not_in'] = $excluded;

		return $args;
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
		$post      = new \WP_Post( new \stdClass() );
		$post_type = $record['post_type'] ?? '';

		// Attachment results take the real (positive) proxy attachment ID so core's
		// attachment functions resolve them natively; every other remote post gets a
		// negative placeholder ID to avoid colliding with local posts. Fall back to
		// the negative ID when the proxy can't be created.
		$proxy_id = 'attachment' === $post_type ? $this->get_proxy_attachment_id() : 0;

		$post->ID                = $proxy_id > 0 ? $proxy_id : -1 - absint( $record['post_id'] );
		$post->filter            = 'raw';
		$post->guid              = $record['permalink'] ?? '';
		$post->post_content      = $record['content'] ?? '';
		$post->post_excerpt      = $record['post_excerpt'] ?? '';
		$post->post_name         = $record['post_name'] ?? '';
		$post->post_status       = 'publish';
		$post->post_title        = $record['post_title'] ?? '';
		$post->post_type         = $post_type;
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
