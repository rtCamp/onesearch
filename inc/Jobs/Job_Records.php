<?php
/**
 * The records of a job's state, stored in a unique option.
 *
 * @package OneSearch\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Jobs;

/**
 * Class - Job_Records
 *
 * @phpstan-type JobRecord array{ status: string, error: ?string, data: array<string, mixed>, action_id: ?int }
 */
final class Job_Records {
	/**
	 * The prefix for the records' option names.
	 */
	private string $option_prefix;

	/**
	 * Sets up the records.
	 *
	 * @param string $option_prefix The option name prefix for the records.
	 */
	public function __construct( string $option_prefix ) {
		$this->option_prefix = '_' . $option_prefix . '_';
	}

	/**
	 * Gets the records.
	 *
	 * @return array<string, JobRecord> Keyed by their key, oldest first.
	 */
	public function get_records(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API lists options by name.
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC",
				// Exactly the 32 characters of a key's md5, so a job whose name starts with this one's doesn't match.
				$wpdb->esc_like( $this->option_prefix ) . str_repeat( '_', 32 )
			)
		);
		$records = [];

		foreach ( $values as $value ) {
			$value = maybe_unserialize( $value );

			if ( is_array( $value ) ) {
				$records += $value;
			}
		}

		/** @var array<string, JobRecord> */
		return $records;
	}

	/**
	 * Gets the record with a key.
	 *
	 * @param string $key The record's key.
	 *
	 * @return ?JobRecord
	 */
	public function get_record( string $key ): ?array {
		$option = $this->get_option_name( $key );

		// Action Scheduler runs many actions per request, so the cached copy may be older than another request's.
		wp_cache_delete( $option, 'options' );

		$value = get_option( $option );

		/** @var ?JobRecord */
		return is_array( $value ) ? ( $value[ $key ] ?? null ) : null;
	}

	/**
	 * Saves a record, over the one with its key.
	 *
	 * @param string               $key    The record's key.
	 * @param array<string, mixed> $record The record.
	 * @phpstan-param JobRecord $record
	 */
	public function save_record( string $key, array $record ): void {
		update_option( $this->get_option_name( $key ), [ $key => $record ], false );
	}

	/**
	 * Changes a record, from its latest copy.
	 *
	 * @param string                         $key    The record's key.
	 * @param callable(JobRecord): JobRecord $change Gets the changed record.
	 *
	 * @return ?JobRecord The changed record, or null if it's missing.
	 */
	public function change_record( string $key, callable $change ): ?array {
		$record = $this->get_record( $key );

		if ( null === $record ) {
			return null;
		}

		$changed = $change( $record );
		$this->save_record( $key, $changed );

		return $changed;
	}

	/**
	 * Deletes the record with a key.
	 *
	 * @param string $key The record's key.
	 */
	public function delete_record( string $key ): void {
		delete_option( $this->get_option_name( $key ) );
	}

	/**
	 * Gets the name of a record's option. The key is hashed, since it can be too long for one.
	 *
	 * @param string $key The record's key.
	 */
	private function get_option_name( string $key ): string {
		return $this->option_prefix . md5( $key );
	}
}
