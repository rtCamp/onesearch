<?php
/**
 * Progress tests.
 *
 * @package OneSearch\Tests\Integration\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Jobs;

use OneSearch\Jobs\Progress;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Progress class.
 */
#[CoversClass( Progress::class )]
final class ProgressTest extends TestCase {
	/**
	 * The test option.
	 */
	private const OPTION = 'onesearch_test_progress';

	/**
	 * A run is queued, then counts its items as they're done, and completes.
	 */
	public function test_a_run_completes(): void {
		$progress = new Progress( self::OPTION );
		$run      = $progress->start();

		$this->assertSame( Progress::STATUS_QUEUED, $run['status'] );
		$this->assertTrue( Progress::is_active( $run ) );

		$progress->advance( $run['id'], 0, 0, [], 150 );
		$progress->advance( $run['id'], 100 );
		$progress->advance( $run['id'], 50 );

		$running = $progress->get();
		$this->assertSame( Progress::STATUS_RUNNING, $running['status'] );
		$this->assertSame( 150, $running['total'] );
		$this->assertSame( 150, $running['done'] );

		$finished = $progress->finish( $run['id'] );
		$this->assertSame( Progress::STATUS_COMPLETED, $finished['status'] );
		$this->assertFalse( Progress::is_active( $finished ) );
		$this->assertSame( $finished, $progress->get() );
	}

	/**
	 * Items added while a run goes on raise its total, so it never shows more done than there are.
	 */
	public function test_done_never_passes_the_total(): void {
		$progress = new Progress( self::OPTION );
		$run      = $progress->start();

		$progress->advance( $run['id'], 0, 0, [], 150 );
		$progress->advance( $run['id'], 100 );
		$running = $progress->advance( $run['id'], 60 );

		$this->assertSame( 160, $running['done'] );
		$this->assertSame( 160, $running['total'] );
	}

	/**
	 * A run with errors fails, and keeps only the first few distinct errors.
	 */
	public function test_a_run_with_errors_fails(): void {
		$progress = new Progress( self::OPTION );
		$run      = $progress->start();

		$progress->advance( $run['id'], 1, 0, [ 'Same error' ] );
		$progress->advance( $run['id'], 1, 0, [ 'Same error' ] );

		for ( $i = 1; $i <= 20; ++$i ) {
			$progress->advance( $run['id'], 1, 0, [ 'Error ' . $i ] );
		}

		$finished = $progress->finish( $run['id'] );

		$this->assertSame( Progress::STATUS_FAILED, $finished['status'] );
		$this->assertSame( 22, $finished['done'] );
		$this->assertSame( [ 'Same error', 'Error 1', 'Error 2', 'Error 3', 'Error 4', 'Error 5', 'Error 6', 'Error 7', 'Error 8', 'Error 9' ], $finished['errors'] );
	}

	/**
	 * Once a run is started over, the old run's batches can't change it.
	 */
	public function test_an_older_run_cannot_update_a_newer_one(): void {
		$progress = new Progress( self::OPTION );
		$old      = $progress->start();
		$new      = $progress->start();

		$this->assertNull( $progress->advance( $old['id'], 10 ) );
		$this->assertNull( $progress->finish( $old['id'], [ 'Old error' ] ) );

		$this->assertSame( $new, $progress->get() );
	}

	/**
	 * The progress is read from the database, so a run started over by another request is seen.
	 */
	public function test_get_sees_a_run_started_by_another_request(): void {
		global $wpdb;

		$progress = new Progress( self::OPTION );
		$old      = $progress->start();
		$this->assertSame( $old['id'], $progress->get()['id'] );

		// Another request starts it over, so this request's option cache isn't updated.
		$new = array_merge( $old, [ 'id' => 'newer-run' ] );
		$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $new ) ], [ 'option_name' => self::OPTION ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( 'newer-run', $progress->get()['id'] );
		$this->assertNull( $progress->advance( $old['id'], 1 ) );
	}

	/**
	 * Progress copied from elsewhere is stored as is, and can be deleted.
	 */
	public function test_set_and_delete(): void {
		$progress = new Progress( self::OPTION );
		$copy     = ( new Progress( 'onesearch_test_other' ) )->start();

		$progress->set( $copy );
		$this->assertSame( $copy, $progress->get() );

		$progress->delete();
		$this->assertNull( $progress->get() );
	}
}
