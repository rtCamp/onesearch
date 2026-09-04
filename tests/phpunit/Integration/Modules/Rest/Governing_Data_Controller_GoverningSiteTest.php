<?php
/**
 * Tests for Governing_Data_Controller on a governing site.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Governing_Data_Controller;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Governing-site scenarios for {@see Governing_Data_Controller}.
 *
 * Site type is fixed in {@see set_up()} so route registration happens once,
 * mirroring how the controller boots in production.
 */
#[CoversClass( Governing_Data_Controller::class )]
#[CoversClass( Abstract_REST_Controller::class )]
class Governing_Data_Controller_GoverningSiteTest extends TestCase {
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

		( new Governing_Data_Controller() )->register_hooks();

		/*
		 * Registering the shared-sites listener keeps these tests on the production
		 * disconnect path, since that is what notifies a dropped brand site.
		 */
		( new Settings() )->register_hooks();

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
	 * Governing site registers brand-config GET and all-post-types.
	 */
	public function test_registers_brand_config_get_and_all_post_types(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Governing_Data_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/brand-config', $routes );
		$this->assertArrayHasKey( 'GET', $routes[ $ns . '/brand-config' ][0]['methods'] );
		$this->assertArrayHasKey( $ns . '/all-post-types', $routes );
	}

	/**
	 * No origin -> auth layer falls back to manage_options (admin is set in set_up),
	 * so the request reaches the controller which itself rejects the empty origin.
	 */
	public function test_get_brand_config_rejects_empty_origin(): void {
		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'onesearch_unauthorized_site', $response->get_data()['code'] );
	}

	/**
	 * The cross-site auth layer requires a valid X-OneSearch-Token for any non-same-host
	 * origin; an unknown origin cannot present one, so the request is rejected before
	 * the controller runs.
	 */
	public function test_get_brand_config_rejects_unknown_origin(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Known Site',
					'url'     => 'https://known.example.com',
					'api_key' => 'key-known',
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'origin', 'https://unknown.example.com' );

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

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'origin', $site_url );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
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

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'origin', 'https://brand.example.com' );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['search_settings']['algolia_enabled'] );
		$this->assertSame( [], $data['search_settings']['searchable_sites'] );
	}

	/**
	 * Token auth: no Origin but a valid X-OneSearch-Site-URL fallback header
	 * resolves the site key and authorizes the request. Current user is logged
	 * out to prove success comes from the token, not the manage_options fallback.
	 */
	public function test_token_auth_succeeds_with_site_url_header_and_no_origin(): void {
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

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$request->set_header( 'X-OneSearch-Site-URL', $site_url );

		$this->assertTrue( ( new Governing_Data_Controller() )->check_api_permissions( $request ) );
	}

	/**
	 * Token auth: a token with neither Origin nor X-OneSearch-Site-URL cannot
	 * resolve a requesting site and must be rejected outright — it must not fall
	 * back to the manage_options check even though an admin is logged in.
	 */
	public function test_token_auth_fails_without_origin_or_site_url_header(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => 'https://brand.example.com/',
					'api_key' => 'brand-token',
				],
			]
		);

		// Admin is logged in from set_up(); the assertFalse proves the fallback is NOT reached.
		$this->assertTrue( current_user_can( 'manage_options' ) );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'X-OneSearch-Token', 'brand-token' );

		$this->assertFalse( ( new Governing_Data_Controller() )->check_api_permissions( $request ) );
	}

	/**
	 * Token auth: the X-OneSearch-Site-URL fallback resolves the site but the token
	 * itself is still validated. A wrong token is rejected even though an admin is
	 * logged in — guarding against the fallback authorizing on header presence alone.
	 */
	public function test_token_auth_fails_with_wrong_token_via_site_url_fallback(): void {
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

		// Admin is logged in from set_up(); a wrong token must not slip through.
		$this->assertTrue( current_user_can( 'manage_options' ) );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/brand-config' );
		$request->set_header( 'X-OneSearch-Token', 'wrong-token' );
		$request->set_header( 'X-OneSearch-Site-URL', $site_url );

		$this->assertFalse( ( new Governing_Data_Controller() )->check_api_permissions( $request ) );
	}

	/**
	 * Returns an `errors` entry for shared sites missing required configuration.
	 */
	public function test_get_all_post_types_reports_errors_for_bad_sites(): void {
		Settings::set_shared_sites(
			[
				[
					'name' => 'Test Site',
					'url'  => 'https://test.example.com',
					// Missing api_key.
				],
			]
		);

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/all-post-types' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$site_url = trailingslashit( get_site_url() );
		$this->assertArrayHasKey( $site_url, $data['sites'] );
		$this->assertNotEmpty( $data['errors'] );
	}

	/**
	 * The governing site exposes the connection endpoint brand sites deregister through.
	 */
	public function test_registers_connection_delete_route(): void {
		$routes = $this->server->get_routes();
		$ns     = '/' . Governing_Data_Controller::NAMESPACE;

		$this->assertArrayHasKey( $ns . '/connection', $routes );
		$this->assertArrayHasKey( 'DELETE', $routes[ $ns . '/connection' ][0]['methods'] );
	}

	/**
	 * A brand site deregistering itself is dropped from the shared sites list.
	 */
	public function test_remove_brand_site_drops_the_requesting_site(): void {
		$api_key = 'brand-key';
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => 'https://brand.example.com',
					'api_key' => $api_key,
				],
				[
					'name'    => 'Other Site',
					'url'     => 'https://other.example.com',
					'api_key' => 'other-key',
				],
			]
		);

		$requested_urls = [];
		$filter         = static function ( $preempt, $args, $url ) use ( &$requested_urls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			$requested_urls[] = $url;
			return new \WP_Error( 'blocked', 'Intercepted' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/connection' );
		$request->set_header( 'origin', 'https://brand.example.com' );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$response = $this->server->dispatch( $request );

		remove_filter( 'pre_http_request', $filter );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( [ 'https://other.example.com/' ], array_keys( Settings::get_shared_sites() ) );
		$this->assertEmpty( $requested_urls, 'A site that disconnected itself should not be notified back.' );

		/*
		 * Removal rewrites the list from keys it just decrypted, so it has to re-encrypt what
		 * it keeps. Only the stored value shows whether it did: a plaintext key decrypts to
		 * itself, so one left in the clear still looks correct on the way back out.
		 */
		$stored = get_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		$this->assertNotSame(
			'other-key',
			$stored[0]['api_key'],
			'The surviving brand site key must stay encrypted at rest.'
		);
		$this->assertSame(
			'other-key',
			Settings::get_shared_sites()['https://other.example.com/']['api_key'],
			'...and must still decrypt back to the original key.'
		);
	}

	/**
	 * Removal is scoped to the caller: it cannot deregister a different brand site.
	 */
	public function test_remove_brand_site_only_removes_the_caller(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => 'https://brand.example.com',
					'api_key' => 'brand-key',
				],
				[
					'name'    => 'Other Site',
					'url'     => 'https://other.example.com',
					'api_key' => 'other-key',
				],
			]
		);

		// Valid key, but presented from a site it does not belong to.
		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/connection' );
		$request->set_header( 'origin', 'https://other.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'brand-key' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 2, Settings::get_shared_sites() );
	}

	/**
	 * An unregistered site cannot deregister anything.
	 */
	public function test_remove_brand_site_rejects_unknown_site(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => 'https://brand.example.com',
					'api_key' => 'brand-key',
				],
			]
		);

		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/connection' );
		$request->set_header( 'origin', 'https://stranger.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'brand-key' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 1, Settings::get_shared_sites() );
	}

	/**
	 * Removing a site that is already gone reports success rather than an error.
	 */
	public function test_remove_brand_site_is_idempotent(): void {
		Settings::set_shared_sites( [] );

		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/connection' );
		$request->set_header( 'origin', 'https://brand.example.com' );

		$response = ( new Governing_Data_Controller() )->remove_brand_site( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/**
	 * A request that carries no identifiable origin is rejected.
	 */
	public function test_remove_brand_site_rejects_unidentifiable_origin(): void {
		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/connection' );

		$response = ( new Governing_Data_Controller() )->remove_brand_site( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'onesearch_unknown_site', $response->get_error_code() );
	}

	/**
	 * Deregistration writes the shared sites option, which is what puts the removed site
	 * in front of the listeners that purge its records and indexable entities.
	 */
	public function test_remove_brand_site_triggers_shared_sites_cleanup(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => 'https://brand.example.com',
					'api_key' => 'brand-key',
				],
			]
		);

		$removed_urls = [];
		$listener     = static function ( $old_value ) use ( &$removed_urls ): void {
			foreach ( $old_value as $site ) {
				$removed_urls[] = $site['url'];
			}
		};
		add_action( 'update_option_' . Settings::OPTION_GOVERNING_SHARED_SITES, $listener, 10, 1 );

		$request = new WP_REST_Request( 'DELETE', '/onesearch/v1/connection' );
		$request->set_header( 'origin', 'https://brand.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'brand-key' );

		$this->server->dispatch( $request );

		remove_action( 'update_option_' . Settings::OPTION_GOVERNING_SHARED_SITES, $listener, 10 );

		$this->assertSame( [ 'https://brand.example.com' ], $removed_urls );
	}
}
