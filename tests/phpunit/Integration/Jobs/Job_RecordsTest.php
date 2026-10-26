<?php
/**
 * Job_Records tests.
 *
 * @package OneSearch\Tests\Integration\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Jobs;

use OneSearch\Jobs\Job_Records;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Job_Records class.
 */
#[CoversClass( Job_Records::class )]
final class Job_RecordsTest extends TestCase {
	/**
	 * A record is kept under a key too long for an option name, like a long site URL.
	 */
	public function test_keeps_a_record_with_a_long_key(): void {
		$records = new Job_Records( 'test_job' );
		$key     = 'https://brand.example.com/' . str_repeat( 'a', 300 );

		$records->save_record( $key, $this->record( 'queued' ) );

		$this->assertSame( $this->record( 'queued' ), $records->get_record( $key ) );
		$this->assertSame( [ $key ], array_keys( $records->get_records() ) );
	}

	/**
	 * A job only gets its own records, even when another job's name starts with its name.
	 */
	public function test_gets_only_its_own_records(): void {
		$index      = new Job_Records( 'test_job_index' );
		$index_site = new Job_Records( 'test_job_index_site' );

		$index->save_record( 'a', $this->record( 'queued' ) );
		$index_site->save_record( 'b', $this->record( 'running' ) );

		$this->assertSame( [ 'a' ], array_keys( $index->get_records() ) );
		$this->assertSame( [ 'b' ], array_keys( $index_site->get_records() ) );
	}

	/**
	 * A record another request changed is read, though this request has an older copy cached, as in a queue runner working through many actions.
	 */
	public function test_reads_a_record_another_request_changed(): void {
		global $wpdb;

		$records = new Job_Records( 'test_job' );
		$records->save_record( 'a', $this->record( 'queued', [ 'marker' => 'old-copy' ] ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another request's write, which this request's cache doesn't see.
		$wpdb->query( "UPDATE {$wpdb->options} SET option_value = REPLACE( option_value, 'old-copy', 'new-copy' ) WHERE option_value LIKE '%old-copy%'" );

		$this->assertSame( 'new-copy', $records->change_record( 'a', static fn ( array $record ): array => $record )['data']['marker'] ?? null );
	}

	/**
	 * Builds a record.
	 *
	 * @param string               $status The status.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return array{ status: string, error: ?string, data: array<string, mixed>, action_id: ?int }
	 */
	private function record( string $status, array $data = [] ): array {
		return [
			'status'    => $status,
			'error'     => null,
			'data'      => $data,
			'action_id' => null,
		];
	}
}
