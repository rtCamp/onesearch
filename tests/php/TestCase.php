<?php
/**
 * Provide a base class for all unit tests by extending WP_UnitTestCase.
 *
 * @package OneSearch\Tests
 */

declare( strict_types = 1 );

namespace OneSearch\Tests;

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
	 * @param array<int, string>              $recorded_paths Paths captured from outgoing SDK requests.
	 * @param (callable(string): string)|null $body_for_path Optional callback to provide a response body for a given request path.
	 */
	public function mock_algolia_http_client( array &$recorded_paths, ?callable $body_for_path = null ): void {
		\OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia::setHttpClient(
			new class( $recorded_paths, $body_for_path ) implements \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\HttpClientInterface {
				/** @var array<int, string> */
				private array $paths;

				/** @var (callable(string): string)|null */
				private $body_for_path;

				/**
				 * @param array<int, string>              $paths Reference to the array that records intercepted request paths.
				 * @param (callable(string): string)|null $body_for_path Optional callback to generate mock response bodies.
				 */
				public function __construct( array &$paths, ?callable $body_for_path ) {
					$this->paths         = &$paths;
					$this->body_for_path = $body_for_path;
				}

				/**
				 * {@inheritDoc}
				 *
				 * @param \OneSearch\Vendor\Psr\Http\Message\RequestInterface $request         The PSR-7 request.
				 * @param mixed                                               $timeout         Request timeout.
				 * @param mixed                                               $connect_timeout Connection timeout.
				 */
				public function sendRequest( \OneSearch\Vendor\Psr\Http\Message\RequestInterface $request, mixed $timeout, mixed $connect_timeout ): \OneSearch\Vendor\Psr\Http\Message\ResponseInterface { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
					$path          = (string) $request->getUri()->getPath();
					$this->paths[] = $path;

					if ( null !== $this->body_for_path ) {
						$body = (string) call_user_func( $this->body_for_path, $path );
					} elseif ( str_contains( $path, '/task/' ) ) {
						$body = '{"status":"published","pendingTask":false}';
					} elseif ( str_contains( $path, '/query' ) ) {
						$body = '{"hits":[{"objectID":"1"}],"nbHits":1,"page":0,"hitsPerPage":20}';
					} else {
						$body = '{"taskID":1,"updatedAt":"2024-01-01T00:00:00.000Z"}';
					}

					// @phpstan-ignore return.type
					return new \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\Psr7\Response( 200, [], $body );
				}
			}
		);
	}

	// Add any common setup or utility methods for tests here.
}
