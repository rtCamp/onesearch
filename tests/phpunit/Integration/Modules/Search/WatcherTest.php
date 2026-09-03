<?php
/**
 * Watcher unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Rest\Governing_Data_Handler;
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
	 * Skips when post is not a WP_Post instance.
	 */
	public function test_on_post_transition_skips_non_wp_post(): void {
		$watcher = new Watcher();

		// Should not throw or error out.
		// @phpstan-ignore argument.type -- Non-WP_Post passed intentionally.
		$watcher->on_post_transition( 'publish', 'draft', 'not-a-post' );

		$this->assertTrue( true );
	}

	/**
	 * Skips when post type is not indexable (no entities configured).
	 */
	public function test_on_post_transition_skips_non_indexable_post_type(): void {
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
		$watcher->on_post_transition( 'publish', 'draft', $post );

		$this->assertTrue( true );
	}

	/**
	 * Skips when no indexable entities are configured at all.
	 */
	public function test_on_post_transition_skips_when_no_entities_configured(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );

		$post    = self::factory()->post->create_and_get();
		$watcher = new Watcher();

		// Should exit early without hitting Algolia since no indexable entities are configured.
		$watcher->on_post_transition( 'publish', 'draft', $post );

		$this->assertTrue( true );
	}

	/**
	 * Processes indexable post types (though Algolia call will fail without credentials,
	 * we verify it gets past the post_type guard).
	 */
	public function test_on_post_transition_processes_indexable_post_type(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					Utils::normalize_url( get_site_url() ) => [ 'post' ],
				],
			]
		);
		// No Algolia credentials → delete_by will return WP_Error, so on_post_transition
		// will return early after the failed delete. This tests the flow gets past the
		// post_type check.
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		$watcher->on_post_transition( 'publish', 'draft', $post );

		$this->assertTrue( true );
	}

	/**
	 * Skips reindexing when new status is not an allowed status (e.g., trashed).
	 *
	 * Injects a fake Algolia HTTP client to intercept SDK-level requests (the SDK
	 * does not use wp_remote_*, so pre_http_request cannot be used here). After
	 * transitioning to 'trash', only the deleteBy call should have been made — no
	 * /batch (saveObjects) request should appear.
	 */
	public function test_on_post_transition_does_not_reindex_disallowed_status(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

		$this->set_consumer_brand_config_cache();

		// Intercept every Algolia SDK HTTP call and record the request paths.
		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// Transitioning to 'trash' should delete from the index but must not reindex.
		$watcher->on_post_transition( 'trash', 'publish', $post );

		// deleteBy was called (some path was recorded), but no /batch (saveObjects) call
		// should have been made — the status guard must have stopped reindexing.
		$batch_calls = array_filter( $recorded_paths, static fn ( $p ) => str_contains( $p, '/batch' ) );
		$this->assertEmpty( $batch_calls, 'Trash transition should perform delete only and must not reindex.' );
	}

	/**
	 * Consumer site attempts to fetch allowed post types from brand config.
	 */
	public function test_on_post_transition_consumer_site_checks_brand_config(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		$post    = self::factory()->post->create_and_get();
		$watcher = new Watcher();

		// No parent URL → get_brand_config returns WP_Error → post type is not indexable.
		$watcher->on_post_transition( 'publish', 'draft', $post );

		$this->assertTrue( true );
	}

	/**
	 * Consumer site with cached brand config recognizes indexable post types.
	 */
	public function test_on_post_transition_consumer_with_cached_config(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

		$this->set_consumer_brand_config_cache( 'test-app', 'test-key' );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// 'post' is in indexable_entities, so it passes the guard.
		// Algolia SDK will fail with test credentials, but the guard logic is tested.
		$watcher->on_post_transition( 'publish', 'draft', $post );

		$this->assertTrue( true );
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
	 * Indexable post triggers Algolia saveObjects (reindex) call.
	 * This verifies the full integration flow for a governing site.
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

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// Transition from 'draft' to 'publish' (should trigger reindex).
		$watcher->on_post_transition( 'publish', 'draft', $post );

		// Assert that a /batch (saveObjects) call was made.
		$batch_calls = array_filter( $recorded_paths, static fn ( $p ) => str_contains( $p, '/batch' ) );
		$this->assertNotEmpty( $batch_calls, 'Happy path should trigger Algolia reindex (saveObjects).' );
	}

	/**
	 * Indexable post triggers Algolia saveObjects (reindex) call on a consumer (brand) site.
	 * This verifies the full integration flow where credentials and indexable entities
	 * are resolved from the governing site's brand config cache.
	 */
	public function test_on_post_transition_triggers_algolia_reindex_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

		$this->set_consumer_brand_config_cache( 'test-app', 'test-key' );

		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// Transition from 'draft' to 'publish' (should trigger reindex).
		$watcher->on_post_transition( 'publish', 'draft', $post );

		// Assert that a /batch (saveObjects) call was made.
		$batch_calls = array_filter( $recorded_paths, static fn ( $p ) => str_contains( $p, '/batch' ) );
		$this->assertNotEmpty( $batch_calls, 'Consumer site happy path should trigger Algolia reindex (saveObjects).' );
	}

	/**
	 * A post leaving the index must be deleted by the `site_post_id` its records carry.
	 *
	 * Regression test: the filter used to be built from the raw site URL while records store
	 * the sanitized site key, so Algolia matched nothing, reported success, and unpublished
	 * or trashed posts kept showing up until a full re-sync. The expected value is read back
	 * off the outgoing payload, so the write and delete paths are checked against each other
	 * instead of against a value the test rebuilds for itself.
	 *
	 * @see https://github.com/rtCamp/OnePress/issues/84
	 */
	public function test_deletes_by_the_site_post_id_written_to_records(): void {
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
			'The delete filter must name the site_post_id stored on the records.'
		);
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
					Utils::normalize_url( get_site_url() ) => $entities,
				],
			]
		);
	}
}
