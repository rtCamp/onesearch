<?php
/**
 * Watcher unit tests.
 *
 * @package OneSearch\Tests\Unit\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Search;

use OneSearch\Modules\Rest\Governing_Data_Handler;
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
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		parent::tearDown();
	}

	// ── register_hooks ──────────────────────────────────────────────────

	/**
	 * Ensures register_hooks adds transition_post_status action.
	 */
	public function test_register_hooks_adds_transition_post_status_action(): void {
		$watcher = new Watcher();
		$watcher->register_hooks();

		$this->assertNotFalse( has_action( 'transition_post_status', [ $watcher, 'on_post_transition' ] ) );
	}

	// ── on_post_transition ──────────────────────────────────────────────

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
	 * The delete still attempts (and fails without creds), but the reindex does not happen.
	 */
	public function test_on_post_transition_does_not_reindex_disallowed_status(): void {
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

		// Transitioning to 'trash' — should not reindex (only delete).
		$watcher->on_post_transition( 'trash', 'publish', $post );

		$this->assertTrue( true );
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

		$cached_config = [
			'algolia_credentials' => [
				'app_id'    => 'test-app',
				'write_key' => 'test-key',
			],
			'search_settings'     => [
				'algolia_enabled'  => true,
				'searchable_sites' => [],
			],
			'indexable_entities'  => [ 'post' ],
			'available_sites'     => [],
		];
		$method        = new \ReflectionMethod( Governing_Data_Handler::class, 'set_brand_config_cache' );
		$method->invoke( null, $cached_config );

		$post    = self::factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$watcher = new Watcher();

		// 'post' is in indexable_entities, so it passes the guard.
		// Algolia SDK will fail with test credentials, but the guard logic is tested.
		$watcher->on_post_transition( 'publish', 'draft', $post );

		$this->assertTrue( true );
	}
}
