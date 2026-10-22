<?php
/**
 * Watcher unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Jobs\Progress;
use OneSearch\Modules\Rest\Indexer_Controller;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Post_Record;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Search\Watcher;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
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
		$this->run_jobs();

		$this->assertSame( [], $this->get_saved_records( $requests ) );
	}

	/**
	 * Changes are synced in the background, with the post as it was saved last.
	 */
	public function test_changes_are_synced_in_the_background(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'First Title',
			]
		);
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Second Title',
			]
		);

		$this->assertSame( [], $paths, 'Nothing is sent to Algolia until the job runs.' );

		$this->run_jobs();

		$this->assertSame( [ 'Second Title' ], array_unique( array_column( $this->get_saved_records( $requests ), 'post_title' ) ) );
	}

	/**
	 * A brand site that can't get its post types from the governing site fails the sync, rather than deleting the post's records.
	 */
	public function test_brand_site_sync_fails_without_its_config(): void {
		$this->set_up_brand_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// The cached config expires, and the governing site misses the job's first request.
		delete_transient( Remote_Client::TRANSIENT_KEY );
		$governing = (object) [ 'is_down' => true ];
		add_filter(
			'pre_http_request',
			static function () use ( $governing ) {
				if ( $governing->is_down ) {
					$governing->is_down = false;
					return new \WP_Error( 'http_request_failed', 'cURL error 28' );
				}

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => wp_json_encode(
						[
							'algolia_credentials' => [
								'app_id'    => 'test-app',
								'write_key' => 'test-key',
							],
							'indexable_entities'  => [ 'post' ],
						]
					),
				];
			}
		);

		$this->run_jobs();

		$this->assertSame( [], $this->get_delete_filters( $requests ) );
		$this->assertSame( [], $this->get_saved_records( $requests ) );
	}

	/**
	 * The index status shows the syncs that failed since the latest indexing run started, and why.
	 */
	public function test_failed_syncs_show_in_the_index_status(): void {
		$this->set_up_governing_site();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$paths = [];
		$this->mock_algolia_http_client( $paths, null, '/batch' );

		( new Watcher() )->register_hooks();

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->run_jobs();

		( new Indexer_Controller() )->register_hooks();
		$failed_jobs = static fn (): array => rest_do_request( new \WP_REST_Request( 'GET', '/onesearch/v1/indexer' ) )->get_data()['failed_jobs'];
		$this->assertSame(
			[
				Utils::get_current_site_url() => [
					[
						'job'   => Watcher::SYNC_HOOK,
						'args'  => [ $post_id ],
						'error' => 'Failed to save records to Algolia index. forced test exception',
					],
				],
			],
			$failed_jobs()
		);

		// An indexing run that started after the sync failed.
		$progress               = ( new Progress( Indexer::OPTION_PROGRESS ) )->start();
		$progress['started_at'] = time() + 1;
		( new Progress( Indexer::OPTION_PROGRESS ) )->set( $progress );

		$this->assertSame( [], $failed_jobs() );
	}

	/**
	 * A post whose sync failed no longer counts once a later sync of it succeeds.
	 */
	public function test_a_later_sync_makes_up_for_a_failed_one(): void {
		$this->set_up_governing_site();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$algolia = (object) [ 'fails' => true ];
		$paths   = [];
		$this->mock_algolia_http_client(
			$paths,
			static function ( string $path ) use ( $algolia ): string {
				if ( $algolia->fails && str_contains( $path, '/batch' ) ) {
					throw new \RuntimeException( 'forced test exception' );
				}

				return str_contains( $path, '/task/' ) ? '{"status":"published","pendingTask":false}' : '{"taskID":1,"updatedAt":"2024-01-01T00:00:00.000Z"}';
			}
		);

		( new Watcher() )->register_hooks();
		( new Indexer_Controller() )->register_hooks();
		$failed_syncs = static fn (): int => count( rest_do_request( new \WP_REST_Request( 'GET', '/onesearch/v1/indexer' ) )->get_data()['failed_jobs'][ Utils::get_current_site_url() ] ?? [] );

		$failing_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->run_jobs();
		$this->assertSame( 2, $failed_syncs() );

		$algolia->fails = false;
		wp_update_post(
			[
				'ID'         => $failing_id,
				'post_title' => 'Edited',
			]
		);
		$this->run_jobs();

		$this->assertSame( 1, $failed_syncs() );
	}

	/**
	 * A sync that fails on every Algolia host doesn't fail the syncs that run after it.
	 */
	public function test_a_failed_sync_does_not_fail_the_next_ones(): void {
		$this->set_up_governing_site();

		$algolia  = (object) [ 'fails' => true ];
		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client(
			$paths,
			static function ( string $path ) use ( $algolia ): string {
				// What the client throws when it can't reach a host, which it then skips.
				if ( $algolia->fails && str_contains( $path, '/batch' ) ) {
					throw new \OneSearch\Vendor\Algolia\AlgoliaSearch\Exceptions\RetriableException( 'Unreachable Host' );
				}

				return str_contains( $path, '/task/' ) ? '{"status":"published","pendingTask":false}' : '{"taskID":1,"updatedAt":"2024-01-01T00:00:00.000Z"}';
			},
			null,
			$requests
		);

		// Algolia is back once the first sync failed.
		add_action(
			'action_scheduler_failed_execution',
			static function () use ( $algolia ): void {
				$algolia->fails = false;
			}
		);

		( new Watcher() )->register_hooks();
		self::factory()->post->create_many( 2, [ 'post_status' => 'publish' ] );
		$this->run_jobs();

		$this->assertCount( 2, array_unique( array_column( $this->get_saved_records( $requests ), 'post_id' ) ), 'Both posts were sent to Algolia.' );
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

		// Nothing is queued, since 'post' isn't indexed.
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

		// Nothing is queued, since no post types are indexed.
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

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		$this->publish( $watcher, $post );

		$this->assertTrue( true );
	}

	/**
	 * A post moving to a status that isn't indexed, like trash, has its records deleted, not saved.
	 */
	public function test_on_after_insert_post_deletes_the_records_of_a_disallowed_status(): void {
		$this->set_up_brand_site();

		$recorded_paths = [];
		$requests       = [];
		$this->mock_algolia_http_client( $recorded_paths, null, null, $requests );

		$post = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		( new Watcher() )->register_hooks();

		wp_trash_post( $post->ID );
		$this->run_jobs();

		$this->assertSame( [ sprintf( 'site_post_id:"%s"', ( new Post_Record() )->get_site_post_id( $post->ID ) ) ], $this->get_delete_filters( $requests ) );
		$this->assertSame( [], $this->get_saved_records( $requests ) );
	}

	/**
	 * Consumer site attempts to fetch allowed post types from brand config.
	 */
	public function test_on_after_insert_post_consumer_site_checks_brand_config(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Remote_Client::TRANSIENT_KEY );

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
		$this->set_up_brand_site();

		$paths = [];
		$this->mock_algolia_http_client( $paths );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// 'post' is in indexable_entities, so it passes the guard.
		$this->publish( $watcher, $post );

		$this->assertTrue( true );
	}

	/**
	 * Publishing an indexable post saves its records.
	 * This verifies the full integration flow for a governing site.
	 */
	public function test_publishing_a_post_saves_its_records(): void {
		$this->set_up_governing_site();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// Transition from 'draft' to 'publish' (should save its records).
		$this->publish( $watcher, $post );

		// Assert that a /batch (saveObjects) call was made.
		$batch_calls = array_filter( $recorded_paths, static fn ( $p ) => str_contains( $p, '/batch' ) );
		$this->assertNotEmpty( $batch_calls, 'Happy path should save the records (saveObjects).' );
	}

	/**
	 * Publishing an indexable post saves its records on a consumer (brand) site.
	 * This verifies the full integration flow where credentials and indexable entities
	 * are resolved from the governing site's brand config cache.
	 */
	public function test_publishing_a_post_on_a_brand_site_saves_its_records(): void {
		$this->set_up_brand_site();

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// Transition from 'draft' to 'publish' (should save its records).
		$this->publish( $watcher, $post );

		// Assert that a /batch (saveObjects) call was made.
		$batch_calls = array_filter( $recorded_paths, static fn ( $p ) => str_contains( $p, '/batch' ) );
		$this->assertNotEmpty( $batch_calls, 'Consumer site happy path should save the records (saveObjects).' );
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

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->run_jobs();
		$stored_id = $this->get_indexed_site_post_id( $requests );

		// Drop the publish traffic, so only the delete request is left to assert on.
		$requests = [];

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);
		$this->run_jobs();

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

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->run_jobs();
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
		$this->run_jobs();

		$this->assertSame( [ sprintf( 'site_post_id:"%s"', $stored_id ) ], $this->get_delete_filters( $requests ) );
		$this->assertSame( [], array_filter( $paths, static fn ( string $path ): bool => str_contains( $path, '/batch' ) ), 'The protected content must not be saved.' );
	}

	/**
	 * A sync checks the post as it is now, even when an earlier job cached it before another request protected it.
	 */
	public function test_sync_skips_the_cached_copy_of_a_protected_post(): void {
		global $wpdb;

		$this->set_up_governing_site( [ 'post', 'attachment' ] );

		$post_id       = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$attachment_id = self::factory()->attachment->create( [ 'post_parent' => $post_id ] );

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		// Cache both posts, then protect the post without clearing that cache, the way another request would.
		get_post( $post_id );
		get_post( $attachment_id );
		$wpdb->update( $wpdb->posts, [ 'post_password' => 'secret' ], [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$watcher = new Watcher();
		$watcher->sync_post( $post_id );
		$watcher->sync_post( $attachment_id );

		$filters = implode( ' OR ', $this->get_delete_filters( $requests ) );

		$this->assertStringContainsString( sprintf( 'site_post_id:"%s"', ( new Post_Record() )->get_site_post_id( $post_id ) ), $filters );
		$this->assertStringContainsString( sprintf( 'site_post_id:"%s"', ( new Post_Record() )->get_site_post_id( $attachment_id ) ), $filters );
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
		$this->run_jobs();

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
		$this->run_jobs();

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
	 * Emptying the trash doesn't delete the records again, since trashing the posts already did.
	 */
	public function test_deleting_a_trashed_post_skips_the_index(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_trash_post( $post_id );
		$this->run_jobs();
		$this->assertNotEmpty( $this->get_delete_filters( $requests ) );

		$requests = [];
		wp_delete_post( $post_id, true );
		$this->run_jobs();

		$this->assertSame( [], $this->get_delete_filters( $requests ) );
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
		$this->run_jobs();

		$records = $this->get_saved_records( $requests );

		$this->assertNotEmpty( $records );
		$this->assertSame( 'Late Category', $records[0]['taxonomies']['category'][0]['name'] );
		$this->assertNotEmpty( $records[0]['thumbnail']['url'] );
	}

	/**
	 * Deleting a featured image removes it from the records of the post that used it.
	 */
	public function test_deleting_a_featured_image_removes_it_from_the_post(): void {
		$this->set_up_governing_site();

		$post_id  = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$image_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		set_post_thumbnail( $post_id, $image_id );

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		( new Watcher() )->register_hooks();

		wp_delete_attachment( $image_id, true );
		$this->run_jobs();

		$records = $this->get_saved_records( $requests );
		$this->assertSame( [ $post_id ], array_column( $records, 'post_id' ) );
		$this->assertSame( [], $records[0]['thumbnail'] );
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
		$this->run_jobs();

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
		$this->run_jobs();

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
		$this->run_jobs();

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
		$this->run_jobs();

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
		$this->run_jobs();

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

		$this->run_jobs();

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

		$this->run_jobs();

		$records = $this->get_saved_records( $requests );
		$this->assertSame( $attachment_id, $records[0]['post_id'] );
		$this->assertArrayNotHasKey( 'medium', $records[0]['thumbnail']['sizes'] ?? [] );

		$requests = [];
		wp_update_image_subsizes( $attachment_id );
		$this->run_jobs();

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

		add_action( Watcher::SYNC_HOOK, [ $watcher, 'sync_post' ] );

		$watcher->on_after_insert_post( $post->ID, $post, true, $before );
		$this->run_jobs();
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
}
