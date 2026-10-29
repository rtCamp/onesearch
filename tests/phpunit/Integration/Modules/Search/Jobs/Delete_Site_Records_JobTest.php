<?php
/**
 * Delete_Site_Records_Job tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search\Jobs;

use OneSearch\Modules\Search\Jobs\Delete_Site_Records_Job;
use OneSearch\Modules\Search\Jobs\Jobs;
use OneSearch\Tests\TestCase;
use OneSearch\Utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Delete_Site_Records_Job class.
 */
#[CoversClass( Delete_Site_Records_Job::class )]
final class Delete_Site_Records_JobTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		( new Jobs() )->register_hooks();
		$this->set_up_governing_site();
	}

	/**
	 * The deletes that failed are reported as one failure of this site, since the removed sites aren't listed, and a retry deletes their records.
	 */
	public function test_deletes_the_removed_sites_records(): void {
		$this->fake_algolia_index( '/deleteByQuery' );
		$this->add_algolia_record( 'old_post', 'https://old.example.com/' );
		$this->add_algolia_record( 'older_post', 'https://older.example.com/' );
		$this->add_algolia_record( 'other_post', 'https://other.example.com/' );

		Jobs::registry()->get( Delete_Site_Records_Job::class )->dispatch( [ 'https://old.example.com/' ] );
		Jobs::registry()->get( Delete_Site_Records_Job::class )->dispatch( [ 'https://older.example.com/' ] );
		$this->assertSame( [], $this->get_statuses_of( 'delete_site_records' ), 'A waiting delete means nothing for search.' );
		$this->run_jobs();

		$this->assertSame(
			[
				[
					'job'    => 'delete_site_records',
					'key'    => Utils::get_current_site_url(),
					'status' => 'failed',
					'error'  => 'Failed to delete Algolia records by given args. forced test exception',
					'data'   => [],
				],
			],
			$this->get_statuses_of( 'delete_site_records' )
		);
		$this->assertCount( 3, $this->algolia_records );

		$this->fake_algolia_index();
		Jobs::registry()->get( Delete_Site_Records_Job::class )->retry();
		$this->run_jobs();

		$this->assertSame( [], $this->get_statuses_of( 'delete_site_records' ) );
		$this->assertSame( [ 'other_post' ], array_keys( $this->algolia_records ) );
	}
}
