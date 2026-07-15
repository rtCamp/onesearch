<?php
/**
 * Converts a WP_Post into a search record for indexing.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Utils;

/**
 * Class - Post_Record
 *
 * @phpstan-type BaseRecordData array{
 *  site_post_id: string,
 *  is_sticky: 0|1,
 *  permalink: string,
 *  post_date_gmt: int,
 *  post_excerpt: string,
 *  post_id: int,
 *  post_modified_gmt: int,
 *  post_name: string,
 *  post_title: string,
 *  post_type: string,
 *  site_key: string,
 *  site_name: string,
 *  site_url: string,
 *  thumbnail: array{
 *    url: string,
 *    width: int,
 *    height: int,
 *    sizes?: array<string, array{url: string, width: int, height: int}>,
 *  }|array{},
 *  post_author_data?: array{
 *    author_display_name: string,
 *    author_first_name: string,
 *    author_id: int,
 *    author_last_name: string,
 *    author_login: string,
 *    author_posts_url: string,
 *    author_avatar: string,
 *  },
 *  taxonomies: array<string, array{
 *    count: int,
 *    description: string,
 *    name: string,
 *    parent: int,
 *    slug: string,
 *    term_id: int,
 *    term_link: string,
 *  }[]>,
 * }
 *
 * @phpstan-type PostRecord array{
 *  chunk_index: int,
 *  content: string,
 *  objectID: string,
 *  total_chunks: int,
 *  site_post_id: string,
 *  is_sticky: 0|1,
 *  permalink: string,
 *  post_date_gmt: int,
 *  post_excerpt: string,
 *  post_id: int,
 *  post_modified_gmt: int,
 *  post_name: string,
 *  post_title: string,
 *  post_type: string,
 *  site_key: string,
 *  site_name: string,
 *  site_url: string,
 *  thumbnail: array{
 *    url: string,
 *    width: int,
 *    height: int,
 *    sizes?: array<string, array{url: string, width: int, height: int}>,
 *  }|array{},
 *  post_author_data?: array{
 *    author_display_name: string,
 *    author_first_name: string,
 *    author_id: int,
 *    author_last_name: string,
 *    author_login: string,
 *    author_posts_url: string,
 *    author_avatar: string,
 *  },
 *  taxonomies: array<string, array{
 *    count: int,
 *    description: string,
 *    name: string,
 *    parent: int,
 *    slug: string,
 *    term_id: int,
 *    term_link: string,
 *  }[]>,
 * }
 */
final class Post_Record {
	/**
	 * The default Algolia record size limit in bytes (Algolia's max is 10KB per record).
	 *
	 * @todo make filterable via a constant or setting.
	 */
	private const DEFAULT_ALGOLIA_RECORD_LIMIT = 9000; // 10kb is getting overflowed sometimes.

	/**
	 * The (normalized) Site URL
	 *
	 * @var string
	 */
	private string $site_url;

	/**
	 * The Site Key
	 *
	 * (Derived from site URL)
	 *
	 * @var string
	 */
	private string $site_key;

	/**
	 * The Site Name
	 *
	 * @var string
	 */
	private string $site_name;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Initialization code here.
		$this->site_url  = Utils::normalize_url( get_site_url() );
		$this->site_key  = sanitize_key( $this->site_url );
		$this->site_name = get_bloginfo( 'name' );
	}

	/**
	 * Gets the index settings. These correspond to the record keys used.
	 *
	 * @return array<string, mixed> The index settings.
	 */
	public static function get_index_settings(): array {
		// Default index configuration.
		$default_settings = [
			'distinct'              => true,
			'attributeForDistinct'  => 'site_post_id',
			'attributesForFaceting' => [
				'filterOnly(site_post_id)',
				'filterOnly(site_url)',
				'filterOnly(post_type)',
				'filterOnly(post_author_data.display_name)',
			],
			'attributesToSnippet'   => [
				'post_title:20',
				'content:40',
			],
			'customRanking'         => [
				'desc(is_sticky)',
				'desc(post_date_gmt)',
				'asc(chunk_index)',
			],
			'searchableAttributes'  => [
				'unordered(post_title)',
				'unordered(content)',
			],
			'snippetEllipsisText'   => '…',
		];

		/**
		 * Modify Algolia index settings.
		 *
		 * @param array<string,mixed> $settings Default settings.
		 */
		return apply_filters( 'onesearch_algolia_index_settings', $default_settings );
	}

	/**
	 * Gets the allowed statuses for the given post types.
	 *
	 * @param string[] $post_types The post type to get allowed statuses for.
	 *
	 * @return string[] The allowed statuses for the given post types.
	 */
	public static function get_allowed_statuses( array $post_types ): array {
		$default_statuses = [ 'publish' ];

		// Media uses 'inherit' status when attached to a published post.
		if ( in_array( 'attachment', $post_types, true ) ) {
			$default_statuses[] = 'inherit';
		}

		/**
		 * Filter the statuses used for indexing.
		 *
		 * @param string[] $statuses   Statuses.
		 * @param string[] $post_types Post types.
		 */
		return apply_filters( 'onesearch_indexable_post_statuses', $default_statuses, $post_types );
	}

	/**
	 * Gets the WP_Post objects that can be indexed.
	 *
	 * @param string[] $post_types The post types to get posts for.
	 * @param int      $page       The page number.
	 * @param int      $posts_per_page The number of posts per page.
	 *
	 * @return \WP_Post[] The posts to index.
	 */
	public static function get_indexable_posts( array $post_types, int $page = 1, int $posts_per_page = -1 ): array {
		$args = [
			'post_type'              => $post_types,
			'post_status'            => self::get_allowed_statuses( $post_types ),
			'posts_per_page'         => $posts_per_page,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
		];

		// Enable pagination when batching.
		if ( $posts_per_page > 0 ) {
			$args['paged'] = $page;
		}

		$query = new \WP_Query( $args );
		/** @var \WP_Post[] $posts */
		$posts = $query->get_posts();
		return $posts;
	}

	/**
	 * Prepares a post for indexing.
	 *
	 * Splits the post data into a list of records suitable for Algolia indexing.
	 *
	 * @param \WP_Post $post The post to prepare.
	 *
	 * @return list<PostRecord> The prepared records.
	 */
	public function to_records( \WP_Post $post ): array {
		// Core data.
		$base_record = $this->get_base_record( $post );

		// Chunk the post content according to Algolia limits.
		$post_content = $this->get_cleaned_post_content( $post );
		$base_size    = strlen( wp_json_encode( $base_record ) ?: '' );

		/**
		 * Filter the Algolia record size limit.
		 *
		 * @param int $limit The record size limit in bytes. Default 9000 to allow some buffer under Algolia's 10KB limit.
		 */
		$algolia_limit  = apply_filters( 'onesearch_algolia_record_size_limit', self::DEFAULT_ALGOLIA_RECORD_LIMIT );
		$max_chunk_size = max( 0, $algolia_limit - $base_size );

		$content_chunks = $this->split_content_into_chunks( $post_content, $max_chunk_size );
		$total_chunks   = count( $content_chunks );
		$records        = [];
		foreach ( $content_chunks as $index => $chunk ) {
			$record = array_merge(
				$base_record,
				[
					'objectID'     => $this->prepare_record_object_name( (int) $post->ID, $index ),
					'content'      => $chunk,
					'chunk_index'  => $index,
					'total_chunks' => $total_chunks,
				]
			);

			/**
			 * Filters the record data for each post chunk.
			 *
			 * @param PostRecord $record       The record data for the chunk.
			 * @param \WP_Post   $post         The post being indexed.
			 * @param int        $index        The chunk index.
			 * @param int        $total_chunks The total number of chunks.
			 * @param string[]   $chunks       The `post_content` chunks.
			 */
			$records[] = apply_filters( 'onesearch_algolia_record_data', $record, $post, $index, $total_chunks, $content_chunks );
		}

		/** @var list<PostRecord> $records */
		return $records;
	}

	/**
	 * Gets the cleaned content for the post.
	 *
	 * Uses the_content filter to process blocks and other content modifications.
	 *
	 * @param \WP_Post $post The post.
	 */
	private function get_cleaned_post_content( \WP_Post $post ): string {
		$removed_filter = remove_filter( 'the_content', 'wptexturize', 10 );

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally using the_content.
			$content = (string) apply_filters( 'the_content', $post->post_content );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- @todo Surface this better with a Logger class.
			error_log(
				sprintf(
					'Algolia indexing error: Error processing post ID %d content: %s',
					$post->ID,
					$e->getMessage()
				)
			);
			return '';
		}

		// Restore filter if it was removed.
		if ( $removed_filter ) {
			add_filter( 'the_content', 'wptexturize', 10 );
		}

		// Replace encoded white spaces with regular spaces.
		$content = str_replace( [ '&nbsp;', '&#160;' ], ' ', $content );

		$regex_map = [
			// Strip out javascript.
			'@<script[^>]*?>.*?</script>@si' => '',
			// Strip out styles.
			'@<style[^>]*?>.*?</style>@siU'  => '',
			// Strip multi-line comments including CDATA.
			'@<![\s\S]*?--[ \t\n\r]*>@'      => '',
			// Strip HTML tags: code, pre.
			'@<(/?(code|pre)[^>]*)>@si'      => '',
			// Remove excessive blank lines and normalize line breaks and whitespaces.
			'/[\r\n]+/'                      => "\n",
			'/\n{2,}/'                       => "\n",
		];
		$content   = preg_replace( array_keys( $regex_map ), array_values( $regex_map ), $content );

		return null !== $content ? trim( html_entity_decode( $content, ENT_QUOTES | ENT_HTML5 ) ) : '';
	}

	/**
	 * Prepares the shared record for a WP_Post record.
	 *
	 * @param \WP_Post $post The post.
	 * @return BaseRecordData The base record data.
	 */
	private function get_base_record( \WP_Post $post ): array {
		$base_record = [
			// The unique site post ID used for distinct filtering.
			'site_post_id'      => sprintf( '%s_%d', $this->site_key, $post->ID ),
			'is_sticky'         => (int) is_sticky( $post->ID ),
			'permalink'         => get_permalink( $post ),
			'post_date_gmt'     => (int) get_post_time( 'U', true, $post ),
			'post_excerpt'      => get_the_excerpt( $post ),
			'post_id'           => $post->ID,
			'post_modified_gmt' => (int) get_post_modified_time( 'U', true, $post ),
			'post_name'         => $post->post_name,
			'post_title'        => $post->post_title,
			'post_type'         => $post->post_type,
			'site_key'          => $this->site_key,
			'site_name'         => $this->site_name,
			'site_url'          => $this->site_url,
			'thumbnail'         => $this->get_attachment_image_metadata( $post ),
		];

		if ( ! empty( $post->post_author ) ) {
			$base_record['post_author_data'] = [
				'author_display_name' => get_the_author_meta( 'display_name', (int) $post->post_author ),
				'author_first_name'   => get_the_author_meta( 'first_name', (int) $post->post_author ),
				'author_id'           => (int) $post->post_author,
				'author_last_name'    => get_the_author_meta( 'last_name', (int) $post->post_author ),
				'author_login'        => get_the_author_meta( 'user_login', (int) $post->post_author ),
				'author_posts_url'    => get_author_posts_url( (int) $post->post_author ),
				'author_avatar'       => get_avatar_url( $post->post_author ) ?: '',
			];
		}

		$base_record['taxonomies'] = $this->get_taxonomy_record_data( $post );

		/**
		 * Filters the record data used across post chunks
		 *
		 * @param BaseRecordData $record The shared record data.
		 * @param \WP_Post       $post   The post being indexed.
		 */
		return apply_filters( 'onesearch_algolia_base_record_data', $base_record, $post );
	}

	/**
	 * Prepares the taxonomy record data for a \WP_Post
	 *
	 * @param \WP_Post $post The post.
	 * @return array<string, array{
	 *   count: int,
	 *   description: string,
	 *   name: string,
	 *   parent: int,
	 *   slug: string,
	 *   term_id: int,
	 *   term_link: string,
	 * }[]>
	 */
	private function get_taxonomy_record_data( \WP_Post $post ): array {
		$taxonomies    = get_object_taxonomies( $post->post_type, 'objects' );
		$taxonomy_data = [];

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy->name );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$taxonomy_data[ $taxonomy->name ] = array_map(
				static function ( $term ) {
					$term_link = get_term_link( $term );

					return [
						'count'       => $term->count,
						'description' => $term->description,
						'name'        => $term->name,
						'parent'      => $term->parent,
						'slug'        => $term->slug,
						'term_id'     => $term->term_id,
						'term_link'   => is_wp_error( $term_link ) ? '' : (string) $term_link,
					];
				},
				$terms
			);
		}

		return array_filter( $taxonomy_data );
	}

	/**
	 * Get image metadata (full + intermediate sizes) for the indexed record.
	 *
	 * The top-level url/width/height describe the full-size image (used as the
	 * canonical file URL and as the fallback when a requested size is missing).
	 * The `sizes` map carries the intermediate sizes that actually exist on the
	 * source site, so the consuming site can render remote images at the size the
	 * theme requests rather than a single fixed thumbnail.
	 *
	 * @param \WP_Post $post The post being indexed (attachment or post with a featured image).
	 * @return array{
	 *   url: string,
	 *   width: int,
	 *   height: int,
	 *   sizes: array<string, array{url: string, width: int, height: int}>,
	 * }|array{}
	 */
	private function get_attachment_image_metadata( \WP_Post $post ): array {
		$attachment_id = 'attachment' === $post->post_type ? (int) $post->ID : get_post_thumbnail_id( $post );

		if ( ! $attachment_id ) {
			return [];
		}

		// Full-size image is the canonical URL and the fallback for unknown sizes.
		$full = \wp_get_attachment_image_src( $attachment_id, 'full' );

		if ( empty( $full ) ) {
			return [];
		}

		$metadata = [
			'url'    => $full[0],
			'width'  => (int) $full[1],
			'height' => (int) $full[2],
			'sizes'  => [],
		];

		/**
		 * Filters the intermediate image sizes indexed for remote rendering.
		 *
		 * @param string[] $sizes         Registered image size names to index.
		 * @param int      $attachment_id The attachment ID.
		 */
		$sizes = apply_filters( 'onesearch_indexed_image_sizes', [ 'thumbnail', 'medium', 'large' ], $attachment_id );

		foreach ( (array) $sizes as $size ) {
			$image_data = \wp_get_attachment_image_src( $attachment_id, $size );

			// Skip missing sizes, or ones where WP fell back to full (URL matches) — no distinct intermediate exists.
			if ( empty( $image_data ) || $image_data[0] === $metadata['url'] ) {
				continue;
			}

			$metadata['sizes'][ (string) $size ] = [
				'url'    => $image_data[0],
				'width'  => (int) $image_data[1],
				'height' => (int) $image_data[2],
			];
		}

		return $metadata;
	}

	/**
	 * Split content into chunks.
	 *
	 * Splits the content into parts, each not exceeding the max size.
	 * Cuts at word boundaries where possible, adding ellipsis for continuation.
	 *
	 * @param string $content The content to split.
	 * @param int    $max_size The maximum size of each chunk in bytes.
	 * @return string[] The chunks.
	 */
	private function split_content_into_chunks( string $content, int $max_size ): array {
		// If max size is zero or negative, return empty array.
		if ( $max_size <= 0 ) {
			return [];
		}

		$content = trim( $content );
		// If content fits within max size, return as single chunk.
		if ( mb_strlen( $content, 'UTF-8' ) <= $max_size ) {
			return [ $content ];
		}

		$chunks              = [];
		$continuation_prefix = '';
		$content_length      = mb_strlen( $content, 'UTF-8' );

		while ( $content_length > $max_size ) {
			// Find the last space within the allowed size.
			$search_start = $content_length - $max_size;
			$cut_position = mb_strrpos( $content, ' ', -$search_start, 'UTF-8' );

			// If no space found, cut at max_size (may split words).
			if ( false === $cut_position ) {
				$cut_position = $max_size;
			}

			// Add the chunk with prefix.
			$chunks[] = $continuation_prefix . mb_substr( $content, 0, $cut_position, 'UTF-8' );

			// Prepare remaining content and set prefix for next.
			$content             = trim( mb_substr( $content, $cut_position, null, 'UTF-8' ) );
			$content_length      = mb_strlen( $content, 'UTF-8' );
			$continuation_prefix = '… ';
		}

		// Add the final chunk.
		if ( ! empty( $content ) ) {
			$chunks[] = $continuation_prefix . $content;
		}

		return $chunks;
	}

	/**
	 * Prepares a record object name. In the format of: ${sanitized_site_url}_{post_id}_{chunk_index}
	 *
	 * @param int $post_id The post ID.
	 * @param int $chunk_index The chunk index.
	 */
	private function prepare_record_object_name( int $post_id, int $chunk_index ): string {
		return sprintf( '%s_%d_%d', $this->site_key, $post_id, $chunk_index );
	}
}
