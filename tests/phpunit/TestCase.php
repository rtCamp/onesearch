<?php
/**
 * Provide a base class for all unit tests by extending WP_UnitTestCase.
 *
 * @package OneSearch\Tests
 */

declare( strict_types = 1 );

namespace OneSearch\Tests;

use OneSearch\Tests\Support\Mock_Algolia_Http_Client;
use WP_UnitTestCase;

/**
 * Class - TestCase
 */
abstract class TestCase extends WP_UnitTestCase {
	/**
	 * {@inheritDoc}
	 *
	 * Prevents wp-phpunit failures with PHPUnit 11.5.
	 *
	 * @return array<string, array<string, list<string>>>
	 */
	public function getAnnotations(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Required compatibility method name.
		$class_reflection  = new \ReflectionClass( static::class );
		$method_name       = method_exists( $this, 'name' ) ? $this->name() : $this->getName( false );
		$method_reflection = $class_reflection->hasMethod( $method_name )
			? $class_reflection->getMethod( $method_name )
			: null;

		return [
			'class'  => self::parse_docblock_annotations( $class_reflection->getDocComment() ?: '' ),
			'method' => self::parse_docblock_annotations( $method_reflection?->getDocComment() ?: '' ),
		];
	}

	/**
	 * Parse selected docblock tags used in WP unit testing expectations.
	 *
	 * @param string $docblock Source docblock.
	 *
	 * @return array<string, list<string>>
	 */
	private static function parse_docblock_annotations( string $docblock ): array {
		if ( '' === trim( $docblock ) ) {
			return [];
		}

		$annotations = [];
		$tags        = [
			'ticket',
			'group',
			'expectedDeprecated',
			'expectedIncorrectUsage',
		];

		foreach ( $tags as $tag ) {
			$matches = [];
			preg_match_all( '/^[ \\t\\*]*@' . preg_quote( $tag, '/' ) . '\\s+([^\\r\\n\\*]+)/mi', $docblock, $matches );

			if ( ! empty( $matches[1] ) ) {
				$annotations[ $tag ] = array_values(
					array_filter(
						array_map( 'trim', $matches[1] ),
						static fn ( string $value ): bool => '' !== $value
					)
				);
			}
		}

		return $annotations;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @deprecated
	 */
	protected function checkRequirements(): void { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
		parent::checkRequirements();
	}

	/**
	 * Intercept Algolia SDK HTTP calls and collect request paths.
	 *
	 * The double itself lives in `Mock_Algolia_Http_Client` so the Playwright
	 * suite's helper mu-plugin can install the same one, and Algolia's response
	 * shapes stay defined in a single place.
	 *
	 * @param array<int, string>              $recorded_paths Paths captured from outgoing SDK requests.
	 * @param (callable(string): string)|null $body_for_path Optional callback to provide a response body for a given request path.
	 * @param string|null                     $throw_on_path_segment Optional path segment that triggers a RuntimeException when matched.
	 */
	public function mock_algolia_http_client( array &$recorded_paths, ?callable $body_for_path = null, ?string $throw_on_path_segment = null ): void {
		\OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia::setHttpClient(
			new Mock_Algolia_Http_Client( $recorded_paths, $body_for_path, $throw_on_path_segment )
		);
	}

	// Add any common setup or utility methods for tests here.
}
