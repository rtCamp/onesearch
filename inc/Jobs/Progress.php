<?php
/**
 * The stored progress of a batch Action Scheduler job.
 *
 * @package OneSearch\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Jobs;

/**
 * Class - Progress
 *
 * @phpstan-type ProgressData array{
 *   id: string,
 *   status: string,
 *   total: ?int,
 *   done: int,
 *   failed: int,
 *   errors: string[],
 *   started_at: int,
 * }
 */
final class Progress {
	/**
	 * The run's statuses.
	 */
	public const STATUS_QUEUED    = 'queued';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';

	/**
	 * Maximum number of errors to keep for a run.
	 */
	private const MAX_ERRORS = 10;

	/**
	 * The option the progress is stored in.
	 */
	private string $option;

	/**
	 * Constructor.
	 *
	 * @param string $option The option the progress is stored in.
	 */
	public function __construct( string $option ) {
		$this->option = $option;
	}

	/**
	 * Whether a run's still queued or running.
	 *
	 * @param array<string,mixed> $progress The run's progress.
	 * @phpstan-param ProgressData $progress
	 */
	public static function is_active( array $progress ): bool {
		return in_array( $progress['status'], [ self::STATUS_QUEUED, self::STATUS_RUNNING ], true );
	}

	/**
	 * Gets the progress of the latest run.
	 *
	 * @return ?ProgressData
	 */
	public function get(): ?array {
		// Action Scheduler runs many batches in one request, so the cached option may be from before the job was started over.
		wp_cache_delete( $this->option, 'options' );

		$progress = get_option( $this->option, null );

		/** @var ?ProgressData */
		return is_array( $progress ) ? $progress : null;
	}

	/**
	 * Starts a new run, replacing the latest one.
	 *
	 * @return ProgressData
	 */
	public function start(): array {
		$progress = [
			'id'         => wp_generate_uuid4(),
			'status'     => self::STATUS_QUEUED,
			'total'      => null,
			'done'       => 0,
			'failed'     => 0,
			'errors'     => [],
			'started_at' => time(),
		];

		$this->set( $progress );

		return $progress;
	}

	/**
	 * Adds to a run's progress, and marks it as running.
	 *
	 * @param string   $id     The run's ID.
	 * @param int      $done   How many more items are done, whether they failed or not.
	 * @param int      $failed How many of those items failed.
	 * @param string[] $errors The errors of those items.
	 * @param ?int     $total  The number of items in the run, once it's known.
	 *
	 * @return ?ProgressData The run's progress, or null if it's no longer the latest run.
	 */
	public function advance( string $id, int $done = 0, int $failed = 0, array $errors = [], ?int $total = null ): ?array {
		$progress = $this->get();

		if ( null === $progress || $progress['id'] !== $id ) {
			return null;
		}

		$progress['status']  = self::STATUS_RUNNING;
		$progress['done']   += $done;
		$progress['failed'] += $failed;
		$progress['errors']  = array_slice( array_values( array_unique( [ ...$progress['errors'], ...$errors ] ) ), 0, self::MAX_ERRORS );
		$progress['total']   = $total ?? $progress['total'];

		// Items added during the run count toward the total too.
		if ( null !== $progress['total'] && $progress['done'] > $progress['total'] ) {
			$progress['total'] = $progress['done'];
		}

		$this->set( $progress );

		return $progress;
	}

	/**
	 * Finishes a run. It fails if it has errors.
	 *
	 * @param string   $id     The run's ID.
	 * @param string[] $errors Errors to add first.
	 *
	 * @return ?ProgressData The run's progress, or null if it's no longer the latest run.
	 */
	public function finish( string $id, array $errors = [] ): ?array {
		$progress = $this->advance( $id, 0, 0, $errors );

		if ( null === $progress ) {
			return null;
		}

		$progress['status'] = [] === $progress['errors'] ? self::STATUS_COMPLETED : self::STATUS_FAILED;

		$this->set( $progress );

		return $progress;
	}

	/**
	 * Gets the JSON schema of a run's progress.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'         => [
					'type'     => 'string',
					'required' => true,
				],
				'status'     => [
					'type'     => 'string',
					'enum'     => [ self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_FAILED ],
					'required' => true,
				],
				// Null until the items are counted.
				'total'      => [
					'type'    => [ 'integer', 'null' ],
					'minimum' => 0,
				],
				'done'       => [
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				],
				'failed'     => [
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				],
				'errors'     => [
					'type'     => 'array',
					'items'    => [ 'type' => 'string' ],
					'required' => true,
				],
				'started_at' => [
					'type'     => 'integer',
					'required' => true,
				],
			],
		];
	}

	/**
	 * Validates and sanitizes a copy of a run's progress, e.g. one fetched from a brand site.
	 *
	 * @param mixed $progress The copy.
	 *
	 * @return ?ProgressData Null if it isn't a run's progress.
	 */
	public static function sanitize( mixed $progress ): ?array {
		if ( ! is_array( $progress ) || is_wp_error( rest_validate_value_from_schema( $progress, self::get_schema() ) ) ) {
			return null;
		}

		return [
			'id'         => sanitize_text_field( (string) $progress['id'] ),
			'status'     => (string) $progress['status'],
			'total'      => isset( $progress['total'] ) ? (int) $progress['total'] : null,
			'done'       => (int) $progress['done'],
			'failed'     => (int) $progress['failed'],
			'errors'     => array_map( static fn ( $error ): string => sanitize_text_field( (string) $error ), (array) $progress['errors'] ),
			'started_at' => (int) $progress['started_at'],
		];
	}

	/**
	 * Stores the progress as given, e.g. a copy of a run on another site.
	 *
	 * @param array<string,mixed> $progress The progress.
	 * @phpstan-param ProgressData $progress
	 */
	public function set( array $progress ): void {
		update_option( $this->option, $progress, false );
	}

	/**
	 * Deletes the stored progress.
	 */
	public function delete(): void {
		delete_option( $this->option );
	}
}
