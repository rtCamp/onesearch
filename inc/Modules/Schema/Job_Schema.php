<?php
/**
 * Database schema management for the job storage table.
 *
 * @package OneSearch\Modules\Schema
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Schema;

/**
 * Class - Job_Schema
 *
 * Creates and upgrades the wp_onesearch_index_jobs table via dbDelta.
 * Called on plugin activation and on each request when the schema version
 * option doesn't match the current version constant.
 */
class Job_Schema {
	public const TABLE_NAME     = 'onesearch_index_jobs';
	public const SCHEMA_VERSION = 1;
	public const VERSION_OPTION = 'onesearch_jobs_schema_version';

	/**
	 * Run table creation / upgrade if needed.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		self::create_table();
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Create or upgrade the table using dbDelta.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE_NAME;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id varchar(36) NOT NULL,
  type varchar(50) NOT NULL,
  status varchar(20) NOT NULL,
  parent_id varchar(36) DEFAULT NULL,
  group_name varchar(50) NOT NULL,
  progress int NOT NULL DEFAULT 0,
  progress_total int NOT NULL DEFAULT 0,
  progress_percent decimal(5,1) NOT NULL DEFAULT 0.0,
  error longtext DEFAULT NULL,
  action_id bigint DEFAULT NULL,
  action_args longtext DEFAULT NULL,
  children_total int NOT NULL DEFAULT 0,
  children_done int NOT NULL DEFAULT 0,
  children_failed int NOT NULL DEFAULT 0,
  children_cancelled int NOT NULL DEFAULT 0,
  data longtext DEFAULT NULL,
  created_at int unsigned NOT NULL,
  updated_at int unsigned NOT NULL,
  finished_at int unsigned DEFAULT NULL,
  cancelled_at int unsigned DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_status (status),
  KEY idx_parent (parent_id),
  KEY idx_created (created_at),
  KEY idx_type_status (type, status)
) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the table entirely. Called from uninstall.php.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
