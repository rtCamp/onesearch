<?php
/**
 * Uninstall tests.
 *
 * @package OneSearch\Tests\Integration
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration;

use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Index_Status;
use OneSearch\Modules\Search\Jobs\Index_Site_Job;
use OneSearch\Modules\Search\Jobs\Jobs;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Runs uninstall.php, which is a script rather than a class.
 */
#[CoversNothing]
final class UninstallTest extends TestCase {
	/**
	 * Uninstalling a brand site deletes its records, deregisters it from the governing site, and removes the options that hold the connection between the sites.
	 *
	 * It deletes the jobs waiting to run, the job records and what the index knows about each post, and keeps the jobs that ran.
	 */
	public function test_uninstall_deregisters_the_brand_and_deletes_the_connection_options(): void {
		$options = [
			Settings::OPTION_CONSUMER_PARENT_SITE_URL,
			Settings::OPTION_CONSUMER_API_KEY,
		];

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		$api_key = Settings::get_api_key();

		// A job that ran, one that waits, and what the index knows.
		( new Jobs() )->register_hooks();
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'http_request_failed', 'cURL error 7' ) );
		Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ Utils::get_current_site_url() ] );
		$this->run_jobs();
		$ran = as_get_scheduled_actions( [ 'group' => 'onesearch' ], 'ids' );
		Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ Utils::get_current_site_url() ] );
		remove_all_filters( 'pre_http_request' );

		$post_id = self::factory()->post->create();
		Index_Status::record_saved( [ $post_id ] );
		Index_Status::record_errors( [ $post_id => 'The post is too big to index.' ] );

		// Fail the brand config request, so the record cleanup stops before it reaches Algolia.
		$sent = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$sent ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$sent[] = [ $args['method'] ?? 'GET', $url, $args['headers']['X-OneSearch-Token'] ?? null ];

				if ( str_ends_with( $url, '/brand-site/config' ) ) {
					return new \WP_Error( 'http_request_failed', 'cURL error 7' );
				}

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '',
					'headers'  => [],
					'cookies'  => [],
				];
			},
			10,
			3
		);

		$this->run_uninstall();

		// The records are deleted while the governing site still recognizes this site.
		$this->assertSame(
			[
				[ 'GET', 'https://governing.example.com/wp-json/onesearch/v1/brand-site/config', $api_key ],
				[ 'DELETE', 'https://governing.example.com/wp-json/onesearch/v1/brand-site', $api_key ],
			],
			$sent
		);

		foreach ( $options as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' should be deleted.' );
		}

		$this->assertSame( [], Jobs::registry()->get_statuses() );
		$this->assertSame( '', get_post_meta( $post_id, Index_Status::META_INDEXED_AT, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Index_Status::META_ERROR, true ) );
		$this->assertSame( $ran, as_get_scheduled_actions( [ 'group' => 'onesearch' ], 'ids' ), 'The job that ran is kept, and the waiting one is gone.' );
	}

	/**
	 * Uninstalling a governing site disconnects its brands, deletes the index and the plugin's data, and keeps the jobs that ran.
	 */
	public function test_uninstall_on_a_governing_site_disconnects_the_brands_and_deletes_the_index(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand A',
					'url'     => 'https://a.example.com/',
					'api_key' => 'key-a',
				],
				[
					'name'    => 'Brand B',
					'url'     => 'https://b.example.com/',
					'api_key' => 'key-b',
				],
			]
		);

		( new Jobs() )->register_hooks();
		Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ Utils::get_current_site_url() ] );
		$this->run_jobs();
		$ran = as_get_scheduled_actions( [ 'group' => 'onesearch' ], 'ids' );
		Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ Utils::get_current_site_url() ] );
		$this->assertNotEmpty( $ran );
		$this->assertCount( count( $ran ) + 1, as_get_scheduled_actions( [ 'group' => 'onesearch' ], 'ids' ) );

		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'app-id',
				'write_key' => 'write-key',
			]
		);
		set_transient( Remote_Client::TRANSIENT_KEY, [ 'search_settings' => [] ], HOUR_IN_SECONDS );
		$proxy_id = self::factory()->attachment->create( [ 'post_name' => 'onesearch-remote-attachment-proxy' ] );
		update_option( 'onesearch_proxy_attachment_id', $proxy_id );

		$algolia_paths = [];
		$this->mock_algolia_http_client( $algolia_paths );

		$sent = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$sent ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$sent[] = [ $args['method'] ?? 'GET', $url, $args['headers']['X-OneSearch-Token'] ?? null ];

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '',
					'headers'  => [],
					'cookies'  => [],
				];
			},
			10,
			3
		);

		$this->run_uninstall();

		$this->assertSame(
			[
				[ 'DELETE', 'https://a.example.com/wp-json/onesearch/v1/governing-site', 'key-a' ],
				[ 'DELETE', 'https://b.example.com/wp-json/onesearch/v1/governing-site', 'key-b' ],
			],
			$sent
		);
		$this->assertNotEmpty( preg_grep( '#^/1/indexes/onesearch_[^/]+_wp_posts$#', $algolia_paths ), 'The whole index is deleted.' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks every option the plugin may have left.
		$this->assertSame( [], $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'onesearch_' ) . '%' ) ) );
		$this->assertFalse( get_transient( Remote_Client::TRANSIENT_KEY ) );
		$this->assertNull( get_post( $proxy_id ) );

		$this->assertSame( $ran, as_get_scheduled_actions( [ 'group' => 'onesearch' ], 'ids' ), 'The job that ran is kept, and the waiting one is gone.' );
		foreach ( $ran as $action_id ) {
			$this->assertNotEmpty( \ActionScheduler::logger()->get_logs( (string) $action_id ), 'The job that ran keeps its logs.' );
		}
	}

	/**
	 * Runs uninstall.php, which declares its functions the first time it's included.
	 */
	private function run_uninstall(): void {
		if ( function_exists( 'OneSearch\run_uninstaller' ) ) {
			\OneSearch\run_uninstaller();
			return;
		}

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'onesearch/onesearch.php' );
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant, WordPressVIPMinimum.Files.IncludingFile.NotAbsolutePath -- Runs the script under test.
		include dirname( __DIR__, 3 ) . '/uninstall.php';
	}
}
