<?php
/**
 * Tests for Search_Controller on a consumer site.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Governing_Data_Handler;
use OneSearch\Modules\Rest\Search_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Consumer-site scenarios for {@see Search_Controller}.
 */
#[CoversClass( Search_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Search_Controller_ConsumerSiteTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		( new Search_Controller() )->register_hooks();
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
	 * Consumer site only registers re-index; governing-only endpoints are omitted.
	 */
	public function test_skips_governing_endpoints(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Search_Controller::NAMESPACE;

		$this->assertArrayNotHasKey( $ns . '/algolia-credentials', $routes );
		$this->assertArrayNotHasKey( $ns . '/indexable-entities', $routes );
		$this->assertArrayHasKey( $ns . '/re-index', $routes );
	}

	/**
	 * POST /re-index on a consumer site with no parent URL configured returns
	 * a 400 with the `no_parent_url` error code.
	 */
	public function test_reindex_returns_error_without_parent_url(): void {
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_transient( Governing_Data_Handler::TRANSIENT_KEY );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/re-index' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'no_parent_url', $data['code'] );
	}
}
