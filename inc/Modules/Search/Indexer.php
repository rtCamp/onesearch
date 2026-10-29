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
use OneSearch\Modules\Rest\Remote_Client;
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
	 */
	private const REQUIRED_ACL = [ 'search', 'addObject', 'deleteIndex', 'settings', 'editSettings' ];

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
			$post_types = $entities['entities'][ Utils::get_current_site_url() ] ?? null;

			return is_array( $post_types ) ? array_values( array_unique( array_map( 'strval', $post_types ) ) ) : [];
		}

		$config = Remote_Client::get_brand_config();
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
	 * Gets an error's message, along with the details Algolia gave.
	 *
	 * @param \WP_Error $error The error.
	 */
	public static function get_error_message( \WP_Error $error ): string {
		$data = $error->get_error_data();

		return trim( $error->get_error_message() . ' ' . ( is_array( $data ) && is_string( $data['message'] ?? null ) ? $data['message'] : '' ) );
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
	 * Saves the records of the given posts with one request, and deletes the chunks they had when they were longer.
	 *
	 * @param \WP_Post[] $posts The posts.
	 *
	 * @return array<int, \WP_Error>|\WP_Error The errors of the posts that couldn't be saved, keyed by post ID, or the error of a request.
	 */
	public function save_posts( array $posts ): array|\WP_Error {
		$records = [];
		$errors  = [];

		// The posts' `site_post_id`s, by how many records each has.
		$by_count = [];

		foreach ( $posts as $post ) {
			$post_record  = new Post_Record();
			$post_records = $post_record->to_records( $post );

			if ( [] === $post_records ) {
				$errors[ $post->ID ] = new \WP_Error( 'onesearch_post_too_big', __( 'The post is too big to index.', 'onesearch' ) );
				continue;
			}

			array_push( $records, ...$post_records );
			$by_count[ count( $post_records ) ][] = $post_record->get_site_post_id( $post->ID );
		}

		if ( [] !== $records ) {
			$is_saved = $this->save_records( $records );
			if ( is_wp_error( $is_saved ) ) {
				return $is_saved;
			}

			// Chunks from when a post was longer would still match searches, so delete them.
			foreach ( $by_count as $count => $site_post_ids ) {
				$is_deleted = $this->delete_by_filter(
					sprintf( '(%s) AND chunk_index >= %d', self::filter_any_of( 'site_post_id', $site_post_ids ), $count )
				);

				if ( is_wp_error( $is_deleted ) ) {
					return $is_deleted;
				}
			}
		}

		// Record which posts were successfully saved and which had errors.
		$post_ids = array_map(
			static fn ( \WP_Post $post ): int => $post->ID,
			$posts
		);

		Index_Status::record_saved( array_values( array_diff( $post_ids, array_keys( $errors ) ) ) );
		Index_Status::record_errors(
			array_map(
				static fn ( \WP_Error $error ): string => $error->get_error_message(),
				$errors
			)
		);

		return $errors;
	}

	/**
	 * Deletes the records of the given posts.
	 *
	 * @param int[] $post_ids The post IDs.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_posts( array $post_ids ): bool|\WP_Error {
		if ( empty( $post_ids ) ) {
			return true;
		}

		$is_deleted = $this->delete_by_filter(
			self::filter_any_of(
				'site_post_id',
				array_map(
					[ new Post_Record(), 'get_site_post_id' ],
					$post_ids
				)
			)
		);

		if ( is_wp_error( $is_deleted ) ) {
			return $is_deleted;
		}

		Index_Status::record_removed( $post_ids );

		return true;
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
	 * Deletes this site's records saved before the given time, e.g. the ones an indexing run didn't save again.
	 *
	 * @param int $before The Unix timestamp.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_stale_site_records( int $before ): bool|\WP_Error {
		// Set up the index first, since it may not exist yet when there was nothing to save.
		$index = $this->get_initialized_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$is_deleted = $this->delete_by_filter(
			sprintf( '%s AND indexed_at < %d', self::filter_any_of( 'site_url', [ Utils::get_current_site_url() ] ), $before )
		);

		if ( true === $is_deleted ) {
			Index_Status::delete_stale( $before );
		}

		return $is_deleted;
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
			Index_Status::clear();
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
		$index = $this->get_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		return $index->delete_by( [ 'filters' => $filter ] );
	}

	/**
	 * Gets the index, making sure its settings are up to date.
	 *
	 * Needed before saving records, since they can only be filtered by the attributes the settings declare as facets.
	 */
	private function get_initialized_index(): Index|\WP_Error {
		$index = $this->get_index();
		if ( is_wp_error( $index ) || $this->index_settings_initialized ) {
			return $index;
		}

		$settings = Post_Record::get_index_settings();

		// Check for a change in settings before pushing them to the index.
		$current = $index->get_settings();
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Algolia may normalize values, e.g. `distinct: true` to `1`.
		if ( is_wp_error( $current ) || array_intersect_key( $current, $settings ) != $settings ) {
			$is_set = $index->set_settings( $settings );
			if ( is_wp_error( $is_set ) ) {
				return $is_set;
			}
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
			? Utils::get_current_site_url()
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

		$config = Remote_Client::get_brand_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return [
			'app_id'    => $config['algolia_credentials']['app_id'] ?? null,
			'write_key' => $config['algolia_credentials']['write_key'] ?? null,
		];
	}
}
