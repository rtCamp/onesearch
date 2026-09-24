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
	 * @param array<int, string>                                 $recorded_paths Paths captured from outgoing SDK requests.
	 * @param (callable(string): string)|null                    $body_for_path Optional callback to provide a response body for a given request path.
	 * @param string|null                                        $throw_on_path_segment Optional path segment that triggers a RuntimeException when matched.
	 * @param array<int, array{path: string, body: string}>|null $recorded_requests Optional collector for the full request path and payload.
	 */
	public function mock_algolia_http_client( array &$recorded_paths, ?callable $body_for_path = null, ?string $throw_on_path_segment = null, ?array &$recorded_requests = null ): void {
		\OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia::setHttpClient(
			new class( $recorded_paths, $body_for_path, $throw_on_path_segment, $recorded_requests ) implements \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\HttpClientInterface {
				/** @var array<int, string> */
				private array $paths;

				/** @var (callable(string): string)|null */
				private $body_for_path;

				/** @var string|null */
				private ?string $throw_on_path_segment;

				/** @var array<int, array{path: string, body: string}>|null */
				private ?array $requests;

				/**
				 * @param array<int, string>                                 $paths Reference to the array that records intercepted request paths.
				 * @param (callable(string): string)|null                    $body_for_path Optional callback to generate mock response bodies.
				 * @param string|null                                        $throw_on_path_segment Optional path segment that triggers a RuntimeException when matched.
				 * @param array<int, array{path: string, body: string}>|null $requests Reference to the array that records intercepted paths with their payloads.
				 */
				public function __construct( array &$paths, ?callable $body_for_path, ?string $throw_on_path_segment, ?array &$requests ) {
					$this->paths                 = &$paths;
					$this->body_for_path         = $body_for_path;
					$this->throw_on_path_segment = $throw_on_path_segment;
					$this->requests              = &$requests;
				}

				/**
				 * {@inheritDoc}
				 *
				 * @param \Psr\Http\Message\RequestInterface $request         The PSR-7 request.
				 * @param mixed                              $timeout         Request timeout.
				 * @param mixed                              $connect_timeout Connection timeout.
				 * @throws \RuntimeException When the configured path segment is encountered.
				 */
				public function sendRequest( \Psr\Http\Message\RequestInterface $request, mixed $timeout, mixed $connect_timeout ): \Psr\Http\Message\ResponseInterface { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
					$path          = (string) $request->getUri()->getPath();
					$this->paths[] = $path;

					if ( null !== $this->requests ) {
						$this->requests[] = [
							'path' => $path,
							'body' => (string) $request->getBody(),
						];
					}

					if ( null !== $this->throw_on_path_segment && str_contains( $path, $this->throw_on_path_segment ) ) {
						throw new \RuntimeException( 'forced test exception' );
					}

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

	/**
	 * Reads the `site_post_id` the intercepted records were actually written with.
	 *
	 * Asserting against the stored value, rather than rebuilding it, is what makes
	 * a drift between the write format and the delete filter visible.
	 *
	 * @see \OneSearch\Modules\Search\Post_Record::get_site_post_id()
	 *
	 * @param array<int, array{path: string, body: string}> $requests The intercepted requests.
	 * @param int                                           $expected_count How many distinct IDs the write traffic should carry.
	 *
	 * @return list<string> The distinct site_post_id values, in the order first seen.
	 */
	protected function get_indexed_site_post_ids( array $requests, int $expected_count = 1 ): array {
		$ids = [];

		foreach ( $requests as $request ) {
			if ( ! str_contains( $request['path'], '/batch' ) ) {
				continue;
			}

			$body = json_decode( $request['body'], true );
			foreach ( $body['requests'] ?? [] as $operation ) {
				if ( isset( $operation['body']['site_post_id'] ) ) {
					$ids[] = (string) $operation['body']['site_post_id'];
				}
			}
		}

		$ids = array_values( array_unique( $ids ) );
		$this->assertCount( $expected_count, $ids, 'Unexpected number of site_post_id values in the write traffic.' );

		return $ids;
	}

	/**
	 * Reads the single `site_post_id` the intercepted records were written with.
	 *
	 * @param array<int, array{path: string, body: string}> $requests The intercepted requests.
	 */
	protected function get_indexed_site_post_id( array $requests ): string {
		return $this->get_indexed_site_post_ids( $requests )[0];
	}

	/**
	 * Collects the `filters` argument of every deleteByQuery request that was sent.
	 *
	 * @param array<int, array{path: string, body: string}> $requests The intercepted requests.
	 *
	 * @return list<string>
	 */
	protected function get_delete_filters( array $requests ): array {
		$filters = [];

		foreach ( $requests as $request ) {
			if ( ! str_contains( $request['path'], '/deleteByQuery' ) ) {
				continue;
			}

			$body = json_decode( $request['body'], true );
			if ( is_array( $body ) && isset( $body['filters'] ) ) {
				$filters[] = (string) $body['filters'];
			}
		}

		return $filters;
	}

	/**
	 * Configures the current site as a governing site with credentials and indexable entities.
	 *
	 * @param string[] $entities The indexable post types.
	 */
	protected function set_up_governing_site( array $entities = [ 'post' ] ): void {
		update_option( \OneSearch\Modules\Settings\Settings::OPTION_SITE_TYPE, \OneSearch\Modules\Settings\Settings::SITE_TYPE_GOVERNING );
		\OneSearch\Modules\Search\Settings::set_algolia_credentials(
			[
				'app_id'    => 'test-app',
				'write_key' => 'test-key',
			]
		);
		update_option(
			\OneSearch\Modules\Search\Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					\OneSearch\Utils::normalize_url( get_site_url() ) => $entities,
				],
			]
		);
	}

	/**
	 * Restores the Algolia and site-type state that set_up_governing_site() changed.
	 */
	protected function tear_down_governing_site(): void {
		\OneSearch\Vendor\Algolia\AlgoliaSearch\Algolia::resetHttpClient();

		delete_option( \OneSearch\Modules\Settings\Settings::OPTION_SITE_TYPE );
		delete_option( \OneSearch\Modules\Search\Settings::OPTION_GOVERNING_INDEXABLE_SITES );
		\OneSearch\Modules\Search\Settings::set_algolia_credentials(
			[
				'app_id'    => '',
				'write_key' => '',
			]
		);
	}
}
