<?php
/**
 * Watcher unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Search\Post_Record;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Search\Watcher;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia as AlgoliaSDK;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Watcher class.
 */
#[CoversClass( \OneSearch\Modules\Search\Watcher::class )]
final class WatcherTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		AlgoliaSDK::resetHttpClient();

		// Reset governing site config to avoid polluting other tests.
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => '',
				'write_key' => '',
			]
		);

		$GLOBALS['wp_rest_server'] = null;
		$this->remove_added_uploads();

		parent::tearDown();
	}

	/**
	 * Ensures the class is initialized correctly
	 */
	public function test_class_instantiation(): void {
		$watcher = new Watcher();
		$watcher->register_hooks();
		// If we made it this far, we're good.
		$this->assertTrue( true );
	}

	/**
	 * A post deleted while it's saved, like by a save_post callback, isn't indexed.
	 */
	public function test_does_not_index_a_post_deleted_while_it_is_saved(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		add_action(
			'save_post',
			static function ( int $post_id ): void {
				wp_delete_post( $post_id, true );
			}
		);

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assertSame( [], $this->get_saved_records( $requests ) );
	}

	/**
	 * Skips when post type is not indexable (no entities configured).
	 */
	public function test_on_after_insert_post_skips_non_indexable_post_type(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		// Set indexable entities to only 'page', not 'post'.
		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					Utils::normalize_url( get_site_url() ) => [ 'page' ],
				],
			]
		);

		$post    = self::factory()->post->create_and_get( [ 'post_type' => 'post' ] );
		$watcher = new Watcher();

		// Should exit early without hitting Algolia since 'post' is not indexable.
		$this->publish( $watcher, $post );

		$this->assertTrue( true );
	}

	/**
	 * Skips when no indexable entities are configured at all.
	 */
	public function test_on_after_insert_post_skips_when_no_entities_configured(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );

		$post    = self::factory()->post->create_and_get();
		$watcher = new Watcher();

		// Should exit early without hitting Algolia since no indexable entities are configured.
		$this->publish( $watcher, $post );

		$this->assertTrue( true );
	}

	/**
	 * Processes indexable post types.
	 */
	public function test_on_after_insert_post_processes_indexable_post_type(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					Utils::normalize_url( get_site_url() ) => [ 'post' ],
				],
			]
		);
		// Without Algolia credentials the save fails quietly, so this only checks the post type is let through.
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		$this->publish( $watcher, $post );

		$this->assertTrue( true );
	}

	/**
	 * A post moving to a status that isn't indexed, like trash, has its records deleted, not saved.
	 */
	public function test_on_after_insert_post_does_not_reindex_disallowed_status(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

		$this->set_consumer_brand_config_cache();

		$recorded_paths = [];
		$requests       = [];
		$this->mock_algolia_http_client( $recorded_paths, null, null, $requests );

		$post = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		( new Watcher() )->register_hooks();

		wp_trash_post( $post->ID );

		$this->assertSame( [ sprintf( 'site_post_id:"%s"', ( new Post_Record() )->get_site_post_id( $post->ID ) ) ], $this->get_delete_filters( $requests ) );
		$this->assertSame( [], $this->get_saved_records( $requests ) );
	}

	/**
	 * Consumer site attempts to fetch allowed post types from brand config.
	 */
	public function test_on_after_insert_post_consumer_site_checks_brand_config(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		$post    = self::factory()->post->create_and_get();
		$watcher = new Watcher();

		// No parent URL → get_brand_config returns WP_Error → post type is not indexable.
		$this->publish( $watcher, $post );

		$this->assertTrue( true );
	}

	/**
	 * Consumer site with cached brand config recognizes indexable post types.
	 */
	public function test_on_after_insert_post_consumer_with_cached_config(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

		$this->set_consumer_brand_config_cache( 'test-app', 'test-key' );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// 'post' is in indexable_entities, so it passes the guard.
		// Algolia SDK will fail with test credentials, but the guard logic is tested.
		$this->publish( $watcher, $post );

		$this->assertTrue( true );
	}

	/**
	 * Indexable post triggers Algolia saveObjects (reindex) call.
	 * This verifies the full integration flow for a governing site.
	 */
	public function test_on_after_insert_post_triggers_algolia_reindex(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		update_option(
			Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS,
			[
				'app_id'    => 'test-app',
				'write_key' => 'test-key',
			]
		);
		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					Utils::normalize_url( get_site_url() ) => [ 'post' ],
				],
			]
		);

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// Transition from 'draft' to 'publish' (should trigger reindex).
		$this->publish( $watcher, $post );

		// Assert that a /batch (saveObjects) call was made.
		$batch_calls = array_filter( $recorded_paths, static fn ( $p ) => str_contains( $p, '/batch' ) );
		$this->assertNotEmpty( $batch_calls, 'Happy path should trigger Algolia reindex (saveObjects).' );
	}

	/**
	 * Indexable post triggers Algolia saveObjects (reindex) call on a consumer (brand) site.
	 * This verifies the full integration flow where credentials and indexable entities
	 * are resolved from the governing site's brand config cache.
	 */
	public function test_on_after_insert_post_triggers_algolia_reindex_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

		$this->set_consumer_brand_config_cache( 'test-app', 'test-key' );

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// Transition from 'draft' to 'publish' (should trigger reindex).
		$this->publish( $watcher, $post );

		// Assert that a /batch (saveObjects) call was made.
		$batch_calls = array_filter( $recorded_paths, static fn ( $p ) => str_contains( $p, '/batch' ) );
		$this->assertNotEmpty( $batch_calls, 'Consumer site happy path should trigger Algolia reindex (saveObjects).' );
	}

	/**
	 * A post leaving `publish` must have its records removed from Algolia.
	 */
	public function test_deletes_records_when_a_post_leaves_publish(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		$post_id   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$stored_id = $this->get_indexed_site_post_id( $requests );

		// Drop the publish traffic, so only the delete request is left to assert on.
		$requests = [];

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);

		$this->assertSame(
			[ sprintf( 'site_post_id:"%s"', $stored_id ) ],
			$this->get_delete_filters( $requests ),
			'Unpublishing a post must delete its records by the stored site_post_id.'
		);
	}

	/**
	 * A published post that gets a password has its records removed instead of updated.
	 */
	public function test_deletes_records_when_a_post_gets_a_password(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		$post_id   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$stored_id = $this->get_indexed_site_post_id( $requests );

		// Drop the publish traffic, so only the password change is left to assert on.
		$paths    = [];
		$requests = [];

		wp_update_post(
			[
				'ID'            => $post_id,
				'post_password' => 'secret',
			]
		);

		$this->assertSame( [ sprintf( 'site_post_id:"%s"', $stored_id ) ], $this->get_delete_filters( $requests ) );
		$this->assertSame( [], array_filter( $paths, static fn ( string $path ): bool => str_contains( $path, '/batch' ) ), 'The protected content must not be saved.' );
	}

	/**
	 * Ensures a record is purged when wp_delete_post() is used to permanently delete the post directly.
	 */
	public function test_deletes_record_when_a_post_is_permanently_deleted(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Drop the publish traffic, so only the delete request is left to assert on.
		$requests = [];

		$filters_while_the_post_still_exists = null;
		add_action(
			'before_delete_post',
			function () use ( &$requests, &$filters_while_the_post_still_exists ): void {
				$filters_while_the_post_still_exists = $this->get_delete_filters( $requests );
			},
			PHP_INT_MAX
		);

		wp_delete_post( $post_id, true );

		$this->assertSame(
			[],
			$filters_while_the_post_still_exists,
			'No records may be deleted while the post is still in the database.'
		);
		$this->assertNotEmpty(
			$this->get_delete_filters( $requests ),
			'The records still have to go once the post has been deleted.'
		);
	}

	/**
	 * Terms and a featured image saved after the post itself, as the REST API does, are in the record.
	 */
	public function test_indexes_terms_and_featured_image_saved_during_the_request(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		$category = self::factory()->category->create( [ 'name' => 'Late Category' ] );
		$image_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		add_action(
			'save_post',
			static function ( int $post_id ) use ( $category, $image_id ): void {
				wp_set_post_terms( $post_id, [ $category ], 'category' );
				set_post_thumbnail( $post_id, $image_id );
			},
			PHP_INT_MAX
		);

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$records = $this->get_saved_records( $requests );

		$this->assertNotEmpty( $records );
		$this->assertSame( 'Late Category', $records[0]['taxonomies']['category'][0]['name'] );
		$this->assertNotEmpty( $records[0]['thumbnail']['url'] );
	}

	/**
	 * A post a save_post callback updates again is indexed as it was saved last.
	 */
	public function test_indexes_a_post_updated_again_while_it_is_saved(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		$update_title = static function ( int $post_id ) use ( &$update_title ): void {
			remove_action( 'save_post', $update_title );
			wp_update_post(
				[
					'ID'         => $post_id,
					'post_title' => 'Updated Title',
				]
			);
		};
		add_action( 'save_post', $update_title );

		self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Original Title',
			]
		);

		$records = $this->get_saved_records( $requests );
		$this->assertSame( 'Updated Title', end( $records )['post_title'] );
	}

	/**
	 * Media follows the post it's attached to out of the index and back in, even when that post's type isn't indexed.
	 */
	public function test_attachments_follow_their_parent(): void {
		$this->set_up_governing_site( [ 'attachment' ] );

		$parent_id      = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$attachment_ids = [
			self::factory()->attachment->create( [ 'post_parent' => $parent_id ] ),
			self::factory()->attachment->create( [ 'post_parent' => $parent_id ] ),
		];

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		$watcher = new Watcher();
		$watcher->register_hooks();

		wp_update_post(
			[
				'ID'          => $parent_id,
				'post_status' => 'draft',
			]
		);

		$filters = implode( ' OR ', $this->get_delete_filters( $requests ) );

		foreach ( $attachment_ids as $attachment_id ) {
			$this->assertStringContainsString( sprintf( 'site_post_id:"%s"', ( new Post_Record() )->get_site_post_id( $attachment_id ) ), $filters );
		}

		$requests = [];
		wp_update_post(
			[
				'ID'          => $parent_id,
				'post_status' => 'publish',
			]
		);
		$watcher->sync_pending_media();

		$this->assertEqualsCanonicalizing( $attachment_ids, array_column( $this->get_saved_records( $requests ), 'post_id' ) );
	}

	/**
	 * Media left behind by a deleted post is indexed, now that it's unattached.
	 */
	public function test_media_of_a_deleted_post_is_indexed(): void {
		$this->set_up_governing_site( [ 'attachment' ] );

		$parent_id     = self::factory()->post->create( [ 'post_status' => 'trash' ] );
		$attachment_id = self::factory()->attachment->create( [ 'post_parent' => $parent_id ] );

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		// Read the media before the post is deleted, so its post is cached with the old parent.
		get_post( $attachment_id );

		wp_delete_post( $parent_id, true );

		$this->assertSame( [ $attachment_id ], array_column( $this->get_saved_records( $requests ), 'post_id' ) );
	}

	/**
	 * Media left behind by a deleted page moves to that page's parent, and leaves the index if that parent is private.
	 */
	public function test_media_of_a_deleted_page_follows_its_new_parent(): void {
		$this->set_up_governing_site( [ 'attachment' ] );

		$grandparent_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'private',
			]
		);
		$parent_id      = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_parent' => $grandparent_id,
			]
		);
		$attachment_id  = self::factory()->attachment->create( [ 'post_parent' => $parent_id ] );

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		wp_delete_post( $parent_id, true );

		$this->assertContains( sprintf( 'site_post_id:"%s"', ( new Post_Record() )->get_site_post_id( $attachment_id ) ), $this->get_delete_filters( $requests ) );
		$this->assertSame( [], $this->get_saved_records( $requests ) );
	}

	/**
	 * New media is saved once, with its image sizes.
	 */
	public function test_new_media_is_synced_with_its_image_sizes(): void {
		$this->set_up_governing_site( [ 'attachment' ] );

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		$watcher = new Watcher();
		$watcher->register_hooks();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/media' );
		$request->set_header( 'Content-Disposition', 'attachment; filename=canola.jpg' );
		$request->set_header( 'Content-Type', 'image/jpeg' );
		$request->set_body( (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ) ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local fixture.

		$attachment_id = rest_do_request( $request )->get_data()['id'];

		$watcher->sync_pending_media();

		$records = $this->get_saved_records( $requests );
		$this->assertCount( 1, array_filter( $paths, static fn ( string $path ): bool => str_contains( $path, '/batch' ) ) );
		$this->assertSame( $attachment_id, $records[0]['post_id'] );
		$this->assertArrayHasKey( 'medium', $records[0]['thumbnail']['sizes'] );
	}

	/**
	 * Media is saved again when image sizes are added to it later, like when the editor generates them.
	 */
	public function test_media_is_synced_when_its_image_sizes_are_added(): void {
		$this->set_up_governing_site( [ 'attachment' ] );

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		$watcher = new Watcher();
		$watcher->register_hooks();

		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );

		$watcher->sync_pending_media();

		$records = $this->get_saved_records( $requests );
		$this->assertSame( $attachment_id, $records[0]['post_id'] );
		$this->assertArrayNotHasKey( 'medium', $records[0]['thumbnail']['sizes'] ?? [] );

		$requests = [];
		wp_update_image_subsizes( $attachment_id );
		$watcher->sync_pending_media();

		$this->assertArrayHasKey( 'medium', $this->get_saved_records( $requests )[0]['thumbnail']['sizes'] );
	}

	/**
	 * Fires the Watcher the way `wp_after_insert_post` does when a draft is published.
	 *
	 * @param \OneSearch\Modules\Search\Watcher $watcher The watcher.
	 * @param \WP_Post                          $post    The published post.
	 */
	private function publish( Watcher $watcher, \WP_Post $post ): void {
		$before              = clone $post;
		$before->post_status = 'draft';

		$watcher->on_after_insert_post( $post->ID, $post, true, $before );
	}

	/**
	 * Prime the consumer brand config cache used by Watcher guards.
	 *
	 * @param string $app_id    Algolia app ID.
	 * @param string $write_key Algolia write key.
	 */
	private function set_consumer_brand_config_cache( string $app_id = 'TEST_APP', string $write_key = 'TEST_KEY' ): void {
		$cached_config = [
			'algolia_credentials' => [
				'app_id'    => $app_id,
				'write_key' => $write_key,
			],
			'search_settings'     => [
				'algolia_enabled'  => true,
				'searchable_sites' => [],
			],
			'indexable_entities'  => [ 'post' ],
			'available_sites'     => [],
		];

		$method = new \ReflectionMethod( Governing_Data_Handler::class, 'set_brand_config_cache' );
		$method->invoke( null, $cached_config );
	}

	/**
	 * Collects every record that was saved.
	 *
	 * @param array<int, array{path: string, body: string}> $requests The intercepted requests.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function get_saved_records( array $requests ): array {
		$records = [];

		foreach ( $requests as $request ) {
			if ( ! str_contains( $request['path'], '/batch' ) ) {
				continue;
			}

			foreach ( json_decode( $request['body'], true )['requests'] ?? [] as $operation ) {
				$records[] = $operation['body'];
			}
		}

		return $records;
	}

	/**
	 * Reads the `site_post_id` the records were actually written with.
	 *
	 * @param array<int, array{path: string, body: string}> $requests The intercepted requests.
	 */
	private function get_indexed_site_post_id( array $requests ): string {
		$ids = [];

		foreach ( $requests as $request ) {
			if ( ! str_contains( $request['path'], '/batch' ) ) {
				continue;
			}

			$body = json_decode( $request['body'], true );
			foreach ( $body['requests'] ?? [] as $operation ) {
				if ( isset( $operation['body']['site_post_id'] ) ) {
					$ids[] = (string) $operation['body']['site_post_id'];
				}
			}
		}

		$ids = array_values( array_unique( $ids ) );
		$this->assertCount( 1, $ids, 'Publishing should write records under exactly one site_post_id.' );

		return $ids[0];
	}

	/**
	 * Collects the `filters` argument of every deleteByQuery request that was sent.
	 *
	 * @param array<int, array{path: string, body: string}> $requests The intercepted requests.
	 *
	 * @return list<string>
	 */
	private function get_delete_filters( array $requests ): array {
		$filters = [];

		foreach ( $requests as $request ) {
			if ( ! str_contains( $request['path'], '/deleteByQuery' ) ) {
				continue;
			}

			$body = json_decode( $request['body'], true );
			if ( is_array( $body ) && isset( $body['filters'] ) ) {
				$filters[] = (string) $body['filters'];
			}
		}

		return $filters;
	}

	/**
	 * Configures the current site as a governing site with credentials and indexable entities.
	 *
	 * @param string[] $entities The indexable post types.
	 */
	private function set_up_governing_site( array $entities = [ 'post' ] ): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'test-app',
				'write_key' => 'test-key',
			]
		);
		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					Utils::get_current_site_url() => $entities,
				],
			]
		);
	}
}
