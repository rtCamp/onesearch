<?php
/**
 * Watcher unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Jobs\Index_Site_Job;
use OneSearch\Modules\Search\Jobs\Jobs;
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
	 * The watcher under test.
	 */
	private Watcher $watcher;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		$this->watcher = new Watcher();
		( new Jobs() )->register_hooks();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
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
		$this->fake_algolia_index();
		$this->watcher->register_hooks();

		add_action(
			'save_post',
			static function ( int $post_id ): void {
				wp_delete_post( $post_id, true );
			}
		);

		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->sync();

		$this->assertSame( [], $this->algolia_records );
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

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// 'post' is in indexable_entities, so it passes the guard.
		$this->publish( $watcher, $post );

		$this->assertTrue( true );
	}

	/**
	 * Publishing a post saves its records, on a governing site and on a brand site.
	 */
	public function test_publishing_indexes_the_post(): void {
		foreach ( [ 'set_up_governing_site', 'set_up_brand_site' ] as $set_up ) {
			$this->algolia_records = [];
			$this->{$set_up}();
			$this->fake_algolia_index();

			$post = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

			$this->publish( $this->watcher, $post );
			$this->sync();

			$this->assertSame( [ $post->ID ], $this->get_algolia_post_ids(), $set_up );
		}
	}

	/**
	 * The posts changed during a request are queued to index once the request ends.
	 */
	public function test_indexes_the_changed_posts_when_the_request_ends(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$this->watcher->register_hooks();
		// Ending the output buffers would end PHPUnit's too.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		do_action( 'shutdown' );
		$this->run_jobs();

		$this->assertSame( [ $post_id ], $this->get_algolia_post_ids() );
	}

	/**
	 * A post leaving the index, by moving to the trash or a draft, or getting a password, has its records deleted.
	 */
	public function test_a_post_leaving_the_index_has_its_records_deleted(): void {
		$this->set_up_brand_site();
		$this->fake_algolia_index();
		$this->watcher->register_hooks();

		$trashed   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$drafted   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$protected = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$unchanged = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->sync();
		$this->assertSame( [ $trashed, $drafted, $protected, $unchanged ], $this->get_algolia_post_ids() );

		wp_trash_post( $trashed );
		wp_update_post(
			[
				'ID'          => $drafted,
				'post_status' => 'draft',
			]
		);
		wp_update_post(
			[
				'ID'            => $protected,
				'post_password' => 'secret',
			]
		);
		$this->sync();

		$this->assertSame( [ $unchanged ], $this->get_algolia_post_ids() );
	}

	/**
	 * A permanently deleted post has its records deleted, and emptying the trash later queues nothing more.
	 */
	public function test_deleting_a_post_deletes_its_records(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$this->watcher->register_hooks();

		$deleted = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$trashed = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->sync();

		wp_delete_post( $deleted, true );
		wp_trash_post( $trashed );
		$this->sync();

		$this->assertSame( [], $this->algolia_records );

		wp_delete_post( $trashed, true );
		$this->watcher->sync_pending();

		$this->assertSame(
			[],
			as_get_scheduled_actions(
				[
					'group'  => 'onesearch',
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				],
				'ids'
			)
		);
	}

	/**
	 * Terms and a featured image saved after the post itself, as the REST API does, are in the record.
	 */
	public function test_indexes_terms_and_featured_image_saved_during_the_request(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$this->watcher->register_hooks();

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

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->sync();

		$record = $this->get_record( $post_id );
		$this->assertSame( 'Late Category', $record['taxonomies']['category'][0]['name'] );
		$this->assertNotEmpty( $record['thumbnail']['url'] );
	}

	/**
	 * Deleting a featured image removes it from the records of the post that used it.
	 */
	public function test_deleting_a_featured_image_removes_it_from_the_post(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$this->watcher->register_hooks();

		$post_id  = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$image_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		set_post_thumbnail( $post_id, $image_id );
		$this->sync();
		$this->assertNotEmpty( $this->get_record( $post_id )['thumbnail'] );

		wp_delete_attachment( $image_id, true );
		$this->sync();

		$this->assertSame( [], $this->get_record( $post_id )['thumbnail'] );
	}

	/**
	 * A post a save_post callback updates again is indexed as it was saved last.
	 */
	public function test_indexes_a_post_updated_again_while_it_is_saved(): void {
		$this->set_up_governing_site();
		$this->fake_algolia_index();
		$this->watcher->register_hooks();

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

		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Original Title',
			]
		);
		$this->sync();

		$this->assertSame( 'Updated Title', $this->get_record( $post_id )['post_title'] );
	}

	/**
	 * Media follows the post it's attached to out of the index and back in, even when that post's type isn't indexed.
	 */
	public function test_attachments_follow_their_parent(): void {
		$this->set_up_governing_site( [ 'attachment' ] );
		$this->fake_algolia_index();

		$parent_id      = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$attachment_ids = [
			self::factory()->attachment->create( [ 'post_parent' => $parent_id ] ),
			self::factory()->attachment->create( [ 'post_parent' => $parent_id ] ),
		];

		$this->watcher->register_hooks();
		$this->sync_posts( $attachment_ids );
		$this->assertSame( $attachment_ids, $this->get_algolia_post_ids() );

		wp_update_post(
			[
				'ID'          => $parent_id,
				'post_status' => 'draft',
			]
		);
		$this->sync();

		$this->assertSame( [], $this->get_algolia_post_ids() );

		wp_update_post(
			[
				'ID'          => $parent_id,
				'post_status' => 'publish',
			]
		);
		$this->sync();

		$this->assertSame( $attachment_ids, $this->get_algolia_post_ids() );
	}

	/**
	 * Media left behind by a deleted post is indexed, now that it's unattached.
	 */
	public function test_media_of_a_deleted_post_is_indexed(): void {
		$this->set_up_governing_site( [ 'attachment' ] );
		$this->fake_algolia_index();

		$parent_id     = self::factory()->post->create( [ 'post_status' => 'trash' ] );
		$attachment_id = self::factory()->attachment->create( [ 'post_parent' => $parent_id ] );

		$this->watcher->register_hooks();

		// Read the media before the post is deleted, so its post is cached with the old parent.
		get_post( $attachment_id );

		wp_delete_post( $parent_id, true );
		$this->sync();

		$this->assertSame( [ $attachment_id ], $this->get_algolia_post_ids() );
	}

	/**
	 * Media attached to a private post in the Media Library leaves the index, and comes back once it's detached.
	 */
	public function test_media_follows_the_media_library_attach_and_detach(): void {
		$this->set_up_governing_site( [ 'attachment' ] );
		$this->fake_algolia_index();

		$parent_id     = self::factory()->post->create( [ 'post_status' => 'private' ] );
		$attachment_id = self::factory()->attachment->create( [ 'post_parent' => 0 ] );

		$this->watcher->register_hooks();
		$this->sync_posts( [ $attachment_id ] );
		$this->assertSame( [ $attachment_id ], $this->get_algolia_post_ids() );

		$this->attach_media( $attachment_id, $parent_id );
		$this->sync();

		$this->assertSame( [], $this->get_algolia_post_ids() );

		$this->attach_media( $attachment_id, 0 );
		$this->sync();

		$this->assertSame( [ $attachment_id ], $this->get_algolia_post_ids() );
	}

	/**
	 * Media left behind by a deleted page moves to that page's parent, and leaves the index if that parent is private.
	 */
	public function test_media_of_a_deleted_page_follows_its_new_parent(): void {
		$this->set_up_governing_site( [ 'attachment' ] );
		$this->fake_algolia_index();

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

		$this->watcher->register_hooks();
		$this->sync_posts( [ $attachment_id ] );
		$this->assertSame( [ $attachment_id ], $this->get_algolia_post_ids() );

		wp_delete_post( $parent_id, true );
		$this->sync();

		$this->assertSame( [], $this->get_algolia_post_ids() );
	}

	/**
	 * New media is saved with its image sizes.
	 */
	public function test_new_media_is_synced_with_its_image_sizes(): void {
		$this->set_up_governing_site( [ 'attachment' ] );
		$this->fake_algolia_index();
		$this->watcher->register_hooks();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/media' );
		$request->set_header( 'Content-Disposition', 'attachment; filename=canola.jpg' );
		$request->set_header( 'Content-Type', 'image/jpeg' );
		$request->set_body( (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ) ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local fixture.

		$attachment_id = rest_do_request( $request )->get_data()['id'];
		$this->sync();

		$this->assertSame( [ $attachment_id ], $this->get_algolia_post_ids() );
		$this->assertArrayHasKey( 'medium', $this->get_record( $attachment_id )['thumbnail']['sizes'] );
	}

	/**
	 * Media is saved again when image sizes are added to it later, like when the editor generates them.
	 */
	public function test_media_is_synced_when_its_image_sizes_are_added(): void {
		$this->set_up_governing_site( [ 'attachment' ] );
		$this->fake_algolia_index();
		$this->watcher->register_hooks();

		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		$this->sync();

		$this->assertArrayNotHasKey( 'medium', $this->get_record( $attachment_id )['thumbnail']['sizes'] ?? [] );

		wp_update_image_subsizes( $attachment_id );
		$this->sync();

		$this->assertArrayHasKey( 'medium', $this->get_record( $attachment_id )['thumbnail']['sizes'] );
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
	 * Attaches or detaches media the way the Media Library does, which updates its parent without saving it.
	 *
	 * @param int $attachment_id The media ID.
	 * @param int $parent_id     The post to attach it to, or 0 to detach it.
	 */
	private function attach_media( int $attachment_id, int $parent_id ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, [ 'post_parent' => $parent_id ], [ 'ID' => $attachment_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		do_action( 'wp_media_attach_action', $parent_id ? 'attach' : 'detach', $attachment_id, $parent_id );
		clean_attachment_cache( $attachment_id );
	}

	/**
	 * Ends the request the way WordPress does, and runs the sync it queued.
	 */
	private function sync(): void {
		$this->watcher->sync_pending();
		$this->run_jobs();
	}

	/**
	 * Syncs posts that changed before the watcher listened.
	 *
	 * @param int[] $post_ids The post IDs.
	 */
	private function sync_posts( array $post_ids ): void {
		Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ Utils::get_current_site_url(), $post_ids ] );
		$this->run_jobs();
	}

	/**
	 * Gets the first record of a post in the fake index.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array<string, mixed>
	 */
	private function get_record( int $post_id ): array {
		foreach ( $this->algolia_records as $record ) {
			if ( ( $record['post_id'] ?? null ) === $post_id && 0 === $record['chunk_index'] ) {
				return $record;
			}
		}

		$this->fail( sprintf( 'Post %d has no record.', $post_id ) );
	}
}
