<?php
/**
 * Tests for Abstract_REST_Controller.
 *
 * @package OneSearch\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

use OneSearch\Modules\Rest\Abstract_REST_Controller;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Concrete implementation used exclusively for testing the abstract base.
 */
class Concrete_REST_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/test-route',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn () => rest_ensure_response( [ 'ok' => true ] ),
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Expose the protected method for direct testing.
	 *
	 * @param string   $url  URL to check.
	 * @param string   $host Host to compare.
	 * @param int|null $port Optional port.
	 */
	public function matches_host_url( string $url, string $host, ?int $port = null ): bool {
		return $this->is_url_from_host( $url, $host, $port );
	}
}

/**
 * Tests for the abstract base REST controller.
 */
#[CoversClass( Abstract_REST_Controller::class )]
class Abstract_REST_ControllerTest extends TestCase {
	/**
	 * Controller under test.
	 */
	private Concrete_REST_Controller $controller;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		$this->controller = new Concrete_REST_Controller();
	}

	// ── register_hooks ──────────────────────────────────────────────────

	/**
	 * Verify register_hooks adds the rest_api_init action.
	 */
	public function test_register_hooks_adds_rest_api_init_action(): void {
		$this->controller->register_hooks();
		$this->assertIsInt( has_action( 'rest_api_init', [ $this->controller, 'register_routes' ] ) );
	}

	// ── check_api_permissions: same-origin ──────────────────────────────

	/**
	 * Admin from same origin is allowed.
	 */
	public function test_check_api_permissions_allows_admin_from_same_origin(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );
		$request->set_header( 'origin', get_site_url() );

		$this->assertTrue( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Subscriber from same origin is denied (lacks manage_options).
	 */
	public function test_check_api_permissions_denies_subscriber_from_same_origin(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );
		$request->set_header( 'origin', get_site_url() );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Admin allowed when no origin header is set (treated as same-origin).
	 */
	public function test_check_api_permissions_allows_admin_when_no_origin_header(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );

		$this->assertTrue( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Unauthenticated user from same origin is denied.
	 */
	public function test_check_api_permissions_denies_unauthenticated_same_origin(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );
		$request->set_header( 'origin', get_site_url() );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
	}

	// ── check_api_permissions: cross-origin with token ──────────────────

	/**
	 * Cross-origin denied when X-OneSearch-Token header is missing.
	 */
	public function test_check_api_permissions_denies_when_cross_origin_token_is_missing(): void {
		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );
		$request->set_header( 'origin', 'https://other-site.example.com' );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Cross-origin denied when token does not match stored key.
	 */
	public function test_check_api_permissions_denies_when_cross_origin_token_is_wrong(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::regenerate_api_key();

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );
		$request->set_header( 'origin', 'https://other-site.example.com' );
		$request->set_header( 'X-OneSearch-Token', 'wrong-token' );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Cross-origin allowed for consumer site with matching token on non-healthcheck route
	 * when origin matches governing site URL.
	 */
	public function test_check_api_permissions_allows_consumer_with_valid_token_and_governing_origin(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$api_key = Settings::regenerate_api_key();

		// Set governing site URL to match the origin.
		Settings::set_parent_site_url( 'https://governing.example.com' );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );
		$request->set_header( 'origin', 'https://governing.example.com' );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$this->assertTrue( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Cross-origin denied for consumer site when origin doesn't match governing site URL.
	 */
	public function test_check_api_permissions_denies_consumer_when_origin_mismatches_governing(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$api_key = Settings::regenerate_api_key();

		Settings::set_parent_site_url( 'https://governing.example.com' );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );
		$request->set_header( 'origin', 'https://attacker.example.com' );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Cross-origin denied for consumer with valid token but no governing site configured.
	 */
	public function test_check_api_permissions_denies_consumer_when_no_governing_site_set(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$api_key = Settings::regenerate_api_key();

		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );
		$request->set_header( 'origin', 'https://other.example.com' );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Health-check sets governing site URL when used correct api_key and no governing site URL is configured.
	 */
	public function test_check_api_permissions_healthcheck_sets_parent_site_url(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$api_key = Settings::regenerate_api_key();

		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/health-check' );
		$request->set_header( 'origin', 'https://new-governing.example.com' );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$this->assertTrue( $this->controller->check_api_permissions( $request ) );
		$this->assertSame( 'https://new-governing.example.com', Settings::get_parent_site_url() );
	}

	/**
	 * Health-check fails to set governing site URL when used incorrect api_key.
	 */
	public function test_check_api_permissions_healthcheck_fails_to_set_parent_site_url(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$api_key = 'wrong-api-key';

		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/health-check' );
		$request->set_header( 'origin', 'https://new-governing.example.com' );
		$request->set_header( 'X-OneSearch-Token', $api_key );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
		$this->assertNotSame( 'https://new-governing.example.com', Settings::get_parent_site_url() );
	}

	/**
	 * Cross-origin allowed for governing site when token matches a shared site.
	 */
	public function test_check_api_permissions_allows_governing_with_valid_shared_site_token(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$shared_api_key = 'test-shared-key-' . wp_generate_password( 32, false );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Site A',
					'url'     => 'https://brand-a.example.com',
					'api_key' => $shared_api_key,
				],
			]
		);

		// Re-read the stored key for comparison.
		$shared_sites = Settings::get_shared_sites();
		$stored_key   = reset( $shared_sites )['api_key'];

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/test-route' );
		$request->set_header( 'origin', 'https://brand-a.example.com' );
		$request->set_header( 'X-OneSearch-Token', $stored_key );

		$this->assertTrue( $this->controller->check_api_permissions( $request ) );
	}

	// ── is_url_from_host ────────────────────────────────────────────────

	/**
	 * Matching host returns true.
	 */
	public function test_is_url_from_host_matches_same_host(): void {
		$this->assertTrue(
			$this->controller->matches_host_url( 'https://example.com/path', 'example.com' )
		);
	}

	/**
	 * Different host returns false.
	 */
	public function test_is_url_from_host_rejects_different_host(): void {
		$this->assertFalse(
			$this->controller->matches_host_url( 'https://example.com', 'other.com' )
		);
	}

	/**
	 * Port comparison succeeds and fails correctly.
	 */
	public function test_is_url_from_host_compares_port_when_provided(): void {
		$this->assertTrue(
			$this->controller->matches_host_url( 'https://example.com:8080', 'example.com', 8080 )
		);
		$this->assertFalse(
			$this->controller->matches_host_url( 'https://example.com:8080', 'example.com', 9090 )
		);
	}

	/**
	 * URL without port defaults to 80.
	 */
	public function test_is_url_from_host_defaults_port_to_80(): void {
		$this->assertTrue(
			$this->controller->matches_host_url( 'https://example.com', 'example.com', 80 )
		);
	}

	/**
	 * URL with no parseable host returns false.
	 */
	public function test_is_url_from_host_returns_false_for_invalid_url(): void {
		$this->assertFalse(
			$this->controller->matches_host_url( 'not-a-url', 'example.com' )
		);
	}
}
