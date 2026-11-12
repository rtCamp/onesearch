<?php
/**
 * Uninstaller tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Core
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Core;

use OneSearch\Modules\Core\Uninstaller;
use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Index_Status;
use OneSearch\Modules\Search\Jobs\Index_Site_Job;
use OneSearch\Modules\Search\Jobs\Jobs;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Runs uninstall.php and the deactivation hook, which share the Uninstaller.
 */
#[CoversClass( Uninstaller::class )]
final class UninstallerTest extends TestCase {
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
		update_option( Settings::OPTION_DELETE_DATA_ON_DEACTIVATION, true );
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
	 * Uninstalling a site that was never set up deletes its data without reporting a failed cleanup.
	 */
	public function test_uninstall_on_a_site_without_a_type_logs_no_errors(): void {
		update_option( Settings::OPTION_DELETE_DATA_ON_DEACTIVATION, true );

		$log      = (string) wp_tempnam();
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Captures what the uninstall logs.

		$this->run_uninstall();

		ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the log.

		$this->assertSame( '', (string) file_get_contents( $log ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads a local temp file.
		$this->assertFalse( get_option( Settings::OPTION_DELETE_DATA_ON_DEACTIVATION ) );
	}

	/**
	 * Deactivating keeps the plugin's data unless the site chose to delete it.
	 */
	public function test_deactivation_keeps_the_data_by_default(): void {
		$this->set_up_deletable_governing_site();

		$algolia_paths = [];
		$this->mock_algolia_http_client( $algolia_paths );

		$this->deactivate( false );

		$this->assertSame( Settings::SITE_TYPE_GOVERNING, Settings::get_site_type() );
		$this->assertSame( 'app-id', Search_Settings::get_algolia_credentials()['app_id'] );
		$this->assertSame( [], $algolia_paths, 'The index is kept.' );
	}

	/**
	 * Deactivating deletes the plugin's data, like uninstalling, when the site chose to.
	 */
	public function test_deactivation_deletes_the_data_when_the_site_chose_to(): void {
		$this->set_up_deletable_governing_site();
		update_option( Settings::OPTION_DELETE_DATA_ON_DEACTIVATION, true );

		$algolia_paths = [];
		$this->mock_algolia_http_client( $algolia_paths );

		$this->deactivate( false );

		$this->assertNotEmpty( preg_grep( '#^/1/indexes/onesearch_[^/]+_wp_posts$#', $algolia_paths ), 'The whole index is deleted.' );
		$this->assertNull( Settings::get_site_type() );
		$this->assertNull( Search_Settings::get_algolia_credentials()['app_id'] );
		$this->assertFalse( get_option( Settings::OPTION_DELETE_DATA_ON_DEACTIVATION ) );
	}

	/**
	 * Deactivating for the whole network only deletes the data of the sites that chose to.
	 */
	public function test_network_deactivation_only_deletes_the_data_of_the_sites_that_chose_to(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network deactivation needs a multisite.' );
		}

		$kept_site_id    = self::factory()->blog->create();
		$deleted_site_id = self::factory()->blog->create();

		foreach ( [ $kept_site_id, $deleted_site_id ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->set_up_deletable_governing_site();
			restore_current_blog();
		}

		update_blog_option( $deleted_site_id, Settings::OPTION_DELETE_DATA_ON_DEACTIVATION, true );

		$algolia_paths = [];
		$this->mock_algolia_http_client( $algolia_paths );

		$this->deactivate( true );

		$this->assertSame( Settings::SITE_TYPE_GOVERNING, get_blog_option( $kept_site_id, Settings::OPTION_SITE_TYPE ) );
		$this->assertFalse( get_blog_option( $deleted_site_id, Settings::OPTION_SITE_TYPE ) );
	}

	/**
	 * Deactivating for the network keeps the data of the sites on other networks, where the plugin is still active.
	 */
	public function test_network_deactivation_keeps_the_data_of_the_sites_on_other_networks(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network deactivation needs a multisite.' );
		}

		$other_network_id = self::factory()->network->create();
		$other_site_id    = self::factory()->blog->create( [ 'network_id' => $other_network_id ] );

		switch_to_blog( $other_site_id );
		$this->set_up_deletable_governing_site();
		update_option( Settings::OPTION_DELETE_DATA_ON_DEACTIVATION, true );
		restore_current_blog();

		$algolia_paths = [];
		$this->mock_algolia_http_client( $algolia_paths );

		$this->deactivate( true );

		$this->assertSame( Settings::SITE_TYPE_GOVERNING, get_blog_option( $other_site_id, Settings::OPTION_SITE_TYPE ) );
	}

	/**
	 * Sets up a governing site with credentials, so deleting its index reaches Algolia.
	 */
	private function set_up_deletable_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'app-id',
				'write_key' => 'write-key',
			]
		);
	}

	/**
	 * Fires the plugin's deactivation hook, the way WordPress does.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	private function deactivate( bool $network_wide ): void {
		do_action( 'deactivate_' . plugin_basename( ONESEARCH_FILE ), $network_wide );
	}

	/**
	 * Runs uninstall.php the way WordPress does.
	 */
	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'onesearch/onesearch.php' );
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant, WordPressVIPMinimum.Files.IncludingFile.NotAbsolutePath -- Runs the script under test.
		include dirname( __DIR__, 5 ) . '/uninstall.php';
	}
}
