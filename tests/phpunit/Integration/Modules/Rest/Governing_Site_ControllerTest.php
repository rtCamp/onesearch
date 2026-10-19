<?php
/**
 * Tests for Governing_Site_Controller.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Governing_Site_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the /governing-site route.
 */
#[CoversClass( Governing_Site_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Governing_Site_ControllerTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		( new Governing_Site_Controller() )->register_hooks();
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
	 * Removes the governing site option and returns success.
	 */
	public function test_remove_governing_site_deletes_option(): void {
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * Succeeds even when no governing site was previously set.
	 */
	public function test_remove_governing_site_succeeds_when_already_unset(): void {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
	}

	/**
	 * Disconnecting tells the governing site to remove this brand site before clearing the local pairing.
	 */
	public function test_remove_governing_site_notifies_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$requested_urls[] = $url;
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => '{"success":true}',
				'headers'  => [],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['governing_site_notified'] );
		$this->assertSame( [ 'https://governing.example.com/wp-json/onesearch/v1/brand-site' ], $requested_urls );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * An unreachable governing site doesn't block the local disconnect, but the admin is told to finish it there.
	 */
	public function test_remove_governing_site_disconnects_locally_when_governing_site_unreachable(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$filter = static fn () => new \WP_Error( 'http_request_failed', 'cURL error 7' );
		add_filter( 'pre_http_request', $filter );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertTrue( $data['success'] );
		$this->assertFalse( $data['governing_site_notified'] );
		$this->assertStringContainsString( 'https://governing.example.com', $data['message'] );
		$this->assertStringContainsString( 'could not be notified', $data['message'] );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * A governing site that rejects this site's key may have already removed it, and the warning says so.
	 */
	public function test_remove_governing_site_warns_when_governing_site_does_not_recognize_it(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$filter = static fn () => [
			'response' => [
				'code'    => 401,
				'message' => 'Unauthorized',
			],
			'body'     => '',
			'headers'  => [],
			'cookies'  => [],
		];
		add_filter( 'pre_http_request', $filter );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/governing-site' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', $filter );

		$this->assertTrue( $data['success'] );
		$this->assertFalse( $data['governing_site_notified'] );
		$this->assertStringContainsString( 'did not recognize', $data['message'] );
		$this->assertNull( Settings::get_parent_site_url() );
	}
}
