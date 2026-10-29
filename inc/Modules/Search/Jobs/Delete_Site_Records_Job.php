<?php
/**
 * The job that deletes the records of a site removed from the governing site.
 *
 * @package OneSearch\Modules\Search\Jobs
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Search\Jobs;

use OneSearch\Jobs\Abstract_Job;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Utils;

/**
 * Class - Delete_Site_Records_Job
 */
final class Delete_Site_Records_Job extends Abstract_Job {
	/**
	 * {@inheritDoc}
	 *
	 * A removed site isn't listed, so its failed deletes are reported as one failure of this site, with the last one's error. Only a failure is reported, since a delete that's waiting or done means nothing for search.
	 */
	public function get_statuses(): array {
		$failed = array_filter( parent::get_statuses(), static fn ( array $status ): bool => self::STATUS_FAILED === $status['status'] );

		return [] === $failed ? [] : [ [ 'key' => Utils::get_current_site_url() ] + end( $failed ) ];
	}

	/**
	 * Dispatches each delete that failed again.
	 */
	public function retry(): void {
		foreach ( $this->records->get_records() as $site_url => $record ) {
			if ( self::STATUS_FAILED === $record['status'] ) {
				$this->dispatch( [ (string) $site_url ] );
			}
		}
	}

	/**
	 * Deletes the record of every delete, e.g. once the whole index is gone. One that's waiting then stops when it runs.
	 */
	public function clear(): void {
		foreach ( array_keys( $this->records->get_records() ) as $site_url ) {
			$this->records->delete_record( (string) $site_url );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * The key is the removed site's URL.
	 *
	 * @param array<int, mixed> $args The removed site's URL.
	 */
	protected function get_key( array $args ): string {
		return (string) $args[0];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, mixed> $args The removed site's URL.
	 *
	 * @throws \RuntimeException When the records can't be deleted.
	 */
	protected function run( array $args ): bool {
		$site_url = (string) $args[0];

		$is_deleted = ( new Indexer() )->delete_site_records( [ $site_url ] );

		if ( is_wp_error( $is_deleted ) ) {
			throw new \RuntimeException( esc_html( Indexer::get_error_message( $is_deleted ) ) );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * A delete that's done means nothing for search, so its record goes.
	 */
	protected function on_complete( string $key, array $record ): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- The parent's signature.
		$this->records->delete_record( $key );
	}
}
