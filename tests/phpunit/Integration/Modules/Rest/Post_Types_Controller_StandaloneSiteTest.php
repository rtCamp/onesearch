<?php
/**
 * Tests for Post_Types_Controller on a standalone site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Post_Types_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Standalone-site scenarios for {@see Post_Types_Controller}.
 *
 * No site type is set, mirroring an install that hasn't been onboarded as part
 * of a federation.
 */
#[CoversClass( Post_Types_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Post_Types_Controller_StandaloneSiteTest extends TestCase {
	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Settings::OPTION_SITE_TYPE );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		( new Post_Types_Controller() )->register_hooks();
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
	 * Standalone site registers all-post-types.
	 */
	public function test_registers_all_post_types(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Post_Types_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/all-post-types', $routes );
	}

	/**
	 * Returns the local site's post types.
	 */
	public function test_get_all_post_types_returns_local_types(): void {
		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/all-post-types' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$site_url = trailingslashit( get_site_url() );
		$this->assertArrayHasKey( $site_url, $data['sites'] );

		$slugs = array_column( $data['sites'][ $site_url ]['post_types'], 'slug' );
		$this->assertContains( 'post', $slugs );
		$this->assertContains( 'page', $slugs );
	}

	/**
	 * Each post type entry exposes slug, label, and restBase.
	 */
	public function test_get_all_post_types_post_type_structure(): void {
		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/all-post-types' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$site_url   = trailingslashit( get_site_url() );
		$post_types = $data['sites'][ $site_url ]['post_types'];
		$first      = $post_types[0];

		$this->assertArrayHasKey( 'slug', $first );
		$this->assertArrayHasKey( 'label', $first );
		$this->assertArrayHasKey( 'restBase', $first );
	}
}
