<?php
/**
 * Tests for Reindex_Controller on a governing site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Reindex_Controller;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Governing-site scenarios for {@see Reindex_Controller}.
 */
#[CoversClass( Reindex_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Reindex_Controller_GoverningSiteTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		( new Reindex_Controller() )->register_hooks();
		do_action( 'rest_api_init' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Governing site registers re-index.
	 */
	public function test_registers_reindex(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Reindex_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/re-index', $routes );
	}

	/**
	 * POST /re-index on a governing site with no Algolia credentials returns a
	 * failure response, since clearing the site's old records fails.
	 */
	public function test_reindex_returns_failure_without_algolia(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		delete_option( Search_Settings::OPTION_GOVERNING_ALGOLIA_CREDENTIALS );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['errors'] );
		$this->assertArrayHasKey( 'message', $data );
	}

	/**
	 * Governing reindex fans out to each shared site via wp_safe_remote_post.
	 */
	public function test_reindex_dispatches_to_each_shared_site(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
				[
					'name'    => 'Site B',
					'url'     => 'https://site-b.example.com/',
					'api_key' => 'key-b',
				],
			]
		);

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/re-index' ) ) {
				return $preempt;
			}
			$requested_urls[] = $url;
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode( [ 'errors' => [] ] ),
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$this->server->dispatch( $request );

		remove_filter( 'pre_http_request', $filter );

		$this->assertCount( 2, $requested_urls );
		$this->assertStringContainsString( 'site-a.example.com', $requested_urls[0] );
		$this->assertStringContainsString( 'site-b.example.com', $requested_urls[1] );
	}

	/**
	 * Non-200 from a child site is listed in the governing response's errors.
	 */
	public function test_reindex_records_child_non_200_as_failure(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'APP',
				'write_key' => 'KEY',
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
			]
		);

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/re-index' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => 500,
					'message' => 'Internal Server Error',
				],
				'body'     => '',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertNotEmpty( $data['errors'] );
	}

	/**
	 * A WP_Error from a child site is listed in the governing response's errors.
	 */
	public function test_reindex_records_child_wp_error_as_failure(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'APP',
				'write_key' => 'KEY',
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
			]
		);

		$filter = static function ( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/re-index' ) ) {
				return $preempt;
			}
			return new \WP_Error( 'http_request_failed', 'cURL timeout' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertNotEmpty( $data['errors'] );
	}

	/**
	 * A child site that fails to re-index returns its error, and the governing response lists its message.
	 */
	public function test_reindex_records_child_unsuccessful_response_as_failure(): void {
		delete_option( Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'APP',
				'write_key' => 'KEY',
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Site A',
					'url'     => 'https://site-a.example.com/',
					'api_key' => 'key-a',
				],
			]
		);

		// The governing site's own reindex succeeds.
		$recorded_paths = [];
		$this->mock_algolia_http_client( $recorded_paths );

		$succeeded = $this->reindex_with_child_response( 200, [ 'errors' => [] ] );
		$failed    = $this->reindex_with_child_response(
			500,
			[
				'code'    => 'onesearch_algolia_not_configured',
				'message' => 'Algolia admin credentials missing.',
				'data'    => [ 'status' => 500 ],
			]
		);

		$this->assertSame( [], $succeeded['errors'] );
		$this->assertSame(
			[
				[
					'site_url' => 'https://site-a.example.com/',
					'message'  => 'Algolia admin credentials missing.',
				],
			],
			$failed['errors']
		);
	}

	/**
	 * Reindexes with every child site responding with the given status and body.
	 *
	 * @param int                  $child_code The child response status.
	 * @param array<string, mixed> $child_body The child response body.
	 *
	 * @return array<string, mixed> The governing site's response data.
	 */
	private function reindex_with_child_response( int $child_code, array $child_body ): array {
		$filter = static function ( $preempt, $args, $url ) use ( $child_code, $child_body ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( false === strpos( $url, '/re-index' ) ) {
				return $preempt;
			}
			return [
				'response' => [
					'code'    => $child_code,
					'message' => '',
				],
				'body'     => wp_json_encode( $child_body ),
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$data = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/re-index' ) )->get_data();

		remove_filter( 'pre_http_request', $filter );

		return $data;
	}
}
