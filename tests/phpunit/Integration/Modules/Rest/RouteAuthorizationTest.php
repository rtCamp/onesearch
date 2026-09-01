<?php
/**
 * Tests that privileged REST routes turn away an under-privileged user.
 *
 * @package OneSearch\Tests\Integration\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Rest\Basic_Options_Controller;
use OneSearch\Modules\Rest\Governing_Data_Controller;
use OneSearch\Modules\Rest\Search_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;

/**
 * Every route below is reachable only by a user who can `manage_options`,
 * either through an explicit capability check or through the fallback in
 * `Abstract_REST_Controller::check_api_permissions()`.
 *
 * The rest of the suite authenticates as an administrator, or as nobody at all
 * to exercise token auth. Neither covers what that fallback actually decides: a
 * signed-in user who lacks the capability.
 */
#[CoversClass( Abstract_REST_Controller::class )]
#[CoversClass( Basic_Options_Controller::class )]
#[CoversClass( Governing_Data_Controller::class )]
#[CoversClass( Search_Controller::class )]
class RouteAuthorizationTest extends TestCase {
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

		// Governing is the role with the largest route surface, so register as one.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		( new Basic_Options_Controller() )->register_hooks();
		( new Governing_Data_Controller() )->register_hooks();
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
	 * Every privileged route, as method, path, and a params payload.
	 *
	 * WordPress validates an endpoint's required args before it consults the
	 * permission callback, so a request missing them answers 400 and never
	 * reaches the check under test. Each route carries params good enough to
	 * get that far.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function privileged_route_provider(): array {
		return [
			'read site type'           => [ 'GET', '/site-type', [] ],
			'read brand sites'         => [ 'GET', '/shared-sites', [] ],
			'write brand sites'        => [ 'POST', '/shared-sites', [ 'sites_data' => [] ] ],
			'read own API key'         => [ 'GET', '/secret-key', [] ],
			'regenerate own API key'   => [ 'PUT', '/secret-key', [] ],
			'read governing site'      => [ 'GET', '/governing-site', [] ],
			'disconnect governing'     => [ 'DELETE', '/governing-site', [] ],
			'read Algolia creds'       => [ 'GET', '/algolia-credentials', [] ],
			'write Algolia creds'      => [
				'POST',
				'/algolia-credentials',
				[
					'app_id'    => 'SOMEAPPID',
					'write_key' => 'some-write-key',
				],
			],
			'read indexable entities'  => [ 'GET', '/indexable-entities', [] ],
			'write indexable entities' => [ 'POST', '/indexable-entities', [ 'entities' => [] ] ],
			'trigger a re-index'       => [ 'POST', '/re-index', [] ],
			'read brand config'        => [ 'GET', '/brand-config', [] ],
			'read all post types'      => [ 'GET', '/all-post-types', [] ],
		];
	}

	/**
	 * Dispatch a route with the given params.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below the plugin namespace.
	 * @param array<string, mixed> $params Request params.
	 */
	private function dispatch( string $method, string $route, array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Abstract_REST_Controller::NAMESPACE . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * An editor is signed in but cannot `manage_options`, so every route refuses.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below the plugin namespace.
	 * @param array<string, mixed> $params Request params.
	 */
	#[DataProvider( 'privileged_route_provider' )]
	public function test_route_is_forbidden_for_an_editor( string $method, string $route, array $params ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame(
			403,
			$this->dispatch( $method, $route, $params )->get_status(),
			sprintf( '%s %s should be forbidden for an editor.', $method, $route )
		);
	}

	/**
	 * A subscriber is the lowest built-in role, and is refused the same way.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below the plugin namespace.
	 * @param array<string, mixed> $params Request params.
	 */
	#[DataProvider( 'privileged_route_provider' )]
	public function test_route_is_forbidden_for_a_subscriber( string $method, string $route, array $params ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame(
			403,
			$this->dispatch( $method, $route, $params )->get_status(),
			sprintf( '%s %s should be forbidden for a subscriber.', $method, $route )
		);
	}

	/**
	 * Guard against a mistyped route in the provider.
	 *
	 * A missing route answers 404, so the tests above would fail for the wrong
	 * reason and read as a missing capability check. Asserted without
	 * dispatching, so nothing reaches Algolia.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below the plugin namespace.
	 * @param array<string, mixed> $params Request params, unused here.
	 */
	#[DataProvider( 'privileged_route_provider' )]
	public function test_route_is_registered( string $method, string $route, array $params ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		$registered = $this->server->get_routes()[ '/' . Abstract_REST_Controller::NAMESPACE . $route ] ?? [];

		$methods = [];
		foreach ( $registered as $handler ) {
			$methods = array_merge( $methods, array_keys( $handler['methods'] ?? [] ) );
		}

		$this->assertContains(
			$method,
			$methods,
			sprintf( '%s %s is not registered; the provider is out of date.', $method, $route )
		);
	}

	/**
	 * An editor cannot borrow a brand site's token to get past the capability check.
	 *
	 * Token auth is meant for server-to-server calls from a registered brand
	 * site. It resolves the caller by Origin, so a token presented from the
	 * governing site's own origin must not be accepted as one.
	 */
	public function test_editor_cannot_escalate_with_a_brand_site_token(): void {
		$api_key = 'brand-token-0123456789';
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site',
					'url'     => 'https://brand.example.com/',
					'api_key' => $api_key,
				],
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/' . Abstract_REST_Controller::NAMESPACE . '/algolia-credentials' );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$request->set_header( 'Origin', get_site_url() );

		$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * A logged-out request is refused too, so the routes are not merely
	 * capability-gated but authenticated.
	 */
	public function test_routes_are_closed_to_anonymous_requests(): void {
		wp_set_current_user( 0 );

		$this->assertContains(
			$this->dispatch( 'POST', '/shared-sites', [ 'sites_data' => [] ] )->get_status(),
			[ 401, 403 ]
		);
	}
}
