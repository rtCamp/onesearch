<?php
/**
 * Syncs the site's content with the OneSearch Algolia index.
 *
 * @package OneSearch\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search;

use OneSearch\Algolia\Connection;
use OneSearch\Algolia\Index;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;

/**
 * Class - Indexer
 *
 * All sites in a OneSearch network share the governing site's index, and are told apart by their records' `site_url`.
 *
 * @phpstan-import-type PostRecord from \OneSearch\Modules\Search\Post_Record
 */
final class Indexer {
	/**
	 * The error code returned when the site has no usable Algolia credentials or index.
	 */
	public const ERROR_NOT_CONFIGURED = 'onesearch_algolia_not_configured';

	/**
	 * The API key permissions needed by the operations below.
	 *
	 * `deleteIndex` also covers deleting records by filter.
	 */
	private const REQUIRED_ACL = [ 'search', 'addObject', 'deleteIndex', 'editSettings' ];

	/**
	 * The default batch size for indexing.
	 */
	private const DEFAULT_BATCH_SIZE = 100;

	/**
	 * The number of posts whose records are fetched per request, so they fit in a single page of results.
	 */
	private const POST_RECORDS_BATCH_SIZE = 20;

	/**
	 * The Algolia index, once resolved.
	 */
	private ?Index $index = null;

	/**
	 * Whether the index settings have been pushed by this instance.
	 */
	private bool $index_settings_initialized = false;

	/**
	 * Whether the credentials are valid and grant the permissions the plugin needs.
	 *
	 * @param string $app_id  The Algolia Application ID.
	 * @param string $api_key The Algolia API key.
	 */
	public static function validate_credentials( string $app_id, string $api_key ): bool {
		return ( new Connection( $app_id, $api_key ) )->has_permissions( self::REQUIRED_ACL );
	}

	/**
	 * Gets the post types this site indexes.
	 *
	 * Governing sites read them from their settings, brand sites from the governing site.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function get_indexable_post_types(): array|\WP_Error {
		if ( Settings::is_governing_site() ) {
			$entities   = Search_Settings::get_indexable_entities();
			$post_types = $entities['entities'][ Utils::normalize_url( get_site_url() ) ] ?? null;

			return is_array( $post_types ) ? array_values( array_unique( array_map( 'strval', $post_types ) ) ) : [];
		}

		$config = Governing_Data_Handler::get_brand_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return $config['indexable_entities'] ?? [];
	}

	/**
	 * Builds a filter matching records whose attribute equals any of the values.
	 *
	 * @see https://www.algolia.com/doc/guides/managing-results/refine-results/filtering/in-depth/filters-and-facetfilters/
	 *
	 * @param string   $attribute The record attribute. Must be declared in `attributesForFaceting`.
	 * @param string[] $values    The values to match.
	 */
	public static function filter_any_of( string $attribute, array $values ): string {
		return implode(
			' OR ',
			array_map(
				static fn ( string $value ): string => sprintf( '%s:"%s"', $attribute, addcslashes( $value, '"\\' ) ),
				$values
			)
		);
	}

	/**
	 * Searches the index.
	 *
	 * @param string              $query  The search query.
	 * @param array<string,mixed> $params The Algolia search params.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function search( string $query, array $params = [] ): array|\WP_Error {
		$index = $this->get_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		return $index->search( $query, $params );
	}

	/**
	 * Gets all the records of the given posts.
	 *
	 * @param string[] $site_post_ids The posts' `site_post_id`s.
	 *
	 * @return array<string, list<PostRecord>>|\WP_Error The records, grouped by `site_post_id`.
	 */
	public function get_post_records( array $site_post_ids ): array|\WP_Error {
		$index = $this->get_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$records = [];

		foreach ( array_chunk( $site_post_ids, self::POST_RECORDS_BATCH_SIZE ) as $batch ) {
			$results = $index->search(
				'',
				[
					'filters'     => self::filter_any_of( 'site_post_id', $batch ),
					'hitsPerPage' => 1000,
					'distinct'    => false,
				]
			);

			if ( is_wp_error( $results ) ) {
				return $results;
			}

			/** @var PostRecord[] $hits */
			$hits = is_array( $results['hits'] ?? null ) ? $results['hits'] : [];
			foreach ( $hits as $hit ) {
				if ( isset( $hit['site_post_id'] ) ) {
					$records[ $hit['site_post_id'] ][] = $hit;
				}
			}
		}

		return $records;
	}

	/**
	 * Replaces this site's records with its current indexable posts.
	 *
	 * Batches that fail to save are skipped, and reported in the returned error.
	 *
	 * @return true|\WP_Error
	 */
	public function reindex(): bool|\WP_Error {
		$post_types = self::get_indexable_post_types();
		if ( is_wp_error( $post_types ) ) {
			return $post_types;
		}

		$is_deleted = $this->delete_site_records( [ get_site_url() ] );
		if ( is_wp_error( $is_deleted ) ) {
			return $is_deleted;
		}

		if ( empty( $post_types ) ) {
			return true;
		}

		$errors = new \WP_Error();

		foreach ( $this->generate_post_batches( $post_types, self::DEFAULT_BATCH_SIZE ) as $records ) {
			$is_saved = $this->save_records( $records );

			if ( is_wp_error( $is_saved ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- @todo Surface this better with a Logger class.
				error_log( 'Algolia indexing error: ' . $is_saved->get_error_message() );
				$errors->merge_from( $is_saved );
			}
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Adds or updates a post's records.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return true|\WP_Error
	 */
	public function save_post( \WP_Post $post ): bool|\WP_Error {
		// @todo Prune chunks left behind when a post shrinks across a chunk boundary.
		return $this->save_records( ( new Post_Record() )->to_records( $post ) );
	}

	/**
	 * Deletes a post's records.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_post( int $post_id ): bool|\WP_Error {
		return $this->delete_by_filter(
			self::filter_any_of( 'site_post_id', [ ( new Post_Record() )->get_site_post_id( $post_id ) ] )
		);
	}

	/**
	 * Deletes all records belonging to the given sites.
	 *
	 * @param string[] $site_urls The site URLs.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_site_records( array $site_urls ): bool|\WP_Error {
		if ( empty( $site_urls ) ) {
			return true;
		}

		return $this->delete_by_filter(
			self::filter_any_of( 'site_url', array_map( [ Utils::class, 'normalize_url' ], $site_urls ) )
		);
	}

	/**
	 * Deletes the entire index, along with every site's records.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_index(): bool|\WP_Error {
		$index = $this->get_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$is_deleted = $index->delete();
		if ( true === $is_deleted ) {
			// The settings went with the index.
			$this->index_settings_initialized = false;
		}

		return $is_deleted;
	}

	/**
	 * Saves records to the index.
	 *
	 * @param PostRecord[] $records The records.
	 *
	 * @return true|\WP_Error
	 */
	private function save_records( array $records ): bool|\WP_Error {
		$index = $this->get_initialized_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		return $index->save_records( $records );
	}

	/**
	 * Deletes the records matching a filter.
	 *
	 * @param string $filter The Algolia filter.
	 *
	 * @return true|\WP_Error
	 */
	private function delete_by_filter( string $filter ): bool|\WP_Error {
		$index = $this->get_initialized_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		return $index->delete_by( [ 'filters' => $filter ] );
	}

	/**
	 * Gets the index, making sure its settings are up to date.
	 *
	 * Needed before writing, since records can only be filtered by the attributes the settings declare as facets.
	 */
	private function get_initialized_index(): Index|\WP_Error {
		$index = $this->get_index();
		if ( is_wp_error( $index ) || $this->index_settings_initialized ) {
			return $index;
		}

		$is_set = $index->set_settings( Post_Record::get_index_settings() );
		if ( is_wp_error( $is_set ) ) {
			return $is_set;
		}

		$this->index_settings_initialized = true;

		return $index;
	}

	/**
	 * Gets the index, connecting to Algolia if needed.
	 */
	private function get_index(): Index|\WP_Error {
		if ( $this->index instanceof Index ) {
			return $this->index;
		}

		$index_name = self::get_index_name();
		if ( empty( $index_name ) ) {
			return new \WP_Error(
				self::ERROR_NOT_CONFIGURED,
				__( 'Algolia index name could not be determined.', 'onesearch' )
			);
		}

		$creds = self::get_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		if ( empty( $creds['app_id'] ) || empty( $creds['write_key'] ) ) {
			return new \WP_Error(
				self::ERROR_NOT_CONFIGURED,
				__( 'Algolia admin credentials missing.', 'onesearch' )
			);
		}

		$this->index = ( new Connection( $creds['app_id'], $creds['write_key'] ) )->get_index( $index_name );

		return $this->index;
	}

	/**
	 * Gets the name of the index, which is derived from the governing site's URL.
	 *
	 * Returns an empty string if the governing site is unknown.
	 */
	private static function get_index_name(): string {
		$site_url = Settings::is_governing_site()
			? get_site_url()
			: Settings::get_parent_site_url();

		$host = ! empty( $site_url ) ? wp_parse_url( $site_url, PHP_URL_HOST ) : null;

		if ( empty( $host ) ) {
			return '';
		}

		return sprintf( 'onesearch_%s_wp_posts', sanitize_title( str_replace( '.', '_', $host ) ) );
	}

	/**
	 * Gets the Algolia credentials.
	 *
	 * Brand sites get them from the governing site.
	 *
	 * @return array{
	 *   app_id: ?string,
	 *   write_key: ?string,
	 * }|\WP_Error
	 */
	private static function get_credentials(): array|\WP_Error {
		if ( Settings::is_governing_site() ) {
			return Search_Settings::get_algolia_credentials();
		}

		$config = Governing_Data_Handler::get_brand_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return [
			'app_id'    => $config['algolia_credentials']['app_id'] ?? null,
			'write_key' => $config['algolia_credentials']['write_key'] ?? null,
		];
	}

	/**
	 * Generator that yields batches of Algolia records for indexing.
	 *
	 * We `yield` to avoid loading all posts into memory at once.
	 *
	 * @param string[] $post_types The post types to index.
	 * @param int      $batch_size The number of posts to process per batch.
	 *
	 * @return \Generator<list<PostRecord>>
	 */
	private function generate_post_batches( array $post_types, int $batch_size ): \Generator {
		$page = 1;

		while ( true ) {
			$posts = Post_Record::get_indexable_posts( $post_types, $page, $batch_size );

			if ( empty( $posts ) ) {
				break;
			}

			$records = [];
			foreach ( $posts as $post ) {
				$records = array_merge( $records, ( new Post_Record() )->to_records( $post ) );
			}
			yield $records;

			++$page;
		}
	}
}
