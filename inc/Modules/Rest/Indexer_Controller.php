<?php
/**
 * The /indexer route, which re-indexes a site and its brand sites, and reports how the index and its jobs are doing.
 *
 * @package OneSearch\Modules\Rest
 */

declare(strict_types = 1);

namespace OneSearch\Modules\Rest;

use OneSearch\Jobs\Queue;
use OneSearch\Modules\Search\Index_Status;
use OneSearch\Modules\Search\Indexer;
use OneSearch\Modules\Search\Jobs\Delete_Site_Records_Job;
use OneSearch\Modules\Search\Jobs\Index_Site_Job;
use OneSearch\Modules\Search\Jobs\Jobs;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Utils;
use WP_REST_Server;

/**
 * Class - Indexer_Controller
 *
 * @phpstan-import-type IndexSummary from \OneSearch\Modules\Search\Index_Status
 *
 * @phpstan-type JobProgress array{ total: int, done: int, failed: int }
 * @phpstan-type JobStatus array{ job: string, site: string, status: string, error: ?string, progress?: JobProgress }
 * @phpstan-type StatusResponse array{
 *   sites: array<string, array{index: ?IndexSummary}|array{error: string}>,
 *   jobs: list<JobStatus>,
 * }
 */
class Indexer_Controller extends Abstract_REST_Controller {
	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/indexer',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_status' ],
					'permission_callback' => [ $this, 'can_index' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'reindex' ],
					'permission_callback' => [ $this, 'can_index' ],
				],
			]
		);
	}

	/**
	 * Whether the request is from an admin, or from the governing site of this brand site.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Request.
	 */
	public function can_index( $request ): bool {
		return current_user_can( 'manage_options' ) || $this->is_governing_request( $request );
	}

	/**
	 * Gets the index summary and jobs of the site, and of a governing site's brand sites.
	 */
	public function get_status(): \WP_REST_Response {
		return rest_ensure_response( $this->get_report() );
	}

	/**
	 * Starts indexing all of the site's posts, and on a governing site all of each brand site's posts too. Indexing that's going starts over.
	 *
	 * A governing site also tries again to delete the records of the sites it removed, where that failed.
	 */
	public function reindex(): \WP_REST_Response|\WP_Error {
		$sites = [ Utils::get_current_site_url() ];

		if ( Settings::is_governing_site() ) {
			$sites = [ ...$sites, ...array_keys( Settings::get_shared_sites() ) ];
			Jobs::registry()->get( Delete_Site_Records_Job::class )->retry();
		}

		// An admin is watching.
		add_action( 'shutdown', [ Queue::class, 'start_runner' ] );

		foreach ( $sites as $site_url ) {
			$started = Jobs::registry()->get( Index_Site_Job::class )->dispatch( [ $site_url ] );

			if ( is_wp_error( $started ) ) {
				return $started;
			}
		}

		return rest_ensure_response( [ 'started' => true ] );
	}

	/**
	 * Gets this site's index summary and jobs, and on a governing site each brand site's report too.
	 *
	 * @return StatusResponse
	 */
	private function get_report(): array {
		$post_types = Indexer::get_indexable_post_types();

		$sites = [
			Utils::get_current_site_url() => [
				'index' => is_wp_error( $post_types ) ? null : Index_Status::get_summary( $post_types ),
			],
		];
		$jobs  = array_map(
			static function ( array $reported ): array {
				// Every job that reports a status keys it by the site it shows on.
				$status = [
					'job'    => $reported['job'],
					'site'   => $reported['key'],
					'status' => $reported['status'],
					'error'  => $reported['error'],
				];

				// The jobs that count their items.
				if ( isset( $reported['data']['done'] ) ) {
					$status['progress'] = [
						'total'  => (int) $reported['data']['total'],
						'done'   => (int) $reported['data']['done'],
						'failed' => (int) $reported['data']['failed'],
					];
				}

				return $status;
			},
			Jobs::registry()->get_statuses()
		);

		if ( ! Settings::is_governing_site() ) {
			return [
				'sites' => $sites,
				'jobs'  => $jobs,
			];
		}

		$known_jobs = Jobs::registry()->get_names();
		// A job this site keeps about a brand site is newer than the brand site's own.
		$own_jobs = array_map( static fn ( array $status ): string => $status['job'] . ' ' . $status['site'], $jobs );

		foreach ( Settings::get_shared_sites() as $site_url => $site_data ) {
			$report = Remote_Client::get_brand_status( $site_data );

			// A brand site only reports about itself.
			$site  = ! is_wp_error( $report ) && is_array( $report['sites'] ?? null ) ? reset( $report['sites'] ) : null;
			$index = is_array( $site ) && is_array( $site['index'] ?? null ) ? $this->sanitize_index_summary( $site['index'] ) : null;

			if ( is_wp_error( $report ) || ! is_array( $site ) || ! array_key_exists( 'index', $site ) || ( null !== $site['index'] && null === $index ) ) {
				$sites[ $site_url ] = [
					'error' => is_wp_error( $report ) ? $report->get_error_message() : __( 'The site returned an invalid response.', 'onesearch' ),
				];
				continue;
			}

			$sites[ $site_url ] = [ 'index' => $index ];

			foreach ( is_array( $report['jobs'] ?? null ) ? $report['jobs'] : [] as $status ) {
				$status = $this->sanitize_job_status( $status, $site_url, $known_jobs );

				if ( null !== $status && ! in_array( $status['job'] . ' ' . $status['site'], $own_jobs, true ) ) {
					$jobs[] = $status;
				}
			}
		}

		return [
			'sites' => $sites,
			'jobs'  => $jobs,
		];
	}

	/**
	 * Sanitizes the index summary in a brand site's report.
	 *
	 * @param mixed $summary The summary.
	 *
	 * @return ?IndexSummary Null if it isn't a summary.
	 */
	private function sanitize_index_summary( mixed $summary ): ?array {
		if ( ! is_array( $summary ) || ! is_numeric( $summary['total'] ?? null ) || ! is_numeric( $summary['indexed'] ?? null ) || ! is_array( $summary['failed'] ?? null ) ) {
			return null;
		}

		$failed = [];
		foreach ( $summary['failed'] as $post ) {
			if ( is_array( $post ) && is_numeric( $post['id'] ?? null ) ) {
				$failed[] = [
					'id'       => absint( $post['id'] ),
					'title'    => sanitize_text_field( (string) ( $post['title'] ?? '' ) ),
					'edit_url' => esc_url_raw( (string) ( $post['edit_url'] ?? '' ) ),
					'error'    => sanitize_text_field( (string) ( $post['error'] ?? '' ) ),
				];
			}
		}

		return [
			'total'   => absint( $summary['total'] ),
			'indexed' => absint( $summary['indexed'] ),
			'failed'  => $failed,
		];
	}

	/**
	 * Sanitizes the status of a job in a brand site's report.
	 *
	 * @param mixed    $status     The status.
	 * @param string   $site       The brand site's URL.
	 * @param string[] $known_jobs The names of the jobs to keep.
	 *
	 * @return ?JobStatus Null if it isn't the status of a known job.
	 */
	private function sanitize_job_status( mixed $status, string $site, array $known_jobs ): ?array {
		$statuses = [ 'queued', 'running', 'completed', 'failed' ];

		if ( ! is_array( $status ) || ! in_array( $status['job'] ?? null, $known_jobs, true ) || ! in_array( $status['status'] ?? null, $statuses, true ) ) {
			return null;
		}

		$sanitized = [
			'job'    => (string) $status['job'],
			'site'   => $site,
			'status' => (string) $status['status'],
			'error'  => is_scalar( $status['error'] ?? null ) ? sanitize_text_field( (string) $status['error'] ) : null,
		];

		if ( is_array( $status['progress'] ?? null ) ) {
			$progress = $status['progress'];

			$sanitized['progress'] = [
				'total'  => absint( $progress['total'] ?? 0 ),
				'done'   => absint( $progress['done'] ?? 0 ),
				'failed' => absint( $progress['failed'] ?? 0 ),
			];
		}

		return $sanitized;
	}
}
