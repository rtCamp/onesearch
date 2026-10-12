<?php
/**
 * Tests for Job_REST_Controller.
 *
 * @package OneSearch\Tests\Integration\Modules\Scheduler
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Scheduler;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Jobs\Registry;
use OneSearch\Modules\Jobs\Reindex_Job;
use OneSearch\Modules\Jobs\Sync_Job;
use OneSearch\Modules\Scheduler\Bootstrap;
use OneSearch\Modules\Scheduler\Job_REST_Controller;
use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Modules\Schema\Job_Repository;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;

/**
 * REST endpoint tests for {@see Job_REST_Controller}.
 */
#[CoversClass( Job_REST_Controller::class )]
#[CoversClass( Job_Scheduler::class )]
#[CoversClass( Registry::class )]
#[CoversClass( Abstract_Job::class )]
#[CoversClass( Reindex_Job::class )]
#[CoversClass( Sync_Job::class )]
class Job_REST_Controller_Test extends TestCase {
	/**
	 * The job the mocked brand site reports.
	 *
	 * @var array<string, mixed>
	 */
	private array $brand_job = [];

	/**
	 * REST server.
	 */
	private ?\WP_REST_Server $server;

	/**
	 * Job_Scheduler instance.
	 */
	private Job_Scheduler $scheduler;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->scheduler = new Job_Scheduler();

		// Main skips module loading under the test install's plain permalinks, so register the job types retries resolve.
		( new Bootstrap() )->init();

		( new Job_REST_Controller() )->register_hooks();
		do_action( 'rest_api_init' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		$this->tear_down_governing_site();

		parent::tear_down();
	}

	/**
	 * The admin UI polls a run and its batches, and a governing site polls a brand site's run with its token.
	 */
	public function test_a_run_can_be_polled_by_the_admin_and_by_the_governing_site(): void {
		$parent = new Reindex_Job();
		$batch  = new Sync_Job();
		$batch->set_parent_id( $parent->get_id() );
		$batch->set_data( [ 'post_ids' => [ 1 ] ] );
		$this->scheduler->schedule( $batch );

		$parent->set_child_ids( [ $batch->get_id() ] );
		$parent->mark_running();
		$this->scheduler->persist_job( $parent );

		$job      = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/jobs/' . $parent->get_id() ) )->get_data();
		$children = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/jobs/' . $parent->get_id() . '/children' ) )->get_data();

		$this->assertSame( Abstract_Job::STATUS_RUNNING, $job['job']['status'] );
		$this->assertSame( [ $batch->get_id() ], array_column( $children['children'], 'id' ) );

		$api_key = Settings::regenerate_api_key();
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/' . $parent->get_id() );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $parent->get_id(), $response->get_data()['job']['id'] );
	}

	/**
	 * Every job endpoint answers 404 for a job that doesn't exist.
	 */
	public function test_unknown_jobs_return_404(): void {
		$requests = [
			new WP_REST_Request( 'GET', '/onesearch/v1/jobs/nonexistent' ),
			new WP_REST_Request( 'GET', '/onesearch/v1/jobs/nonexistent/children' ),
			new WP_REST_Request( 'POST', '/onesearch/v1/jobs/nonexistent/retry' ),
			new WP_REST_Request( 'DELETE', '/onesearch/v1/jobs/nonexistent' ),
		];

		foreach ( $requests as $request ) {
			$response = $this->server->dispatch( $request );

			$this->assertSame( 'onesearch_job_not_found', $response->get_data()['code'], $request->get_method() . ' ' . $request->get_route() );
			$this->assertSame( 404, $response->get_status() );
		}
	}

	/**
	 * A subscriber cannot access jobs endpoints.
	 */
	public function test_jobs_endpoint_requires_manage_options(): void {
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/history' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Cancelling stops a live job, and leaves a finished one as it ended.
	 */
	public function test_cancel_stops_a_live_job_and_refuses_a_finished_one(): void {
		$live = $this->create_scheduled_job();

		$finished = new Sync_Job();
		$finished->set_data( [ 'post_ids' => [ 1 ] ] );
		$finished->mark_completed();
		$this->scheduler->persist_job( $finished );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', '/onesearch/v1/jobs/' . $live->get_id() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Abstract_Job::STATUS_CANCELLED, $this->scheduler->get_status( $live->get_id() )['status'] );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', '/onesearch/v1/jobs/' . $finished->get_id() ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_job_terminal', $response->get_data()['code'] );
		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $this->scheduler->get_status( $finished->get_id() )['status'] );
	}

	/**
	 * Only a failed job can be retried, so a job that's still queued isn't queued twice.
	 */
	public function test_retry_job_requires_failed_status(): void {
		$job = $this->create_scheduled_job();

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_retry_not_failed', $data['code'] );
	}

	/**
	 * A reindex whose batches can't be trusted to have covered every post is re-run from scratch when retried, rather
	 * than having its cancelled batches counted as done and being marked completed.
	 *
	 * It has to be re-queued as a Reindex_Job; rebuilt as a Sync_Job it would fail forever for lack of post_ids.
	 *
	 * @param string[] $batch_statuses How each of the failed reindex's batches ended.
	 */
	#[DataProvider( 'unreliable_batches_provider' )]
	public function test_retry_restarts_a_reindex_whose_batches_cant_be_kept( array $batch_statuses ): void {
		$this->set_up_governing_site();
		$paths = [];
		$this->mock_algolia_http_client( $paths );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$job = $this->create_failed_reindex_with_batches( $batch_statuses );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$this->assertSame( 200, $response->get_status() );
		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $status['status'], 'The reindex must be re-queued, not marked completed.' );
		$this->assertSame( [], $status['child_ids'] );

		$action = ( new Job_Repository() )->get_action( $job->get_id() );
		$this->scheduler->execute_job( $action['args']['job_class'], $job->get_id(), 0 );

		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( Abstract_Job::STATUS_RUNNING, $status['status'] );
		$this->assertNull( $status['error'] );
		$this->assertCount( 1, $status['child_ids'], 'The re-run creates its own batches.' );
	}

	/**
	 * Failed reindexes whose batches indexed nothing reliable.
	 *
	 * Cancelled batches mean scheduling was cut short, so the later batches were never created even if some indexed.
	 *
	 * @return array<string, array{0: string[]}>
	 */
	public static function unreliable_batches_provider(): array {
		return [
			'failed before creating any batch'           => [ [] ],
			'every batch failed'                         => [ [ Abstract_Job::STATUS_FAILED, Abstract_Job::STATUS_FAILED ] ],
			'failed while scheduling, batches cancelled' => [ [ Abstract_Job::STATUS_CANCELLED, Abstract_Job::STATUS_CANCELLED ] ],
			'cut short after a batch indexed'            => [ [ Abstract_Job::STATUS_COMPLETED, Abstract_Job::STATUS_CANCELLED ] ],
		];
	}

	/**
	 * Retrying a partly failed governing run re-runs only its failed batch, and once that's done the run records its
	 * totals afresh, rather than keeping the ones from before the retry.
	 */
	public function test_retry_reruns_only_failed_batches_and_records_fresh_totals(): void {
		$this->set_up_governing_site();
		$paths = [];
		$this->mock_algolia_http_client( $paths );

		$job                                = $this->create_failed_reindex_with_batches( [ Abstract_Job::STATUS_COMPLETED, Abstract_Job::STATUS_FAILED ] );
		[ $completed_batch, $failed_batch ] = $job->get_child_ids();
		( new Job_Repository() )->reset_counters( $job->get_id(), 2, 1, 0 );
		$this->run_on_brand_site( $job->get_id(), Abstract_Job::STATUS_COMPLETED, 3 );

		// Totals recorded when the run failed, before the retry.
		$data                               = $this->scheduler->get_status( $job->get_id() );
		$data['children_completed']         = 1;
		$data['data']['_remote_aggregated'] = true;
		( new Job_Repository() )->upsert( $data );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $this->scheduler->get_status( $completed_batch )['status'], 'A batch that indexed is not re-run.' );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $this->scheduler->get_status( $failed_batch )['status'] );

		$this->scheduler->execute_job( Sync_Job::class, $failed_batch );

		$parent = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $parent['status'] );
		$this->assertSame( 5, $parent['data']['total_batches'] );
		$this->assertSame( 5, $parent['children_completed'], '2 batches here and 3 on the brand site.' );
	}

	/**
	 * A run left as failed though all its batches completed is corrected to completed when retried.
	 */
	public function test_retry_corrects_a_failed_run_whose_batches_all_completed(): void {
		$job = $this->create_failed_reindex_with_batches( [ Abstract_Job::STATUS_COMPLETED ] );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $this->scheduler->get_status( $job->get_id() )['status'] );
	}

	/**
	 * Correcting a governing run whose batches here all finished waits for its brand sites, which may be retrying their own.
	 */
	public function test_correcting_a_governing_run_waits_for_its_brand_sites(): void {
		$job = $this->create_failed_reindex_with_batches( [ Abstract_Job::STATUS_COMPLETED, Abstract_Job::STATUS_COMPLETED ] );
		( new Job_Repository() )->reset_counters( $job->get_id(), 2, 0, 0 );
		$this->run_on_brand_site( $job->get_id(), Abstract_Job::STATUS_RUNNING, 1 );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Waiting for the brand sites', $response->get_data()['message'] );
		$this->assertSame( Abstract_Job::STATUS_RUNNING, $this->scheduler->get_status( $job->get_id() )['status'] );

		$this->brand_job['status'] = Abstract_Job::STATUS_COMPLETED;

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/jobs/' . $job->get_id() ) )->get_data();

		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $data['job']['status'] );
	}

	/**
	 * Retrying a failed batch of a run the user cancelled is refused, since it would reopen the run.
	 */
	public function test_retry_refuses_a_batch_of_a_cancelled_run(): void {
		$job          = $this->create_cancelled_reindex_with_batches( [ Abstract_Job::STATUS_FAILED ] );
		[ $batch_id ] = $job->get_child_ids();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $batch_id . '/retry' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_retry_cancelled', $response->get_data()['code'] );
		$this->assertSame( Abstract_Job::STATUS_CANCELLED, $this->scheduler->get_status( $job->get_id() )['status'] );
		$this->assertSame( Abstract_Job::STATUS_FAILED, $this->scheduler->get_status( $batch_id )['status'] );
	}

	/**
	 * Loading the history keeps a run the user cancelled as cancelled, with no retry, even when one of its batches had
	 * failed before the cancel.
	 */
	public function test_history_keeps_a_cancelled_run_cancelled(): void {
		$job = $this->create_cancelled_reindex_with_batches( [ Abstract_Job::STATUS_COMPLETED, Abstract_Job::STATUS_FAILED ] );
		$this->run_on_brand_site( $job->get_id(), Abstract_Job::STATUS_COMPLETED, 1 );

		$history = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/jobs/history' ) )->get_data();
		$jobs    = array_column( $history['jobs'], null, 'id' );

		$this->assertSame( Abstract_Job::STATUS_CANCELLED, $jobs[ $job->get_id() ]['status'] );
		$this->assertSame( Abstract_Job::STATUS_CANCELLED, $this->scheduler->get_status( $job->get_id() )['status'], 'The stored run must stay cancelled too.' );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Loading the history keeps a reindex that failed while scheduling its batches as failed.
	 *
	 * Failing cancels the batches it had queued. Reading those as a cancellation would store the run as
	 * cancelled on a governing site, which can't be retried, so it could never be restarted.
	 */
	public function test_history_keeps_a_reindex_that_failed_while_scheduling_retryable(): void {
		$job = $this->create_failed_reindex_with_batches( [ Abstract_Job::STATUS_CANCELLED, Abstract_Job::STATUS_CANCELLED ] );
		$this->run_on_brand_site( $job->get_id(), Abstract_Job::STATUS_COMPLETED, 1 );

		$history = $this->server->dispatch( new WP_REST_Request( 'GET', '/onesearch/v1/jobs/history' ) )->get_data();
		$jobs    = array_column( $history['jobs'], null, 'id' );

		$this->assertSame( Abstract_Job::STATUS_FAILED, $jobs[ $job->get_id() ]['status'] );
		$this->assertSame( Abstract_Job::STATUS_FAILED, $this->scheduler->get_status( $job->get_id() )['status'], 'The stored run must stay failed too.' );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $this->scheduler->get_status( $job->get_id() )['status'], 'The run has to restart from scratch.' );
	}

	/**
	 * The /jobs/history endpoint reports a parent job as failed when any child failed.
	 */
	public function test_history_marks_parent_failed_when_child_failed(): void {
		$parent = new Reindex_Job();
		$child  = new Sync_Job();

		$child->set_parent_id( $parent->get_id() );
		$child->set_data( [ 'post_ids' => [ 1 ] ] );
		$child->fail( 'Permanent batch failure.' );
		$this->scheduler->persist_job( $child );

		$parent->set_child_ids( [ $child->get_id() ] );
		$parent->set_progress_total( 1 );
		$parent->mark_completed();
		$this->scheduler->persist_job( $parent );

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/history' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$jobs = array_column( $data['jobs'], null, 'id' );
		$this->assertArrayHasKey( $parent->get_id(), $jobs );
		$this->assertSame( Abstract_Job::STATUS_FAILED, $jobs[ $parent->get_id() ]['status'] );
		$this->assertSame( 1, $jobs[ $parent->get_id() ]['children_failed'] );
	}

	/**
	 * The history pages through finished runs only; one still in progress shows in the progress view instead.
	 */
	public function test_history_pages_through_finished_runs_only(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$job = new Sync_Job();
			$job->set_data( [ 'post_ids' => [ 1 ] ] );
			$job->mark_completed();
			$this->scheduler->persist_job( $job );
		}

		$running = new Sync_Job();
		$running->set_data( [ 'post_ids' => [ 1 ] ] );
		$running->mark_running();
		$this->scheduler->persist_job( $running );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/history' );
		$request->set_param( 'page', 2 );
		$request->set_param( 'per_page', 2 );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 1, $data['jobs'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 2, $data['total_pages'] );
	}

	/**
	 * The governing site's proxies to brand-site jobs only ever reach registered brand sites, and pass on a brand
	 * site's refusal rather than reporting it as success.
	 */
	public function test_remote_job_proxies_only_reach_registered_brand_sites(): void {
		$requested = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requested ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
				$requested[] = $url;

				return [
					'response' => [
						'code'    => 404,
						'message' => 'Not Found',
					],
					'body'     => (string) wp_json_encode(
						[
							'code'    => 'onesearch_job_not_found',
							'message' => 'Job not found.',
						]
					),
					'headers'  => [],
					'cookies'  => [],
				];
			},
			10,
			3
		);

		$routes    = [
			'remote-status' => 'GET',
			'remote-retry'  => 'POST',
			'remote-cancel' => 'POST',
		];
		$responses = [];
		foreach ( $routes as $route => $method ) {
			$request = new WP_REST_Request( $method, '/onesearch/v1/jobs/' . $route );
			$request->set_param( 'site_url', 'https://unknown.example.com' );
			$request->set_param( 'job_id', 'some_job_id' );
			$responses[ $route ] = $this->server->dispatch( $request );
		}

		$this->assertSame( [], $requested, 'Nothing is sent to a site that is not a registered brand site.' );
		$this->assertSame( 'onesearch_unknown_site', $responses['remote-status']->get_data()['code'] );
		$this->assertSame( 'onesearch_unknown_site', $responses['remote-retry']->get_data()['code'] );
		$this->assertSame( 200, $responses['remote-cancel']->get_status(), 'Cancelling still clears a run whose brand site is gone.' );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.example.com/',
					'api_key' => 'brand-key',
				],
			]
		);

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/remote-cancel' );
		$request->set_param( 'site_url', 'https://brand.example.com/' );
		$request->set_param( 'job_id', 'brand-job' );
		$response = $this->server->dispatch( $request );

		$this->assertCount( 1, $requested );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'Job not found.', $response->get_data()['message'] );
	}

	/**
	 * Create a Sync_Job and schedule it for testing.
	 */
	private function create_scheduled_job(): Sync_Job {
		$job = new Sync_Job();
		$job->set_data( [ 'post_ids' => [ 1 ] ] );
		$job->set_max_retries( 2 );
		$this->scheduler->schedule( $job );
		return $job;
	}

	/**
	 * Persist a Reindex_Job the user cancelled after its batches ended in the given statuses.
	 *
	 * @param string[] $batch_statuses One terminal status per batch.
	 */
	private function create_cancelled_reindex_with_batches( array $batch_statuses ): Reindex_Job {
		$job = $this->create_failed_reindex_with_batches( $batch_statuses );

		$data           = $this->scheduler->get_status( $job->get_id() );
		$data['status'] = Abstract_Job::STATUS_CANCELLED;
		$data['error']  = null;
		( new Job_Repository() )->upsert( $data );

		return $job;
	}

	/**
	 * Persist a failed Reindex_Job whose batches ended in the given statuses.
	 *
	 * @param string[] $batch_statuses One terminal status per batch.
	 */
	private function create_failed_reindex_with_batches( array $batch_statuses ): Reindex_Job {
		$job = new Reindex_Job();
		$job->set_data( [ 'post_types' => [ 'post' ] ] );

		foreach ( $batch_statuses as $i => $batch_status ) {
			$batch = new Sync_Job();
			$batch->set_data( [ 'post_ids' => [ $i + 1 ] ] );
			$batch->set_parent_id( $job->get_id() );
			if ( Abstract_Job::STATUS_FAILED === $batch_status ) {
				$batch->fail( 'Permanent batch failure.' );
			} else {
				$batch->set_status( $batch_status );
			}
			$this->scheduler->persist_job( $batch );
			$job->add_child_id( $batch->get_id() );
		}

		$job->set_progress_total( count( $batch_statuses ) );
		$job->fail( 'Simulated reindex failure.' );
		$this->scheduler->persist_job( $job );

		return $job;
	}

	/**
	 * Record a run as having also started on a brand site, whose job the mocked brand site reports.
	 *
	 * @param string $job_id        The governing site's job ID.
	 * @param string $brand_status  The brand job's status.
	 * @param int    $brand_batches The brand job's batch count.
	 */
	private function run_on_brand_site( string $job_id, string $brand_status, int $brand_batches ): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.example.com/',
					'api_key' => 'brand-key',
				],
			]
		);

		$this->brand_job = [
			'status'             => $brand_status,
			'children_total'     => $brand_batches,
			'children_completed' => Abstract_Job::STATUS_COMPLETED === $brand_status ? $brand_batches : 0,
			'children_failed'    => 0,
		];
		add_filter( 'pre_http_request', [ $this, 'mock_brand_job' ], 10, 3 );

		$data                  = $this->scheduler->get_status( $job_id );
		$data['data']['sites'] = [
			[
				'site_url'    => get_site_url(),
				'job_id'      => $job_id,
				'batch_count' => count( $data['child_ids'] ),
			],
			[
				'site_url'    => 'https://brand.example.com/',
				'job_id'      => 'brand-job',
				'batch_count' => $brand_batches,
			],
		];
		( new Job_Repository() )->upsert( $data );
	}

	/**
	 * Answers the brand site's job requests with $this->brand_job.
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt Whether to preempt the request.
	 * @param array<string, mixed>                 $args    The request arguments.
	 * @param string                               $url     The request URL.
	 * @return false|array<string, mixed>|\WP_Error
	 */
	public function mock_brand_job( $preempt, $args, $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
		if ( ! str_contains( $url, 'brand.example.com' ) ) {
			return $preempt;
		}

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => (string) wp_json_encode(
				[
					'success' => true,
					'job'     => $this->brand_job,
				]
			),
			'headers'  => [],
			'cookies'  => [],
		];
	}
}
