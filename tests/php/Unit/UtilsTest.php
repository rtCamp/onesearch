<?php
/**
 * Utils unit tests.
 *
 * @package OneSearch\Tests\Unit
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit;

use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class UtilsTest
 */
#[CoversClass( \OneSearch\Utils::class )]
final class UtilsTest extends TestCase {
	/**
	 * Ensures provider cases normalize as expected.
	 *
	 * @param string $input    Raw input URL.
	 * @param string $expected Expected normalized URL.
	 */
	#[DataProvider( 'normalize_url_provider' )]
	public function test_normalize_url_with_data_provider( string $input, string $expected ): void {
		$this->assertSame( $expected, Utils::normalize_url( $input ) );
	}

	/**
	 * Provides URL normalization cases.
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function normalize_url_provider(): array {
		return [
			'adds trailing slash'  => [
				'https://example.com',
				'https://example.com/',
			],
			'trims whitespace'     => [
				"\thttps://example.com/path \n",
				'https://example.com/path/',
			],
			'keeps trailing slash' => [
				'https://example.com/path/',
				'https://example.com/path/',
			],
			'query string'         => [
				'https://example.com?foo=bar',
				'https://example.com?foo=bar/',
			],
			'empty string'         => [
				'',
				'/',
			],
		];
	}
}
