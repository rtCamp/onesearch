<?php
/**
 * Uninstall tests.
 *
 * @package OneSearch\Tests\Integration
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration;

use OneSearch\Jobs\Queue;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Runs uninstall.php, which is a script rather than a class.
 */
#[CoversNothing]
final class UninstallTest extends TestCase {
	/**
	 * Uninstalling a brand site deletes its records, deregisters it from the governing site, deletes its pending jobs while keeping the ones that ran, and removes the options that hold the connection between the sites and its indexing progress.
	 *
	 * The script declares functions, so it can only be run once per test run.
	 */
	public function test_uninstall_deregisters_the_brand_and_deletes_the_connection_options(): void {
		$options = [
			Settings::OPTION_CONSUMER_PARENT_SITE_URL,
			Settings::OPTION_CONSUMER_API_KEY,
			Indexer::OPTION_PROGRESS,
		];

		// A job that failed, and one that's waiting.
		$queue = new Queue();
		$queue->add( Indexer::BATCH_HOOK, [ 'run-id', 0 ] );
		$this->fail_job_with_fatal_error( (int) \ActionScheduler::store()->find_action( Indexer::BATCH_HOOK ), 'Allowed memory size exhausted' );
		$queue->add( Indexer::BATCH_HOOK, [ 'run-id', 100 ] );
		update_option( Indexer::OPTION_PROGRESS, [ 'id' => 'run-id' ] );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );
		$api_key = Settings::get_api_key();

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

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'onesearch/onesearch.php' );
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant, WordPressVIPMinimum.Files.IncludingFile.NotAbsolutePath -- Runs the script under test.
		include dirname( __DIR__, 3 ) . '/uninstall.php';

		// The records are deleted while the governing site still recognizes this site.
		$this->assertSame(
			[
				[ 'GET', 'https://governing.example.com/wp-json/onesearch/v1/brand-site/config', $api_key ],
				[ 'DELETE', 'https://governing.example.com/wp-json/onesearch/v1/brand-site', $api_key ],
			],
			$sent
		);

		$this->assertFalse( $queue->has( Indexer::BATCH_HOOK ) );
		$this->assertSame( \ActionScheduler_Store::STATUS_FAILED, $queue->get_latest( Indexer::BATCH_HOOK )['status'] );

		foreach ( $options as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' should be deleted.' );
		}
	}
}
