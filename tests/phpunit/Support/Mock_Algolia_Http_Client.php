<?php
/**
 * A stand-in for the Algolia SDK's HTTP transport.
 *
 * @package OneSearch\Tests\Support
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Support;

use OneSearch\Vendor\Algolia\AlgoliaSearch\Http\HttpClientInterface;
use OneSearch\Vendor\Algolia\AlgoliaSearch\Http\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers Algolia SDK requests from canned payloads.
 *
 * Algolia is the one boundary the test suites cannot keep real: it needs paid
 * credentials, it is a shared mutable index, and it fails for reasons unrelated
 * to the change under test. The SDK ships no test double of its own, so this
 * takes its place through `Algolia::setHttpClient()`.
 *
 * Installing it at the SDK's own seam means everything in front of Algolia
 * stays real — the plugin's REST routes, their permission and validation
 * callbacks, option writes, and the mapping from an SDK failure to an HTTP
 * status all still execute.
 *
 * Both suites share this class so the payload shapes are defined once:
 *
 * - PHPUnit, through `OneSearch\Tests\TestCase::mock_algolia_http_client()`,
 *   records the paths requested and can supply a body per path or force an
 *   exception.
 * - The Playwright suite, through the E2E helper mu-plugin, resolves a mode
 *   per request so a spec can choose the outcome it needs.
 *
 * It is autoloadable at WordPress runtime because `OneSearch\Tests\` is mapped
 * in Composer's `autoload` block, and it never ships: `.gitattributes` marks
 * `/tests/` as `export-ignore`.
 */
final class Mock_Algolia_Http_Client implements HttpClientInterface {
	/**
	 * The key can write, and indexing succeeds.
	 */
	public const MODE_OK = 'ok';

	/**
	 * The key resolves but lacks the ACL needed to write.
	 */
	public const MODE_INVALID_KEY = 'invalid_key';

	/**
	 * Algolia answers 500 for anything but a key lookup.
	 */
	public const MODE_SERVER_ERROR = 'server_error';

	/**
	 * Do not stand in for Algolia at all.
	 *
	 * This client never receives the mode — it tells whoever installs the
	 * client to leave the SDK's real transport alone. Only the opt-in smoke
	 * suite asks for it. It lives here so the vocabulary of modes has one home.
	 */
	public const MODE_LIVE = 'live';

	/**
	 * Every mode the modes resolver may return.
	 *
	 * @var list<string>
	 */
	public const MODES = [
		self::MODE_OK,
		self::MODE_INVALID_KEY,
		self::MODE_SERVER_ERROR,
		self::MODE_LIVE,
	];

	/**
	 * Paths requested so far, by reference so the caller can inspect them.
	 *
	 * @var array<int, string>
	 */
	private array $paths;

	/**
	 * Supplies a response body for a given path, when set.
	 *
	 * @var (callable(string): string)|null
	 */
	private $body_for_path;

	/**
	 * Path fragment that makes the request throw, when set.
	 */
	private ?string $throw_on_path_segment;

	/**
	 * Returns the mode for the current request, when set.
	 *
	 * @var (callable(): string)|null
	 */
	private $mode_resolver;

	/**
	 * @param array<int, string>              $recorded_paths        Reference to the array that records requested paths.
	 * @param (callable(string): string)|null $body_for_path         Optional body for a given path, which wins over every default.
	 * @param string|null                     $throw_on_path_segment Optional path fragment that triggers a RuntimeException.
	 * @param (callable(): string)|null       $mode_resolver         Optional mode lookup; without one every request behaves as `MODE_OK`.
	 */
	public function __construct(
		array &$recorded_paths,
		?callable $body_for_path = null,
		?string $throw_on_path_segment = null,
		?callable $mode_resolver = null
	) {
		$this->paths                 = &$recorded_paths;
		$this->body_for_path         = $body_for_path;
		$this->throw_on_path_segment = $throw_on_path_segment;
		$this->mode_resolver         = $mode_resolver;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \Psr\Http\Message\RequestInterface $request         The PSR-7 request.
	 * @param mixed            $timeout         Request timeout.
	 * @param mixed            $connect_timeout Connection timeout.
	 *
	 * @throws \RuntimeException When the configured path segment is encountered.
	 */
	public function sendRequest( RequestInterface $request, $timeout, $connect_timeout ): ResponseInterface { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$path          = (string) $request->getUri()->getPath();
		$this->paths[] = $path;

		if ( null !== $this->throw_on_path_segment && str_contains( $path, $this->throw_on_path_segment ) ) {
			throw new \RuntimeException( 'forced test exception' );
		}

		// An explicit body outranks every default, including the modes below.
		if ( null !== $this->body_for_path ) {
			return self::respond( 200, (string) call_user_func( $this->body_for_path, $path ) );
		}

		$mode = null !== $this->mode_resolver
			? (string) call_user_func( $this->mode_resolver )
			: self::MODE_OK;

		// Key lookup, which is how the plugin validates credentials before storing them.
		if ( str_contains( $path, '/1/keys/' ) ) {
			$acl = self::MODE_INVALID_KEY === $mode
				? [ 'search' ]
				: [ 'search', 'addObject', 'deleteObject' ];

			return self::json( 200, [ 'acl' => $acl ] );
		}

		// Everything else is indexing, which is what a re-index exercises.
		if ( self::MODE_SERVER_ERROR === $mode ) {
			return self::json( 500, [ 'message' => 'Internal error.' ] );
		}

		if ( str_contains( $path, '/task/' ) ) {
			return self::json(
				200,
				[
					'status'      => 'published',
					'pendingTask' => false,
				]
			);
		}

		if ( str_contains( $path, '/query' ) ) {
			return self::json(
				200,
				[
					'hits'        => [ [ 'objectID' => '1' ] ],
					'nbHits'      => 1,
					'page'        => 0,
					'hitsPerPage' => 20,
				]
			);
		}

		return self::json(
			200,
			[
				'taskID'    => 1,
				'updatedAt' => '2024-01-01T00:00:00.000Z',
			]
		);
	}

	/**
	 * Build a JSON response.
	 *
	 * @param int                  $status Response status.
	 * @param array<string, mixed> $body   Response body.
	 */
	private static function json( int $status, array $body ): ResponseInterface {
		return self::respond( $status, (string) wp_json_encode( $body ) );
	}

	/**
	 * Build a response from an already-encoded body.
	 *
	 * @param int    $status Response status.
	 * @param string $body   Response body.
	 */
	private static function respond( int $status, string $body ): ResponseInterface {
		// @phpstan-ignore return.type
		return new Response( $status, [], $body );
	}
}
