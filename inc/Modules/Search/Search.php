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
	 * First synthetic ID handed to remote results.
	 *
	 * Core treats IDs <= 0 as "no post" and absint()s meta lookups, so remote results get unique positive IDs from a range far above any real post ID.
	 * It stays below 2^53, so the IDs survive being passed to JavaScript.
	 */
	private const REMOTE_ID_BASE = ( 2 ** 53 ) - 1_000_000;

	/**
	 * Option key storing the ID of the shared "proxy" attachment that 1.1.x and earlier created.
	 *
	 * @todo Remove when we no longer need to worry about migration.
	 */
	private const LEGACY_PROXY_ATTACHMENT_OPTION = 'onesearch_proxy_attachment_id';

	/**
	 * The indexer, once instantiated.
	 */
	private ?Indexer $indexer = null;

	/**
	 * Whether to filter the search results.
	 *
	 * @var bool|null
	 */
	private ?bool $is_search_enabled = null;

	/**
	 * The next synthetic ID to hand out.
	 */
	private int $next_remote_id = self::REMOTE_ID_BASE;

	/**
	 * The next synthetic author ID to hand out.
	 *
	 * Negative, so it can never match a real user.
	 */
	private int $next_remote_author_id = -1000;

	/**
	 * Map of synthetic author IDs to the author data of the remote post they were handed to, for the current request.
	 *
	 * @var array<int, array{display_name: string, link: string, avatar: string}>
	 */
	private array $remote_authors_map = [];

	/**
	 * Map of synthetic post IDs to their mocked WP_Post objects, for the current request.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $remote_posts_map = [];

	/**
	 * Map of the synthetic IDs of the companion attachments (featured images of remote posts) to the remote post they belong to.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $remote_attachments_map = [];

	/**
	 * Map of the synthetic IDs of the terms of remote posts to their links on the remote site.
	 *
	 * @var array<int, string>
	 */
	private array $remote_term_links_map = [];

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
		add_filter( 'get_the_author_display_name', [ $this, 'get_post_author' ], 10, 2 );
		add_filter( 'author_link', [ $this, 'get_post_author_link' ], 10, 2 );
		add_filter( 'get_avatar_url', [ $this, 'get_post_author_avatar' ], 10, 2 );
		add_action( 'the_post', [ $this, 'set_remote_authordata' ], 10, 1 );

		// Term links of remote posts.
		add_filter( 'term_link', [ $this, 'get_term_link' ], 10, 2 );

		// Featured images and attachments of remote posts.
		add_filter( 'wp_get_attachment_image_src', [ $this, 'get_remote_attachment_image_src' ], 10, 4 );
		add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filter_remote_attachment_image_attributes' ], 10, 3 );
		add_filter( 'wp_get_attachment_url', [ $this, 'get_remote_attachment_url' ], 10, 2 );

		// Nothing uses or hides the proxy attachment of 1.1.x anymore, so it would show up in the Media Library.
		add_action( 'admin_init', [ $this, 'delete_legacy_proxy_attachment' ] );
	}

	/**
	 * Deletes the shared "proxy" attachment that 1.1.x and earlier created for remote results.
	 *
	 * @todo Remove when we no longer need to worry about migration.
	 */
	public function delete_legacy_proxy_attachment(): void {
		$proxy_id = (int) get_option( self::LEGACY_PROXY_ATTACHMENT_OPTION, 0 );
		if ( $proxy_id <= 0 ) {
			return;
		}

		$proxy_post = get_post( $proxy_id );
		if ( $proxy_post instanceof \WP_Post && 'attachment' === $proxy_post->post_type && 'onesearch-remote-attachment-proxy' === $proxy_post->post_name ) {
			wp_delete_post( $proxy_id, true );
		}

		delete_option( self::LEGACY_PROXY_ATTACHMENT_OPTION );
	}

	/**
	 * Hooks onto 'posts_pre_query' to replace the search query's results with Algolia's.
	 *
	 * @param ?\WP_Post[] $posts Current posts.
	 * @param \WP_Query   $query Current query.
	 *
	 * @return ?\WP_Post[] Modified posts.
	 */
	public function get_algolia_results( $posts, $query ) {
		if ( ! $query instanceof \WP_Query || ! $this->should_filter_query( $query ) || ! $this->is_search_enabled() ) {
			return $posts;
		}

		$results = $this->execute_algolia_search( $query );

		// Algolia didn't answer, so let WordPress run its native search.
		if ( null === $results ) {
			return $posts;
		}

		/** @var PostRecord[] $records */
		$records = is_array( $results['hits'] ?? null ) ? $results['hits'] : [];

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
		$query->is_algolia_search = true;

		// WordPress reads this after `posts_pre_query`. Otherwise it caches the synthetic IDs in `post-queries`, where a native search (with Algolia off or failing) would read them back.
		$query->set( 'cache_results', false );

		$query->found_posts   = isset( $results['nbHits'] ) ? (int) $results['nbHits'] : $query->post_count;
		$query->max_num_pages = isset( $results['nbPages'] )
			? (int) $results['nbPages']
			: (int) ceil( $query->found_posts / $this->get_hits_per_page( $query ) );

		return $posts_to_return;
	}

	/**
	 * Return the correct permalink for local or remote posts.
	 *
	 * Remote results are mocked posts with synthetic IDs. This method locates the remote item and returns its GUID.
	 *
	 * @param string       $permalink Default permalink.
	 * @param int|\WP_Post $post      Post object or ID.
	 *
	 * @return string
	 */
	public function get_post_type_permalink( $permalink, $post ) {
		if ( ! $this->is_filtering_main_search() ) {
			return $permalink;
		}

		$remote_post = $this->resolve_remote_post_for_permalink( $post );

		if ( $remote_post instanceof \WP_Post && ! empty( $remote_post->guid ) ) {
			return $remote_post->guid;
		}

		return $permalink;
	}

	/**
	 * Author display name mapping for remote posts.
	 *
	 * Only substitutes for the synthetic author of a remote post.
	 *
	 * @param string    $author_name Default display name.
	 * @param int|false $user_id     The user ID the name is for.
	 *
	 * @return string
	 */
	public function get_post_author( $author_name, $user_id = 0 ) {
		$author = $this->resolve_remote_author( $user_id );

		return null !== $author ? $author['display_name'] : $author_name;
	}

	/**
	 * Author link mapping for remote posts.
	 *
	 * @param string $author_link Default author link.
	 * @param int    $author_id   The author ID.
	 *
	 * @return string
	 */
	public function get_post_author_link( $author_link, $author_id = 0 ) {
		$author = $this->resolve_remote_author( $author_id );

		return null !== $author ? $author['link'] : $author_link;
	}

	/**
	 * Author avatar URL mapping for remote posts.
	 *
	 * @param string $avatar_url  Default avatar URL.
	 * @param mixed  $id_or_email The avatar to retrieve: a user ID, email, WP_User, WP_Post or WP_Comment.
	 *
	 * @return string
	 */
	public function get_post_author_avatar( $avatar_url, $id_or_email = null ) {
		if ( $id_or_email instanceof \WP_Post ) {
			// Only the posts we mocked, since a local post's author is a real user.
			$author = $this->is_remote_post( $id_or_email ) ? $this->resolve_remote_author( $id_or_email->post_author ) : null;
		} else {
			$author = $this->resolve_remote_author( $id_or_email );
		}

		return null !== $author && '' !== $author['avatar'] ? $author['avatar'] : $avatar_url;
	}

	/**
	 * Gives the template tags that read the global `$authordata` (e.g. `get_the_author()` and `get_the_author_posts_link()`) an author for remote posts.
	 *
	 * @param mixed $post The post that was set up.
	 */
	public function set_remote_authordata( $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->is_remote_post( $post ) ) {
			return;
		}

		$author = $this->resolve_remote_author( $post->post_author );

		if ( null === $author ) {
			return;
		}

		$nicename = sanitize_title( $author['display_name'] );

		// WP_User reads its fields from `->data`, so the stand-in is built from one.
		$data                = new \stdClass();
		$data->ID            = (int) $post->post_author;
		$data->display_name  = $author['display_name'];
		$data->user_nicename = $nicename;

		$user       = new \WP_User();
		$user->data = $data;
		$user->ID   = $data->ID;

		$GLOBALS['authordata'] = $user; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's own mechanism for the current author.
	}

	/**
	 * Return the remote link of a remote post's term.
	 *
	 * @param string   $term_link Default term link.
	 * @param \WP_Term $term      The term.
	 *
	 * @return string
	 */
	public function get_term_link( $term_link, $term ) {
		if ( ! $term instanceof \WP_Term ) {
			return $term_link;
		}

		return $this->remote_term_links_map[ $term->term_id ] ?? $term_link;
	}

	/**
	 * Provide the remote file URL when core asks for a remote attachment's URL.
	 *
	 * Applies to remote attachment results, and to the companion attachments that
	 * stand in for the featured images of remote posts.
	 *
	 * @param string|false $url           The attachment URL (or false if not resolved).
	 * @param int          $attachment_id The attachment post ID.
	 *
	 * @return string|false The remote URL when this is a remote attachment, else $url.
	 */
	public function get_remote_attachment_url( $url, $attachment_id ) {
		$remote_post = $this->resolve_remote_attachment_post( (int) $attachment_id );

		if ( $remote_post instanceof \WP_Post && ! empty( $remote_post->onesearch_thumbnail['url'] ) ) {
			return esc_url_raw( $remote_post->onesearch_thumbnail['url'] );
		}

		return $url;
	}

	/**
	 * Swap a remote attachment's image src for the actual remote file.
	 *
	 * Applies to remote attachment results, and to the companion attachments that
	 * stand in for the featured images of remote posts.
	 *
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

		return $this->select_remote_image_size( $remote_post->onesearch_thumbnail, $size );
	}

	/**
	 * Adjust a remote attachment's image attributes for the remote file.
	 *
	 * Remote attachments have no real metadata, so we set a meaningful alt text from the remote post.
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

		// Core escapes the attributes after this filter.
		if ( empty( $attr['alt'] ) && ! empty( $remote_post->post_title ) ) {
			$attr['alt'] = wp_strip_all_tags( $remote_post->post_title );
		}

		return $attr;
	}

	/**
	 * Whether a post is a remote (Algolia-mocked) search result.
	 *
	 * @param mixed $post The candidate post.
	 *
	 * @return bool True when the post is a remote result.
	 */
	private function is_remote_post( $post ): bool {
		return $post instanceof \WP_Post && isset( $this->remote_posts_map[ $post->ID ] );
	}

	/**
	 * Finds the remote author a core author filter is asking about.
	 *
	 * Only the synthetic author ID of a remote post matches. Real users (positive IDs), emails, WP_User and WP_Comment arguments never do.
	 *
	 * @param mixed $author_id The user ID the filter was given.
	 *
	 * @return array{display_name: string, link: string, avatar: string}|null The author data, or null when this isn't a remote author.
	 */
	private function resolve_remote_author( $author_id ): ?array {
		if ( empty( $this->remote_authors_map ) || ! $this->is_filtering_main_search() ) {
			return null;
		}

		if ( ( is_int( $author_id ) && $author_id < 0 ) || ( is_string( $author_id ) && 1 === preg_match( '/^-\d+$/', $author_id ) ) ) {
			return $this->remote_authors_map[ (int) $author_id ] ?? null;
		}

		return null;
	}

	/**
	 * Whether the main query is a search being served by Algolia.
	 *
	 * The cheap query checks run before the search config lookup.
	 */
	private function is_filtering_main_search(): bool {
		global $wp_query;

		return $wp_query instanceof \WP_Query && $this->should_filter_query( $wp_query ) && $this->is_search_enabled();
	}

	/**
	 * Locate the remote post a permalink request belongs to.
	 *
	 * Some link filters pass the WP_Post object, others only an ID. A featured image links to the post it belongs to.
	 *
	 * @param int|\WP_Post $post Post object or ID from the link filter.
	 *
	 * @return \WP_Post|null The matching remote post, or null when not ours.
	 */
	private function resolve_remote_post_for_permalink( $post ): ?\WP_Post {
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;

		return $this->remote_posts_map[ $post_id ] ?? $this->remote_attachments_map[ $post_id ] ?? null;
	}

	/**
	 * Register the remote posts for the current request.
	 *
	 * Gives the mocked posts a place in the object cache, so core can resolve them by their synthetic ID, and
	 * creates the companion attachments that stand in for the featured images of remote posts.
	 *
	 * @param \WP_Post[] $posts The posts returned for the query.
	 */
	private function register_remote_posts( array $posts ): void {
		foreach ( $posts as $post ) {
			// Local hits are real posts. Only the mocked ones have an original ID.
			if ( ! property_exists( $post, 'onesearch_original_id' ) ) {
				continue;
			}

			$this->remote_posts_map[ $post->ID ] = $post;

			$meta = [];
			if ( 'attachment' !== $post->post_type && ! empty( $post->onesearch_thumbnail['url'] ) ) {
				$meta['_thumbnail_id'] = [ (string) $this->register_companion_attachment( $post ) ];
			}

			// Every taxonomy of the post type, since core asks for terms the record doesn't have too, e.g. for the post classes.
			$term_ids = array_fill_keys( get_object_taxonomies( $post->post_type ), [] );
			foreach ( is_array( $post->onesearch_remote_taxonomies ?? null ) ? $post->onesearch_remote_taxonomies : [] as $taxonomy => $tax_terms ) {
				$term_ids[ (string) $taxonomy ] = [];

				foreach ( is_array( $tax_terms ) ? $tax_terms : [] as $tax_term ) {
					if ( is_array( $tax_term ) ) {
						$term_ids[ (string) $taxonomy ][] = $this->register_remote_term( $tax_term, (string) $taxonomy );
					}
				}
			}

			$this->prime_remote_post_cache( $post, $meta, $term_ids );
		}
	}

	/**
	 * Creates the term that stands in for a term of a remote post.
	 *
	 * It gets a synthetic ID, since the remote ID can belong to a different local term, and core looks some terms up by ID (e.g. `get_the_category_list()`).
	 * The remote parent ID means nothing locally, so the term has no parent.
	 *
	 * @param array<string, mixed> $tax_term The term from the record.
	 * @param string               $taxonomy The term's taxonomy.
	 *
	 * @return int The term's synthetic ID.
	 */
	private function register_remote_term( array $tax_term, string $taxonomy ): int {
		$term                   = new \WP_Term( new \stdClass() );
		$term->term_id          = $this->next_remote_id++;
		$term->term_taxonomy_id = $term->term_id;
		$term->count            = (int) ( $tax_term['count'] ?? 0 );
		$term->description      = wp_kses_post( (string) ( $tax_term['description'] ?? '' ) );
		$term->name             = sanitize_text_field( (string) ( $tax_term['name'] ?? '' ) );
		$term->slug             = (string) ( $tax_term['slug'] ?? '' );
		$term->taxonomy         = $taxonomy;

		wp_cache_set( $term->term_id, $term, 'terms' );

		if ( ! empty( $tax_term['term_link'] ) ) {
			$this->remote_term_links_map[ $term->term_id ] = esc_url_raw( (string) $tax_term['term_link'] );
		}

		return $term->term_id;
	}

	/**
	 * Creates the attachment that stands in for the featured image of a remote post.
	 *
	 * @param \WP_Post $remote_post The remote post.
	 *
	 * @return int The attachment's synthetic ID.
	 */
	private function register_companion_attachment( \WP_Post $remote_post ): int {
		$url  = (string) ( $remote_post->onesearch_thumbnail['url'] ?? '' );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$mime = wp_check_filetype( is_string( $path ) ? $path : '' )['type'] ?: 'image/jpeg';

		$attachment                 = new \WP_Post( new \stdClass() );
		$attachment->ID             = $this->next_remote_id++; // @phpstan-ignore assign.propertyType (Synthetic IDs are far above what core stubs consider possible.)
		$attachment->filter         = 'raw';
		$attachment->guid           = $url;
		$attachment->post_mime_type = $mime;
		$attachment->post_parent    = $remote_post->ID;
		$attachment->post_status    = 'inherit';
		$attachment->post_title     = $remote_post->post_title;
		$attachment->post_type      = 'attachment';

		$this->prime_remote_post_cache( $attachment, [], [] );

		$this->remote_attachments_map[ $attachment->ID ] = $remote_post;

		return $attachment->ID;
	}

	/**
	 * Primes the object cache for a synthetic post, so core resolves it, its meta and its terms without querying the database.
	 *
	 * @param \WP_Post                    $post     The synthetic post.
	 * @param array<string, list<string>> $meta     The post's meta, as core caches it.
	 * @param array<string, int[]>        $term_ids The post's term IDs, by taxonomy.
	 */
	private function prime_remote_post_cache( \WP_Post $post, array $meta, array $term_ids ): void {
		wp_cache_set( $post->ID, $post, 'posts' );
		wp_cache_set( $post->ID, $meta, 'post_meta' );

		foreach ( $term_ids as $taxonomy => $ids ) {
			wp_cache_set( $post->ID, $ids, $taxonomy . '_relationships' );
		}
	}

	/**
	 * Pick the best remote image variant for a requested size.
	 *
	 * The record's top-level url/width/height are the full-size image; `sizes`
	 * holds the intermediate variants that exist on the source site. We honor the
	 * requested size — a named size (e.g. 'medium'), or a [width, height] array —
	 * and fall back to the full image. When no `sizes` map is present (records
	 * indexed before size support, or a post's featured thumbnail), the full
	 * image is returned unchanged.
	 *
	 * @param array<string, mixed> $thumbnail The record's thumbnail metadata.
	 * @param string|int[]         $size      Requested size (name or [w, h]).
	 *
	 * @return array{0: string, 1: int, 2: int, 3: bool} Image src tuple.
	 */
	private function select_remote_image_size( array $thumbnail, $size ): array {
		$full = [
			'url'    => (string) $thumbnail['url'],
			'width'  => (int) ( $thumbnail['width'] ?? 0 ),
			'height' => (int) ( $thumbnail['height'] ?? 0 ),
		];

		$sizes  = isset( $thumbnail['sizes'] ) && is_array( $thumbnail['sizes'] ) ? $thumbnail['sizes'] : [];
		$chosen = $full;

		if ( ! empty( $sizes ) ) {
			if ( is_string( $size ) && 'full' !== $size && isset( $sizes[ $size ] ) ) {
				$chosen = $sizes[ $size ];
			} elseif ( is_array( $size ) && isset( $size[0], $size[1] ) ) {
				// Dimension request: smallest variant that covers it, else largest.
				$chosen = $this->best_fit_remote_size( $sizes, $full, (int) $size[0], (int) $size[1] );
			}
			// Any other case (named 'full', or an unknown named size) keeps $full.
		}

		return [
			esc_url_raw( (string) $chosen['url'] ),
			absint( $chosen['width'] ?? 0 ),
			absint( $chosen['height'] ?? 0 ),
			$chosen['url'] !== $full['url'],
		];
	}

	/**
	 * Choose the remote image variant that best fits requested dimensions.
	 *
	 * Prefers the smallest variant whose width and height both cover the request;
	 * when none is large enough, returns the largest available variant.
	 *
	 * @param array<string, array{url: string, width: int, height: int}> $sizes  Available variants.
	 * @param array{url: string, width: int, height: int}                $full   Full-size fallback.
	 * @param int                                                        $width  Requested width.
	 * @param int                                                        $height Requested height.
	 *
	 * @return array{url: string, width: int, height: int} The chosen variant.
	 */
	private function best_fit_remote_size( array $sizes, array $full, int $width, int $height ): array {
		$candidates   = array_values( $sizes );
		$candidates[] = $full;

		// Sort ascending by width so the first covering match is the smallest.
		usort( $candidates, static fn ( $a, $b ) => (int) $a['width'] <=> (int) $b['width'] );

		foreach ( $candidates as $candidate ) {
			if ( (int) $candidate['width'] >= $width && (int) $candidate['height'] >= $height ) {
				return $candidate;
			}
		}

		// None cover the request; use the largest available.
		return end( $candidates ) ?: $full;
	}

	/**
	 * Resolve which remote post provides the file of an attachment request.
	 *
	 * @param int $attachment_id The attachment ID being resolved.
	 *
	 * @return \WP_Post|null The matching remote post, or null if not ours.
	 */
	private function resolve_remote_attachment_post( int $attachment_id ): ?\WP_Post {
		if ( isset( $this->remote_attachments_map[ $attachment_id ] ) ) {
			return $this->remote_attachments_map[ $attachment_id ];
		}

		$remote_post = $this->remote_posts_map[ $attachment_id ] ?? null;

		return $remote_post instanceof \WP_Post && 'attachment' === $remote_post->post_type ? $remote_post : null;
	}

	/**
	 * Gets the indexer, instantiating it if needed.
	 */
	private function get_indexer(): Indexer {
		if ( ! $this->indexer instanceof Indexer ) {
			$this->indexer = new Indexer();
		}

		return $this->indexer;
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

			$search_config = $all_sites[ Utils::get_current_site_url() ] ?? null;
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
	 * Execute Algolia search and return the results.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 *
	 * @return array<string,mixed>|null Algolia search results, or null when Algolia couldn't answer.
	 */
	private function execute_algolia_search( \WP_Query $query ): ?array {
		$site_urls = $this->get_searchable_site_urls();
		if ( empty( $site_urls ) ) {
			Utils::log( 'No searchable sites for the front-end search, falling back to the native search.' );
			return null;
		}

		$params = $this->prepare_search_params( $query, $site_urls );

		$results = $this->get_indexer()->search( $query->get( 's' ), $params );

		if ( is_wp_error( $results ) ) {
			Utils::log( 'Algolia search failed, falling back to the native search: ' . $results->get_error_message() );
			return null;
		}

		return $results;
	}

	/**
	 * Gets the number of results per page to ask Algolia for.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 */
	private function get_hits_per_page( \WP_Query $query ): int {
		$per_page = (int) $query->get( 'posts_per_page' );

		// -1 (and 0) mean "no limit" to WP_Query, which Algolia can't do.
		if ( $per_page <= 0 ) {
			$per_page = (int) get_option( 'posts_per_page' );
		}

		return min( 1000, $per_page > 0 ? $per_page : 10 );
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
				'hitsPerPage' => $this->get_hits_per_page( $query ),
			]
		);

		// Add the Post Type filters.
		if ( ! empty( $query->get( 'post_type' ) ) && 'any' !== $query->get( 'post_type' ) ) {
			$post_types = is_string( $query->get( 'post_type' ) ) ? [ $query->get( 'post_type' ) ] : (array) $query->get( 'post_type' );

			$default_params['filters'] = Indexer::filter_any_of( 'post_type', $post_types );
		}

		// Add Site URL filters.
		$site_urls            = array_map( [ Utils::class, 'normalize_url' ], $searchable_sites );
		$site_url_filters_str = Indexer::filter_any_of( 'site_url', $site_urls );

		$default_params['filters'] = isset( $default_params['filters'] )
			? '(' . $default_params['filters'] . ') AND (' . $site_url_filters_str . ')'
			: $site_url_filters_str;

		// Boost the results of this site, when it's one of the searched ones.
		$current_site_url = Utils::get_current_site_url();
		if ( in_array( $current_site_url, $site_urls, true ) ) {
			// Unlike `filters`, optional filters match a quoted value literally.
			$default_params['optionalFilters'] = [ 'site_url:' . $current_site_url ];
		}

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
			$selected_sites = $search_config[ Utils::get_current_site_url() ] ?? [];
			return $selected_sites['searchable_sites'] ?? [];
		}

		// Brand: intersect local selection with governing-available sites.
		$brand_config = Governing_Data_Handler::get_brand_config();

		return is_array( $brand_config ) && ! empty( $brand_config['search_settings']['searchable_sites'] ) ? $brand_config['search_settings']['searchable_sites'] : [];
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

		// The query's own cache priming is off (see `cache_results` in `get_algolia_results()`), so load the local hits in one go.
		$site_url  = Utils::get_current_site_url();
		$local_ids = [];
		foreach ( $records as $record ) {
			if ( isset( $record['post_id'], $record['site_url'] ) && Utils::normalize_url( $record['site_url'] ) === $site_url ) {
				$local_ids[] = (int) $record['post_id'];
			}
		}
		if ( [] !== $local_ids ) {
			_prime_post_caches( $local_ids );
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
	 * Gives the records of remote posts that were split into chunks their full content.
	 *
	 * Local posts are skipped, since they're hydrated from the database. If any of the chunks can't be fetched, the matched chunk is kept.
	 *
	 * @param PostRecord[] $records Algolia records.
	 * @return PostRecord[]
	 */
	private function get_all_chunks_for_records( array $records ): array {
		$site_url      = Utils::get_current_site_url();
		$site_post_ids = [];

		foreach ( $records as $record ) {
			if (
				isset( $record['site_url'], $record['site_post_id'] )
				&& Utils::normalize_url( $record['site_url'] ) !== $site_url
				&& (int) ( $record['total_chunks'] ?? 0 ) > 1
			) {
				$site_post_ids[] = $record['site_post_id'];
			}
		}

		if ( empty( $site_post_ids ) ) {
			return $records;
		}

		$post_records = $this->get_indexer()->get_post_records( array_values( array_unique( $site_post_ids ) ) );

		if ( is_wp_error( $post_records ) ) {
			Utils::log( 'Error fetching chunks from Algolia: ' . $post_records->get_error_message() );
			return $records;
		}

		foreach ( $records as $i => $record ) {
			if ( isset( $record['site_post_id'], $post_records[ $record['site_post_id'] ] ) ) {
				$records[ $i ]['content'] = Post_Record::join_content( $post_records[ $record['site_post_id'] ] ) ?? ( $record['content'] ?? '' );
			}
		}

		return $records;
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
		if ( Utils::get_current_site_url() === $site_url ) {
			$post = get_post( (int) $record['post_id'] );

			if ( ! $post instanceof \WP_Post ) {
				return null;
			}

			$post->onesearch_site_url  = $site_url;
			$post->onesearch_site_name = sanitize_text_field( $record['site_name'] ?? '' );

			return $post;
		}

		// Mock the WP_Post object.
		$post      = new \WP_Post( new \stdClass() );
		$post_type = $record['post_type'] ?? '';

		$post->ID                = $this->next_remote_id++; // @phpstan-ignore assign.propertyType (Synthetic IDs are far above what core stubs consider possible.)
		$post->filter            = 'raw';
		$post->guid              = esc_url_raw( $record['permalink'] ?? '' );
		$post->post_content      = wp_kses_post( $record['content'] ?? '' );
		$post->post_excerpt      = wp_kses_post( $record['post_excerpt'] ?? '' );
		$post->post_name         = $record['post_name'] ?? '';
		$post->post_status       = 'publish';
		$post->post_title        = wp_kses_post( $record['post_title'] ?? '' );
		$post->post_type         = $post_type; // @phpstan-ignore assign.propertyType (Partial records may not have a post_type.)
		$post->post_date_gmt     = isset( $record['post_date_gmt'] ) ? gmdate( 'Y-m-d H:i:s', (int) $record['post_date_gmt'] ) : '';
		$post->post_modified_gmt = isset( $record['post_modified_gmt'] ) ? gmdate( 'Y-m-d H:i:s', (int) $record['post_modified_gmt'] ) : '';

		// Get the post_date and modified_date from the GMT values.
		$post->post_date     = get_date_from_gmt( $post->post_date_gmt );
		$post->post_modified = get_date_from_gmt( $post->post_modified_gmt );

		// Set negative author ID to avoid conflicts.
		if ( isset( $record['post_author_data'] ) ) {
			$author_id = $this->next_remote_author_id--;

			$post->post_author                               = (string) $author_id;
			$post->onesearch_remote_post_author_display_name = sanitize_text_field( $record['post_author_data']['author_display_name'] ?? '' );
			$post->onesearch_remote_post_author_link         = esc_url_raw( $record['post_author_data']['author_posts_url'] ?? '' );
			$post->onesearch_remote_post_author_gravatar     = esc_url_raw( $record['post_author_data']['author_avatar'] ?? '' );

			$this->remote_authors_map[ $author_id ] = [
				'display_name' => $post->onesearch_remote_post_author_display_name,
				'link'         => $post->onesearch_remote_post_author_link,
				'avatar'       => $post->onesearch_remote_post_author_gravatar,
			];
		}

		// Set Custom OneSearch properties.
		$post->onesearch_original_id       = $record['post_id'];
		$post->onesearch_remote_taxonomies = $record['taxonomies'] ?? [];
		$post->onesearch_site_url          = $record['site_url'] ?? '';
		$post->onesearch_site_name         = sanitize_text_field( $record['site_name'] ?? '' );
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
