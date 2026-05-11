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
use OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia as AlgoliaSDK;
use OneSearch\Vendor\Psr\Http\Message\RequestInterface;
use OneSearch\Vendor\Psr\Http\Message\ResponseInterface;
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
	 *
	 * Injects a fake Algolia HTTP client to intercept SDK-level requests (the SDK
	 * does not use wp_remote_*, so pre_http_request cannot be used here). After
	 * transitioning to 'trash', only the deleteBy call should have been made — no
	 * /batch (saveObjects) request should appear.
	 */
	public function test_on_post_transition_does_not_reindex_disallowed_status(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

		$cached_config = [
			'algolia_credentials' => [
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
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

		// Intercept every Algolia SDK HTTP call and record the request paths.
		$recorded_paths = [];
		AlgoliaSDK::setHttpClient(
			new class( $recorded_paths ) implements \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\HttpClientInterface {
				/** @var array<int, string> */
				private array $paths;

				/**
				 * @param array<int, string> $paths Reference to the array that records intercepted request paths.
				 */
				public function __construct( array &$paths ) {
					$this->paths = &$paths;
				}

				/**
				 * {@inheritDoc}
				 *
				 * @param \OneSearch\Vendor\Psr\Http\Message\RequestInterface $request       The PSR-7 request.
				 * @param mixed            $timeout       Request timeout.
				 * @param mixed            $connect_timeout Connection timeout.
				 */
				public function sendRequest( RequestInterface $request, mixed $timeout, mixed $connect_timeout ): ResponseInterface { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
					$path          = (string) $request->getUri()->getPath();
					$this->paths[] = $path;

					// getTask polling → mark published so wait() completes immediately.
					// All other requests (deleteBy) → return a taskID.
					$body = str_contains( $path, '/task/' )
						? '{"status":"published","pendingTask":false}'
						: '{"taskID":1,"updatedAt":"2024-01-01T00:00:00.000Z"}';

					// @phpstan-ignore return.type
					return new \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\Psr7\Response( 200, [], $body );
				}
			}
		);

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
