<?php
/**
 * Data access layer for the wp_onesearch_index_jobs table.
 *
 * @package OneSearch\Modules\Schema
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Schema;

/**
 * Class - Job_Repository
 *
 * All reads and writes to the custom jobs table go through here.
 * Every job is inserted into the table on creation so active jobs
 * can be enumerated via a status query; transients remain the fast-read
 * path for active job state.
 */
class Job_Repository {
	/** @var string Fully-qualified table name including WP prefix. */
	private string $table;

	/**
	 * Initialize the repository with the correct table name.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . Job_Schema::TABLE_NAME;
	}

	/**
	 * Insert a new job row.
	 *
	 * @param array<string, mixed> $data Flat row data from Abstract_Job::to_array().
	 */
	public function insert( array $data ): bool {
		global $wpdb;

		$row = $this->prepare_row( $data );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->insert( $this->table, $row );
	}

	/**
	 * Update an existing job row.
	 *
	 * @param string               $id   Job ID.
	 * @param array<string, mixed> $data Partial or full row data.
	 */
	public function update( string $id, array $data ): bool {
		global $wpdb;

		$row = $this->prepare_row( $data );
		unset( $row['id'] ); // never overwrite the PK.

		if ( empty( $row ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( $this->table, $row, [ 'id' => $id ] );
	}

	/**
	 * Insert-or-update a job row (upsert via ON DUPLICATE KEY UPDATE).
	 *
	 * @param array<string, mixed> $data Full row data from Abstract_Job::to_array().
	 */
	public function upsert( array $data ): bool {
		global $wpdb;

		$row = $this->prepare_row( $data );

		// Build placeholders handling NULLs explicitly — $wpdb->prepare('%s', null)
		// produces '' not NULL, which breaks IS NULL queries on parent_id etc.
		$columns      = array_keys( $row );
		$placeholders = [];
		$values       = [];
		$updates      = [];

		foreach ( $row as $col => $val ) {
			if ( null === $val ) {
				$placeholders[] = 'NULL';
			} else {
				$placeholders[] = '%s';
				$values[]       = $val;
			}

			if ( 'id' !== $col ) {
				$updates[] = null === $val ? "{$col} = NULL" : "{$col} = VALUES({$col})";
			}
		}

		$columns_sql = implode( ', ', $columns );
		$values_sql  = implode( ', ', $placeholders );
		$update_sql  = implode( ', ', $updates );

		// $this->table is safe: it is always $wpdb->prefix + a hard-coded constant.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return false !== $wpdb->query( "INSERT INTO {$this->table} ({$columns_sql}) VALUES ({$values_sql}) ON DUPLICATE KEY UPDATE {$update_sql}" );
		}

		$sql = $wpdb->prepare(
			"INSERT INTO {$this->table} ({$columns_sql}) VALUES ({$values_sql}) ON DUPLICATE KEY UPDATE {$update_sql}",
			...$values
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is already prepared by $wpdb->prepare() above.
		return false !== $wpdb->query( $sql );
		// phpcs:enable
	}

	/**
	 * Fetch a single job row by ID.
	 *
	 * @param string $id Job ID.
	 * @return array<string, mixed>|null
	 */
	public function get_by_id( string $id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %s", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $this->hydrate_row( $row ) : null;
	}

	/**
	 * Fetch multiple job rows by IDs.
	 *
	 * @param string[] $ids List of job IDs.
	 * @return array<string, array<string, mixed>> Keyed by job ID.
	 */
	public function get_by_ids( array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id IN ({$placeholders})", ...$ids ),
			ARRAY_A
		);
		// phpcs:enable

		$result = [];
		foreach ( ( $rows ?: [] ) as $row ) {
			$hydrated                  = $this->hydrate_row( $row );
			$result[ $hydrated['id'] ] = $hydrated;
		}

		return $result;
	}

	/**
	 * Delete a job row by ID.
	 *
	 * @param string $id Job ID.
	 */
	public function delete_by_id( string $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $this->table, [ 'id' => $id ] );
	}

	/**
	 * Store the Action Scheduler action ID and args for a job.
	 *
	 * @param string  $id        Job ID.
	 * @param int     $action_id AS action ID.
	 * @param mixed[] $args      AS action args.
	 */
	public function set_action( string $id, int $action_id, array $args ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			$this->table,
			[
				'action_id'   => $action_id,
				'action_args' => wp_json_encode( $args ),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * Read the Action Scheduler action ID and args for a job.
	 *
	 * @param string $id Job ID.
	 * @return array{action_id: int, args: mixed[]}|null
	 */
	public function get_action( string $id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT action_id, action_args FROM {$this->table} WHERE id = %s", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row || ! $row['action_id'] ) {
			return null;
		}

		return [
			'action_id' => (int) $row['action_id'],
			'args'      => json_decode( (string) $row['action_args'], true ) ?: [],
		];
	}

	/**
	 * Atomically increment a counter column on the parent row.
	 *
	 * @param string $parent_id Job ID of the parent.
	 * @param string $column    One of: children_done, children_failed, children_cancelled.
	 * @param int    $amount    Amount to add (default 1).
	 */
	public function increment_counter( string $parent_id, string $column, int $amount = 1 ): bool {
		global $wpdb;

		$allowed = [ 'children_done', 'children_failed', 'children_cancelled' ];
		if ( ! in_array( $column, $allowed, true ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET {$column} = {$column} + %d, updated_at = %d WHERE id = %s",
				$amount,
				time(),
				$parent_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Atomically decrement a counter column, clamped to 0.
	 *
	 * @param string $parent_id Job ID of the parent.
	 * @param string $column    Column to decrement.
	 * @return int New value after decrement.
	 */
	public function decrement_counter( string $parent_id, string $column ): int {
		global $wpdb;

		$allowed = [ 'children_done', 'children_failed', 'children_cancelled' ];
		if ( ! in_array( $column, $allowed, true ) ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET {$column} = GREATEST({$column} - 1, 0), updated_at = %d WHERE id = %s",
				time(),
				$parent_id
			)
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT {$column} FROM {$this->table} WHERE id = %s", $parent_id )
		);
		// phpcs:enable
	}

	/**
	 * Cancel multiple jobs in one UPDATE, skipping already-terminal rows.
	 *
	 * Only transitions 'pending' and 'running' rows to 'cancelled';
	 * completed or already-cancelled children are left untouched.
	 *
	 * @param string[] $ids Array of job IDs to cancel.
	 * @param int      $now Unix timestamp for finished_at / cancelled_at / updated_at.
	 */
	public function batch_cancel( array $ids, int $now ): void {
		global $wpdb;

		if ( empty( $ids ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				 SET status = 'cancelled', finished_at = %d, cancelled_at = %d, updated_at = %d
				 WHERE id IN ({$placeholders}) AND status IN ('pending', 'running')",
				$now,
				$now,
				$now,
				...$ids
			)
		);
		// phpcs:enable
	}

	/**
	 * Reset counter columns to explicit values (e.g. when retrying jobs).
	 *
	 * @param string $parent_id Job ID of the parent.
	 * @param int    $done      New value for children_done.
	 * @param int    $failed    New value for children_failed.
	 * @param int    $cancelled New value for children_cancelled.
	 */
	public function reset_counters( string $parent_id, int $done = 0, int $failed = 0, int $cancelled = 0 ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			$this->table,
			[
				'children_done'      => $done,
				'children_failed'    => $failed,
				'children_cancelled' => $cancelled,
				'updated_at'         => time(),
			],
			[ 'id' => $parent_id ]
		);
	}

	/**
	 * Read all three counter values for a parent job.
	 *
	 * @param string $parent_id Job ID of the parent.
	 * @return array{done: int, failed: int, cancelled: int}
	 */
	public function get_counters( string $parent_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT children_done, children_failed, children_cancelled FROM {$this->table} WHERE id = %s",
				$parent_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return [
				'done'      => 0,
				'failed'    => 0,
				'cancelled' => 0,
			];
		}

		return [
			'done'      => (int) $row['children_done'],
			'failed'    => (int) $row['children_failed'],
			'cancelled' => (int) $row['children_cancelled'],
		];
	}

	/**
	 * Return job IDs for all active (pending or running) jobs.
	 *
	 * @return string[]
	 */
	public function get_active_job_ids(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			"SELECT id FROM {$this->table} WHERE status IN ('pending', 'running') ORDER BY created_at ASC"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: [];
	}

	/**
	 * Paginate terminal top-level jobs (no parent_id) or all terminal jobs.
	 *
	 * @param  int  $page        1-based page number.
	 * @param  int  $per_page    Rows per page.
	 * @param  bool $parent_only When true, only returns root jobs (parent_id IS NULL).
	 * @return array<string, mixed>[]
	 */
	public function get_terminal_jobs( int $page, int $per_page, bool $parent_only = true ): array {
		global $wpdb;

		$offset     = ( $page - 1 ) * $per_page;
		$parent_sql = $parent_only ? 'AND parent_id IS NULL' : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE status IN ('completed', 'failed', 'cancelled') {$parent_sql}
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map( [ $this, 'hydrate_row' ], $rows ?: [] );
	}

	/**
	 * Count terminal jobs (optionally root-only).
	 *
	 * @param  bool $parent_only When true, only counts rows with parent_id IS NULL.
	 */
	public function count_terminal_jobs( bool $parent_only = true ): int {
		global $wpdb;

		$parent_sql = $parent_only ? 'AND parent_id IS NULL' : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table}
			 WHERE status IN ('completed', 'failed', 'cancelled') {$parent_sql}"
		);
		// phpcs:enable
	}

	/**
	 * Convert a domain-model array (from Abstract_Job::to_array()) into a flat DB row.
	 *
	 * Retry fields and child_ids are folded into the `data` JSON blob since they
	 * don't have dedicated columns.
	 *
	 * @param  array<string, mixed> $data Domain-model array from Abstract_Job::to_array().
	 * @return array<string, mixed>
	 */
	private function prepare_row( array $data ): array {
		$now = time();

		// Fields without dedicated columns go into the data blob.
		$blob_keys = [ 'max_retries', 'retry_count', 'retry_delay_seconds', 'child_ids' ];
		$blob      = is_array( $data['data'] ?? null ) ? $data['data'] : [];
		foreach ( $blob_keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$blob[ $key ] = $data[ $key ];
			}
		}

		return [
			'id'                 => (string) ( $data['id'] ?? '' ),
			'type'               => (string) ( $data['type'] ?? '' ),
			'status'             => (string) ( $data['status'] ?? 'pending' ),
			'parent_id'          => isset( $data['parent_id'] ) ? (string) $data['parent_id'] : null,
			'group_name'         => (string) ( $data['group'] ?? 'default' ),
			'progress'           => (int) ( $data['progress'] ?? 0 ),
			'progress_total'     => (int) ( $data['progress_total'] ?? 0 ),
			'progress_percent'   => (float) ( $data['progress_percent'] ?? 0.0 ),
			'error'              => isset( $data['error'] ) ? (string) $data['error'] : null,
			'children_total'     => (int) ( $data['children_total'] ?? 0 ),
			'children_done'      => (int) ( $data['children_completed'] ?? $data['children_done'] ?? 0 ),
			'children_failed'    => (int) ( $data['children_failed'] ?? 0 ),
			'children_cancelled' => (int) ( $data['children_cancelled'] ?? 0 ),
			'data'               => wp_json_encode( $blob ),
			'created_at'         => (int) ( $data['created_at'] ?? $now ),
			'updated_at'         => (int) ( $data['updated_at'] ?? $now ),
			'finished_at'        => isset( $data['finished_at'] ) ? (int) $data['finished_at'] : null,
			'cancelled_at'       => isset( $data['cancelled_at'] ) ? (int) $data['cancelled_at'] : null,
		];
	}

	/**
	 * Convert a raw DB row back into the domain-model array shape (matching Abstract_Job::to_array()).
	 *
	 * @param  array<string, mixed> $row Raw database row from $wpdb.
	 * @return array<string, mixed>
	 */
	private function hydrate_row( array $row ): array {
		$blob = [];
		if ( isset( $row['data'] ) && is_string( $row['data'] ) ) {
			$decoded = json_decode( $row['data'], true );
			$blob    = is_array( $decoded ) ? $decoded : [];
		}

		// Strip blob-only fields from the 'data' key so the domain model sees
		// only the user-provided metadata, not the retry/child_ids we folded in.
		$data_only = array_diff_key(
			$blob,
			array_flip( [ 'max_retries', 'retry_count', 'retry_delay_seconds', 'child_ids' ] )
		);

		return [
			'id'                  => (string) $row['id'],
			'type'                => (string) ( $row['type'] ?? '' ),
			'status'              => (string) ( $row['status'] ?? '' ),
			'parent_id'           => isset( $row['parent_id'] ) ? (string) $row['parent_id'] : null,
			'group'               => (string) ( $row['group_name'] ?? 'default' ),
			'progress'            => (int) ( $row['progress'] ?? 0 ),
			'progress_total'      => (int) ( $row['progress_total'] ?? 0 ),
			'progress_percent'    => (float) ( $row['progress_percent'] ?? 0.0 ),
			'error'               => isset( $row['error'] ) ? (string) $row['error'] : null,
			'action_id'           => isset( $row['action_id'] ) ? (int) $row['action_id'] : null,
			'children_total'      => (int) ( $row['children_total'] ?? 0 ),
			'children_completed'  => (int) ( $row['children_done'] ?? 0 ),
			'children_failed'     => (int) ( $row['children_failed'] ?? 0 ),
			'children_cancelled'  => (int) ( $row['children_cancelled'] ?? 0 ),
			'data'                => $data_only,
			'max_retries'         => (int) ( $blob['max_retries'] ?? 3 ),
			'retry_count'         => (int) ( $blob['retry_count'] ?? 0 ),
			'retry_delay_seconds' => (int) ( $blob['retry_delay_seconds'] ?? 60 ),
			'child_ids'           => $blob['child_ids'] ?? [],
			'created_at'          => (int) ( $row['created_at'] ?? 0 ),
			'updated_at'          => (int) ( $row['updated_at'] ?? 0 ),
			'finished_at'         => isset( $row['finished_at'] ) ? (int) $row['finished_at'] : null,
			'cancelled_at'        => isset( $row['cancelled_at'] ) ? (int) $row['cancelled_at'] : null,
		];
	}
}
