<?php
/**
 * Watcher unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Scheduler\Job_Scheduler;
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
		// Clean up any AS actions enqueued by the Watcher during this test.
		as_unschedule_all_actions( Job_Scheduler::HOOK );

		$this->tear_down_governing_site();

		parent::tearDown();
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
	 * Indexable post schedules an async Sync_Job for a governing site.
	 * Algolia is no longer called synchronously — the job runs in an AS worker.
	 */
	public function test_on_post_transition_triggers_algolia_reindex(): void {
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

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// Transition from 'draft' to 'publish' (should schedule async Sync_Job).
		$watcher->on_post_transition( 'publish', 'draft', $post );

		// Assert that an async action was enqueued for the job executor hook.
		$this->assertNotFalse(
			as_next_scheduled_action( Job_Scheduler::HOOK ),
			'Happy path should schedule an async Sync_Job via Action Scheduler.'
		);
	}

	/**
	 * Indexable post on a consumer site schedules an async Sync_Job.
	 * Credentials and indexable entities are resolved from the brand config cache,
	 * but Algolia is no longer called synchronously — the job runs in an AS worker.
	 */
	public function test_on_post_transition_triggers_algolia_reindex_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

		$this->set_consumer_brand_config_cache( 'test-app', 'test-key' );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// Transition from 'draft' to 'publish' (should schedule async Sync_Job).
		$watcher->on_post_transition( 'publish', 'draft', $post );

		// Assert that an async action was enqueued for the job executor hook.
		$this->assertNotFalse(
			as_next_scheduled_action( Job_Scheduler::HOOK ),
			'Consumer site happy path should schedule an async Sync_Job via Action Scheduler.'
		);
	}

	/**
	 * When the Sync_Job can't be queued, the post is synced during the request instead.
	 */
	public function test_syncs_inline_when_scheduling_fails(): void {
		$this->set_up_governing_site();

		$paths    = [];
		$requests = [];
		$this->mock_algolia_http_client( $paths, null, null, $requests );

		$post = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Action Scheduler reports a failed enqueue as action ID 0.
		add_filter( 'pre_as_enqueue_async_action', '__return_zero' );

		( new Watcher() )->on_post_transition( 'publish', 'draft', $post );
		$stored_id = $this->get_indexed_site_post_id( $requests );

		$requests          = [];
		$post->post_status = 'draft';

		( new Watcher() )->on_post_transition( 'draft', 'publish', $post );

		remove_filter( 'pre_as_enqueue_async_action', '__return_zero' );

		$this->assertSame(
			[ sprintf( 'site_post_id:"%s"', $stored_id ) ],
			$this->get_delete_filters( $requests ),
			'An unpublished post must have its records deleted inline.'
		);
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
}
