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
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
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

		// The shared sites listeners that purge a removed site's search data and their associated jobs.
		( new Search_Settings() )->register_hooks();
		( new Indexer() )->register_hooks();

		// Keep the cache purge for the remaining brand sites off the network.
		$block_http = static fn () => new \WP_Error( 'http_request_blocked', 'Blocked in tests.' );
		add_filter( 'pre_http_request', $block_http );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/brand-site' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://brand-one.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'brand-one-key' );

		$response = $this->server->dispatch( $request );
		$this->run_jobs();

		remove_filter( 'pre_http_request', $block_http );

		$this->assertSame( 200, $response->get_status() );
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

	/**
	 * Governing site registers the brand-site/config GET route brand sites fetch their config from.
	 */
	public function test_registers_brand_site_config_get(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Brand_Site_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-site/config', $routes );
		$this->assertArrayHasKey( 'GET', $routes[ $ns . '/brand-site/config' ][0]['methods'] );
	}

	/**
	 * Only brand sites use brand-site/config, so a logged-in admin (from set_up) without a brand's key is refused.
	 */
	public function test_get_brand_config_refuses_an_admin_without_a_brand_key(): void {
		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/brand-site/config' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * A site that isn't listed is refused, even with a listed brand's key.
	 */
	public function test_get_brand_config_rejects_an_unlisted_site(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Known Site',
					'url'     => 'https://known.example.com',
					'api_key' => 'key-known',
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-site/config' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://unknown.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'key-known' );

		$response = $this->server->dispatch( $request );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	/**
	 * Returns full brand config for a known shared site presenting a valid token.
	 */
	public function test_get_brand_config_returns_config_for_known_site(): void {
		$site_url = 'https://brand.example.com/';
		$api_key  = 'the-key';
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => $site_url,
					'api_key' => $api_key,
				],
			]
		);

		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'TEST_APP',
				'write_key' => 'TEST_KEY',
			]
		);

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-site/config' );
		$request->set_header( 'X-OneSearch-Site-URL', $site_url );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'algolia_credentials', $data );
		$this->assertSame( 'TEST_APP', $data['algolia_credentials']['app_id'] );
		$this->assertArrayHasKey( 'search_settings', $data );
		$this->assertArrayHasKey( 'indexable_entities', $data );
		$this->assertArrayHasKey( 'available_sites', $data );
	}

	/**
	 * Returns default search settings when none configured for the requesting site.
	 */
	public function test_get_brand_config_returns_default_search_settings(): void {
		$api_key = 'brand-key';
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.example.com',
					'api_key' => $api_key,
				],
			]
		);

		delete_option( Search_Settings::OPTION_GOVERNING_SEARCH_SETTINGS );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-site/config' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://brand.example.com' );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['search_settings']['algolia_enabled'] );
		$this->assertSame( [], $data['search_settings']['searchable_sites'] );
	}

	/**
	 * A listed brand's X-OneSearch-Site-URL and key identify it. The user is logged out
	 * to prove success comes from the key.
	 */
	public function test_is_brand_request_with_site_url_header_and_key(): void {
		$site_url = 'https://brand.example.com/';
		$api_key  = 'brand-token';
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => $site_url,
					'api_key' => $api_key,
				],
			]
		);

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-site/config' );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$request->set_header( 'X-OneSearch-Site-URL', $site_url );

		$this->assertTrue( ( new Brand_Site_Controller() )->is_brand_request( $request ) );
	}

	/**
	 * A key without X-OneSearch-Site-URL can't identify the brand, even when Origin names it.
	 */
	public function test_is_not_brand_request_without_site_url_header(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => 'https://brand.example.com/',
					'api_key' => 'brand-token',
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-site/config' );
		$request->set_header( 'origin', 'https://brand.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'brand-token' );

		$this->assertFalse( ( new Brand_Site_Controller() )->is_brand_request( $request ) );
	}

	/**
	 * A listed brand's X-OneSearch-Site-URL with the wrong key isn't that brand.
	 */
	public function test_is_not_brand_request_with_wrong_key(): void {
		$site_url = 'https://brand.example.com/';
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => $site_url,
					'api_key' => 'brand-token',
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-site/config' );
		$request->set_header( 'X-OneSearch-Token', 'wrong-token' );
		$request->set_header( 'X-OneSearch-Site-URL', $site_url );

		$this->assertFalse( ( new Brand_Site_Controller() )->is_brand_request( $request ) );
	}

	/**
	 * The governing site lists itself in `available_sites` by its normalized identity URL.
	 */
	public function test_get_brand_config_lists_the_governing_site_by_its_normalized_url(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.example.com',
					'api_key' => 'brand-key',
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-site/config' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://brand.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'brand-key' );

		$data = $this->server->dispatch( $request )->get_data();

		$this->assertContains( Utils::get_current_site_url(), $data['available_sites'] );
		$this->assertContains( 'https://brand.example.com/', $data['available_sites'] );
	}

	/**
	 * A brand in a sub-directory is identified by the X-OneSearch-Site-URL path, since Origin drops it.
	 */
	public function test_get_brand_config_identifies_sub_directory_brand_from_site_url_header(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Sibling',
					'url'     => 'https://example.com/sibling',
					'api_key' => 'sibling-key',
				],
				[
					'name'    => 'Brand',
					'url'     => 'https://example.com/brand',
					'api_key' => 'brand-key',
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-site/config' );
		$request->set_header( 'X-OneSearch-Site-URL', 'https://example.com/brand/' );
		$request->set_header( 'X-OneSearch-Token', 'brand-key' );

		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );

		// The sibling's key must not authenticate the brand's path.
		$request->set_header( 'X-OneSearch-Token', 'sibling-key' );
		$this->assertGreaterThanOrEqual( 400, $this->server->dispatch( $request )->get_status() );
	}
}
