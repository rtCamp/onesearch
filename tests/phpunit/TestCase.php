<?php
/**
 * Provide a base class for all unit tests by extending WP_UnitTestCase.
 *
 * @package OneSearch\Tests
 */

declare( strict_types = 1 );

namespace OneSearch\Tests;

use OneSearch\Modules\Rest\Remote_Client;
use OneSearch\Modules\Search\Jobs\Jobs;
use OneSearch\Modules\Search\Settings as Search_Settings;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
use WP_UnitTestCase;

/**
 * Class - TestCase
 */
abstract class TestCase extends WP_UnitTestCase {
	/**
	 * The records in the fake Algolia index, keyed by object ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $algolia_records = [];

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
		$http_client = new class( $recorded_paths, $body_for_path, $throw_on_path_segment, $recorded_requests ) implements \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\HttpClientInterface {
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
			 * @param \OneSearch\Vendor\Psr\Http\Message\RequestInterface $request         The PSR-7 request.
			 * @param mixed                                               $timeout         Request timeout.
			 * @param mixed                                               $connect_timeout Connection timeout.
			 * @throws \RuntimeException When the configured path segment is encountered.
			 */
			public function sendRequest( \OneSearch\Vendor\Psr\Http\Message\RequestInterface $request, mixed $timeout, mixed $connect_timeout ): \OneSearch\Vendor\Psr\Http\Message\ResponseInterface { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
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
		};

		add_filter( 'onesearch_algolia_http_client', static fn () => $http_client );
	}

	/**
	 * Fakes an Algolia index that keeps its records in `$this->algolia_records`, and understands the filters the plugin deletes by.
	 *
	 * @param ?string $fail_path A path segment whose requests fail.
	 */
	protected function fake_algolia_index( ?string $fail_path = null ): void {
		$records = &$this->algolia_records;

		$http_client = new class( $records, $fail_path ) implements \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\HttpClientInterface {
			/** @var array<string, array<string, mixed>> */
			private array $records;

			/** @var ?string */
			private ?string $fail_path;

			/**
			 * @param array<string, array<string, mixed>> $records   The records.
			 * @param ?string                             $fail_path A path segment whose requests fail.
			 */
			public function __construct( array &$records, ?string $fail_path ) {
				$this->records   = &$records;
				$this->fail_path = $fail_path;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param \OneSearch\Vendor\Psr\Http\Message\RequestInterface $request         The PSR-7 request.
			 * @param mixed                                               $timeout         Request timeout.
			 * @param mixed                                               $connect_timeout Connection timeout.
			 * @throws \RuntimeException When the request should fail.
			 */
			public function sendRequest( \OneSearch\Vendor\Psr\Http\Message\RequestInterface $request, mixed $timeout, mixed $connect_timeout ): \OneSearch\Vendor\Psr\Http\Message\ResponseInterface { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$path = $request->getUri()->getPath();
				$body = json_decode( (string) $request->getBody(), true );

				if ( null !== $this->fail_path && str_contains( $path, $this->fail_path ) ) {
					throw new \RuntimeException( 'forced test exception' );
				}

				if ( str_ends_with( $path, '/batch' ) ) {
					foreach ( $body['requests'] as $operation ) {
						$this->records[ $operation['body']['objectID'] ] = $operation['body'];
					}
				} elseif ( str_ends_with( $path, '/deleteByQuery' ) ) {
					$this->records = array_filter( $this->records, fn ( array $record ): bool => ! $this->matches( $record, $body['filters'] ) );
				} elseif ( 'DELETE' === $request->getMethod() && 1 === preg_match( '#^/1/indexes/[^/]+$#', $path ) ) {
					$this->records = [];
				}

				$response = str_contains( $path, '/task/' ) ? '{"status":"published"}' : '{"taskID":1,"hits":[]}';

				// @phpstan-ignore return.type
				return new \OneSearch\Vendor\Algolia\AlgoliaSearch\Http\Psr7\Response( 200, [], $response );
			}

			/**
			 * Whether a record matches a filter of `attribute:"value"`, `attribute < number` and `attribute >= number` terms, joined with OR inside AND. Values match case-insensitively, like Algolia's.
			 *
			 * @param array<string, mixed> $record  The record.
			 * @param string               $filters The filter.
			 */
			private function matches( array $record, string $filters ): bool {
				foreach ( explode( ' AND ', $filters ) as $clause ) {
					$any = false;

					foreach ( explode( ' OR ', trim( $clause, '()' ) ) as $term ) {
						if ( preg_match( '/^(\w+):"(.*)"$/', $term, $m ) ) {
							$any = $any || 0 === strcasecmp( (string) ( $record[ $m[1] ] ?? '' ), stripslashes( $m[2] ) );
						} elseif ( preg_match( '/^(\w+) (<|>=) (\d+)$/', $term, $m ) && isset( $record[ $m[1] ] ) ) {
							$any = $any || ( '<' === $m[2] ? $record[ $m[1] ] < (int) $m[3] : $record[ $m[1] ] >= (int) $m[3] );
						}
					}

					if ( ! $any ) {
						return false;
					}
				}

				return true;
			}
		};

		add_filter( 'onesearch_algolia_http_client', static fn () => $http_client );
	}

	/**
	 * Adds a record to the fake index, saved an hour ago.
	 *
	 * @param string $object_id The record's object ID.
	 * @param string $site_url  The site the record belongs to.
	 */
	protected function add_algolia_record( string $object_id, string $site_url ): void {
		$this->algolia_records[ $object_id ] = [
			'objectID'   => $object_id,
			'site_url'   => $site_url,
			'indexed_at' => time() - HOUR_IN_SECONDS,
		];
	}

	/**
	 * Gets the IDs of the posts with records in the fake index.
	 *
	 * @return int[]
	 */
	protected function get_algolia_post_ids(): array {
		$post_ids = array_unique( array_column( $this->algolia_records, 'post_id' ) );
		sort( $post_ids );

		return $post_ids;
	}

	/**
	 * Makes a post's shared record data leave no room for content, so it's too big to index.
	 *
	 * @param int $post_id The post ID.
	 */
	protected function make_too_big_to_index( int $post_id ): void {
		add_filter(
			'onesearch_algolia_base_record_data',
			static fn ( array $record, \WP_Post $post ): array => $post->ID === $post_id ? [ 'padding' => str_repeat( 'x', 10000 ) ] + $record : $record,
			10,
			2
		);
	}

	/**
	 * Runs the plugin's queued actions the way Action Scheduler does, along with the actions they queue.
	 */
	protected function run_jobs(): void {
		// Enough rounds for any chain the tests queue.
		for ( $round = 0; $round < 50; ++$round ) {
			$action_ids = as_get_scheduled_actions(
				[
					'group'    => 'onesearch',
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
					'orderby'  => 'action_id',
					'order'    => 'ASC',
				],
				'ids'
			);

			if ( [] === $action_ids ) {
				return;
			}

			foreach ( $action_ids as $action_id ) {
				\ActionScheduler::runner()->process_action( (int) $action_id, 'PHPUnit' );
			}
		}

		$this->fail( 'The jobs kept queueing more jobs.' );
	}

	/**
	 * Runs the oldest of the plugin's actions waiting to run.
	 */
	protected function run_oldest_action(): void {
		$this->run_waiting_action( 'ASC' );
	}

	/**
	 * Runs the newest of the plugin's actions waiting to run.
	 */
	protected function run_newest_action(): void {
		$this->run_waiting_action( 'DESC' );
	}

	/**
	 * Runs the first of the plugin's actions waiting to run, in an order.
	 *
	 * @param string $order ASC for the oldest, or DESC for the newest.
	 */
	private function run_waiting_action( string $order ): void {
		$action_ids = as_get_scheduled_actions(
			[
				'group'    => 'onesearch',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'action_id',
				'order'    => $order,
			],
			'ids'
		);

		$this->assertNotEmpty( $action_ids, 'No action is waiting to run.' );

		\ActionScheduler::runner()->process_action( (int) reset( $action_ids ), 'PHPUnit' );
	}

	/**
	 * Gets the statuses of one of the plugin's jobs.
	 *
	 * @param string $job The job's name.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function get_statuses_of( string $job ): array {
		return array_values( array_filter( Jobs::registry()->get_statuses(), static fn ( array $status ): bool => $job === $status['job'] ) );
	}

	/**
	 * Configures the current site as a governing site with credentials and indexable entities.
	 *
	 * @param string[] $entities The indexable post types.
	 */
	protected function set_up_governing_site( array $entities = [ 'post' ] ): void {
		// Name the index after a known host, since the test site's URL depends on the environment.
		add_filter( 'option_home', static fn (): string => 'https://governing.example.org', 11 );
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Search_Settings::set_algolia_credentials(
			[
				'app_id'    => 'test-app',
				'write_key' => 'test-key',
			]
		);
		update_option(
			Search_Settings::OPTION_GOVERNING_INDEXABLE_SITES,
			[
				'entities' => [
					Utils::get_current_site_url() => $entities,
				],
			]
		);
	}

	/**
	 * Configures the current site as a brand site with a cached brand config.
	 *
	 * @param string[] $entities The indexable post types.
	 */
	protected function set_up_brand_site( array $entities = [ 'post' ] ): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		update_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://governing.example.com' );

		$method = new \ReflectionMethod( Remote_Client::class, 'set_brand_config_cache' );
		$method->invoke(
			null,
			[
				'algolia_credentials' => [
					'app_id'    => 'test-app',
					'write_key' => 'test-key',
				],
				'search_settings'     => [
					'algolia_enabled'  => true,
					'searchable_sites' => [],
				],
				'indexable_entities'  => $entities,
				'available_sites'     => [],
			]
		);
	}
}
