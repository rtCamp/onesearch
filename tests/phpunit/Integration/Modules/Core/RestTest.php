<?php
/**
 * Rest unit tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Core
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Core;

use OneSearch\Modules\Core\Rest;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Rest core module.
 */
#[CoversClass( \OneSearch\Modules\Core\Rest::class )]
final class RestTest extends TestCase {
	/**
	 * Tests no errors on class instantiation.
	 */
	public function test_class_instantiation(): void {
		$rest = new Rest();

		$rest->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests that the OneSearch token header is added once.
	 */
	public function test_allowed_cors_headers_adds_OneSearch_token_once(): void {
		$rest = new Rest();

		$this->assertSame(
			[ 'X-WP-Nonce', 'X-OneSearch-Token', 'X-OneSearch-Site-URL' ],
			$rest->allowed_cors_headers( [ 'X-WP-Nonce' ] ),
			'Token should be added to headers'
		);

		$this->assertSame(
			[ 'X-OneSearch-Token', 'X-OneSearch-Site-URL' ],
			$rest->allowed_cors_headers( [ 'X-OneSearch-Token', 'X-OneSearch-Site-URL' ] ),
			'Token should not be readded'
		);
	}

	/**
	 * Only the missing custom header is appended when one is already present,
	 * so the returned list never contains duplicates.
	 */
	public function test_allowed_cors_headers_appends_only_missing_header(): void {
		$rest = new Rest();

		$this->assertSame(
			[ 'X-OneSearch-Token', 'X-OneSearch-Site-URL' ],
			$rest->allowed_cors_headers( [ 'X-OneSearch-Token' ] ),
			'Only the missing site-URL header should be appended'
		);

		$this->assertSame(
			[ 'X-OneSearch-Site-URL', 'X-OneSearch-Token' ],
			$rest->allowed_cors_headers( [ 'X-OneSearch-Site-URL' ] ),
			'Only the missing token header should be appended'
		);
	}
}
