<?php
/**
 * Tests for Brand_Site_Controller on a governing site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Brand_Site_Controller;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Governing-site scenarios for {@see Brand_Site_Controller}.
 */
#[CoversClass( Brand_Site_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Brand_Site_Controller_GoverningSiteTest extends TestCase {
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

		( new Brand_Site_Controller() )->register_hooks();
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
	 * Governing site registers the brand-site DELETE route brand sites use to disconnect.
	 */
	public function test_registers_brand_site_delete(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Brand_Site_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-site', $routes );
		$this->assertArrayHasKey( 'DELETE', $routes[ $ns . '/brand-site' ][0]['methods'] );
	}

	/**
	 * A brand site presenting its own token removes itself, and the removal runs the same
	 * cleanup as deleting it from the settings screen: its records and indexable entities are purged.
	 */
	public function test_remove_brand_site_removes_the_requesting_site_and_its_search_data(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example.com',
					'api_key' => 'brand-one-key',
				],
				[
					'name'    => 'Brand Two',
					'url'     => 'https://brand-two.example.com',
					'api_key' => 'brand-two-key',
				],
			]
		);
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);
		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					'https://brand-one.example.com/' => [ 'post', 'page' ],
					'https://brand-two.example.com/' => [ 'post' ],
				],
			]
		);

		$algolia_paths    = [];
		$algolia_requests = [];
		$this->mock_algolia_http_client( $algolia_paths, null, null, $algolia_requests );

		// The shared sites listeners that purge a removed site's search data.
		( new Search_Settings() )->register_hooks();

		// Keep the cache purge for the remaining brand sites off the network.
		$block_http = static fn () => new \WP_Error( 'http_request_blocked', 'Blocked in tests.' );
		add_filter( 'pre_http_request', $block_http );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-site' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://brand-one.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'brand-one-key' );

		$response = $this->server->dispatch( $request );

		remove_filter( 'pre_http_request', $block_http );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( [ 'https://brand-two.example.com/' ], array_keys( Settings::get_shared_sites() ) );

		$this->assertSame(
			[ 'https://brand-two.example.com/' => [ 'post' ] ],
			Search_Settings::get_indexable_entities()['entities']
		);

		$delete_filters = [];
		foreach ( $algolia_requests as $algolia_request ) {
			if ( str_contains( $algolia_request['path'], '/deleteByQuery' ) ) {
				$delete_filters[] = json_decode( $algolia_request['body'], true )['filters'] ?? '';
			}
		}
		$this->assertCount( 1, $delete_filters );
		$this->assertStringContainsString( 'site_url:"https://brand-one.example.com/"', $delete_filters[0] );
		$this->assertStringNotContainsString( 'brand-two', $delete_filters[0] );
	}

	/**
	 * A brand site cannot remove itself without its own token.
	 */
	public function test_remove_brand_site_rejects_wrong_token(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example.com',
					'api_key' => 'brand-one-key',
				],
			]
		);

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-site' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://brand-one.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'wrong-key' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertCount( 1, Settings::get_shared_sites() );
	}

	/**
	 * Only a brand site can remove itself, so a logged-in admin (from set_up) without its key is refused.
	 */
	public function test_remove_brand_site_refuses_an_admin_without_a_brand_key(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example.com',
					'api_key' => 'brand-one-key',
				],
			]
		);

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-site' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		$this->assertCount( 1, Settings::get_shared_sites() );
	}
}
