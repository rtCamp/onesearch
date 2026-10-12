<?php
/**
 * Tests for Job_REST_Controller.
 *
 * @package OneSearch\Tests\Unit\Modules\Scheduler
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

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

		parent::tear_down();
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
	 * GET /jobs/{id} returns the job state.
	 */
	public function test_get_job_returns_status(): void {
		$job = $this->create_scheduled_job();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/' . $job->get_id() );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $job->get_id(), $data['job']['id'] );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $data['job']['status'] );
	}

	/**
	 * GET /jobs/{id} returns 404 for an unknown job ID.
	 */
	public function test_get_job_returns_404_for_unknown_id(): void {
		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/nonexistent_id_12345' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * POST /jobs/{id}/retry is only allowed for failed jobs.
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
	 * POST /jobs/{id}/retry returns 404 for an unknown job.
	 */
	public function test_retry_job_returns_404_for_unknown_id(): void {
		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/nonexistent_id_12345/retry' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A reindex that failed before creating any batch has to be retried as a
	 * Reindex_Job; rebuilt as a Sync_Job it would fail forever for lack of post_ids.
	 */
	public function test_retry_failed_reindex_without_batches_requeues_reindex_job(): void {
		$job = $this->create_failed_reindex_job();

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( 'reindex', $status['type'] );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $status['status'] );

		$action = ( new Job_Repository() )->get_action( $job->get_id() );
		$this->assertSame( Reindex_Job::class, $action['args']['job_class'] );
	}

	/**
	 * Running the re-queued action rebuilds the batches instead of failing.
	 */
	public function test_retried_reindex_job_schedules_batches_when_run(): void {
		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$job = $this->create_failed_reindex_job();

		$this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$action = ( new Job_Repository() )->get_action( $job->get_id() );
		$this->scheduler->execute_job( $action['args']['job_class'], $job->get_id(), 0 );

		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( 'reindex', $status['type'] );
		$this->assertSame( Abstract_Job::STATUS_RUNNING, $status['status'] );
		$this->assertNull( $status['error'] );
		$this->assertCount( 1, $status['child_ids'] );
	}

	/**
	 * A job whose stored type isn't registered is refused and left untouched.
	 */
	public function test_retry_returns_400_for_unregistered_job_type(): void {
		$job  = $this->create_failed_reindex_job();
		$data = $this->scheduler->get_status( $job->get_id() );

		$data['type'] = 'unregistered';
		( new Job_Repository() )->upsert( $data );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_retry_unknown_type', $response->get_data()['code'] );
		$this->assertSame( Abstract_Job::STATUS_FAILED, $this->scheduler->get_status( $job->get_id() )['status'] );
	}

	/**
	 * A reindex that failed partway through scheduling cancels the batches it had
	 * created. Retrying must re-run it from scratch rather than count those
	 * cancelled batches as done and mark the job completed.
	 */
	public function test_retry_reindex_that_failed_mid_scheduling_restarts_it(): void {
		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$job = new Reindex_Job();
		$job->set_data( [ 'post_types' => [ 'post' ] ] );
		$job->mark_running();
		foreach ( [ 1, 2 ] as $post_id ) {
			$batch = new Sync_Job();
			$batch->set_data( [ 'post_ids' => [ $post_id ] ] );
			$batch->set_parent_id( $job->get_id() );
			$this->scheduler->schedule( $batch );
			$job->add_child_id( $batch->get_id() );
		}
		foreach ( $job->get_child_ids() as $batch_id ) {
			$this->scheduler->cancel( $batch_id );
		}
		$job->fail( 'Failed to schedule child Sync_Job: simulated' );
		$this->scheduler->persist_job( $job );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$this->assertSame( 200, $response->get_status() );
		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $status['status'], 'The reindex must be re-queued, not marked completed.' );
		$this->assertSame( [], $status['child_ids'] );
		$this->assertSame( 0, $status['children_completed'] );

		$action = ( new Job_Repository() )->get_action( $job->get_id() );
		$this->scheduler->execute_job( $action['args']['job_class'], $job->get_id(), 0 );

		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( Abstract_Job::STATUS_RUNNING, $status['status'] );
		$this->assertCount( 1, $status['child_ids'], 'The re-run creates its own batches.' );
	}

	/**
	 * Batches that all failed indexed nothing, so the reindex is re-run from scratch.
	 */
	public function test_retry_reindex_whose_batches_all_failed_restarts_it(): void {
		$job = $this->create_failed_reindex_with_batches( [ Abstract_Job::STATUS_FAILED, Abstract_Job::STATUS_FAILED ] );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Job restarted from the beginning.', $response->get_data()['message'] );
		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $status['status'] );
		$this->assertSame( [], $status['child_ids'] );
	}

	/**
	 * Cancelled batches mean scheduling was cut short, so the reindex is re-run from
	 * scratch even though some batches had indexed: the later batches were never created.
	 */
	public function test_retry_reindex_cut_short_after_some_batches_indexed_restarts_it(): void {
		$job = $this->create_failed_reindex_with_batches( [ Abstract_Job::STATUS_COMPLETED, Abstract_Job::STATUS_CANCELLED ] );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Job restarted from the beginning.', $response->get_data()['message'] );
		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $status['status'] );
		$this->assertSame( [], $status['child_ids'] );
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
	 * Persist a Reindex_Job that failed before it scheduled any batch.
	 */
	private function create_failed_reindex_job(): Reindex_Job {
		$job = new Reindex_Job();
		$job->set_data(
			[
				'post_types' => [ 'post' ],
				'batch_size' => 100,
			]
		);
		$job->mark_running();
		$this->scheduler->persist_job( $job );
		$job->fail( 'Failed before any batch was scheduled.' );
		$this->scheduler->persist_job( $job );

		return $job;
	}

	/**
	 * POST /jobs/{id}/retry retries only failed child batches for a parent job.
	 */
	public function test_retry_parent_job_retries_failed_children_only(): void {
		$parent          = new Reindex_Job();
		$completed_child = new Sync_Job();
		$failed_child    = new Sync_Job();

		$completed_child->set_parent_id( $parent->get_id() );
		$completed_child->set_data( [ 'post_ids' => [ 1 ] ] );
		$completed_child->mark_completed();
		$this->scheduler->persist_job( $completed_child );

		$failed_child->set_parent_id( $parent->get_id() );
		$failed_child->set_data( [ 'post_ids' => [ 2 ] ] );
		$failed_child->fail( 'Permanent batch failure.' );
		$this->scheduler->persist_job( $failed_child );

		$parent->set_child_ids( [ $completed_child->get_id(), $failed_child->get_id() ] );
		$parent->set_progress_total( 2 );
		$parent->fail( '1/2 child batches failed' );
		$this->scheduler->persist_job( $parent );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $parent->get_id() . '/retry' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['retried'] );
		$this->assertSame( [ $failed_child->get_id() ], $data['child_ids'] );

		$parent_status          = $this->scheduler->get_status( $parent->get_id() );
		$completed_child_status = $this->scheduler->get_status( $completed_child->get_id() );
		$failed_child_status    = $this->scheduler->get_status( $failed_child->get_id() );

		$this->assertSame( Abstract_Job::STATUS_RUNNING, $parent_status['status'] );
		$this->assertSame( 1, $parent_status['children_completed'] );
		$this->assertSame( 0, $parent_status['children_failed'] );
		$this->assertNull( $parent_status['finished_at'] );
		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $completed_child_status['status'] );
		$this->assertSame( Abstract_Job::STATUS_PENDING, $failed_child_status['status'] );
	}

	/**
	 * DELETE /jobs/{id} cancels a running job.
	 */
	public function test_cancel_job_marks_it_as_cancelled(): void {
		$job = $this->create_scheduled_job();

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/jobs/' . $job->get_id() );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify the job is now cancelled.
		$status = $this->scheduler->get_status( $job->get_id() );
		$this->assertSame( Abstract_Job::STATUS_CANCELLED, $status['status'] );
	}

	/**
	 * DELETE /jobs/{id} returns 404 for an unknown job.
	 */
	public function test_cancel_job_returns_404_for_unknown_id(): void {
		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/jobs/nonexistent_id_12345' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * The /jobs/history endpoint returns paginated results.
	 */
	public function test_history_returns_paginated_response(): void {
		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/history' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['jobs'] );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'page', $data );
		$this->assertArrayHasKey( 'total_pages', $data );
	}

	/**
	 * The /jobs history endpoint respects per_page parameter.
	 */
	public function test_history_respects_per_page(): void {
		// Create a few jobs to populate history.
		for ( $i = 0; $i < 3; $i++ ) {
			$job = $this->create_scheduled_job();
			// Mark them as completed so they appear in history.
			$job->mark_completed();
			$this->scheduler->persist_job( $job );
		}

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/history' );
		$request->set_param( 'per_page', 2 );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 2, count( $data['jobs'] ) );
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
	 * The /jobs/history endpoint includes parent jobs stored as failed.
	 */
	public function test_history_includes_failed_parent_jobs(): void {
		$job = new Reindex_Job();
		$job->set_data( [ 'post_types' => [ 'post' ] ] );
		$job->fail( 'Permanent parent failure.' );
		$this->scheduler->persist_job( $job );

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/history' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$jobs = array_column( $data['jobs'], null, 'id' );
		$this->assertArrayHasKey( $job->get_id(), $jobs );
		$this->assertSame( Abstract_Job::STATUS_FAILED, $jobs[ $job->get_id() ]['status'] );
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
	 * The /jobs/{id}/children endpoint returns child jobs.
	 */
	public function test_get_children_returns_array(): void {
		$job = $this->create_scheduled_job();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/' . $job->get_id() . '/children' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['children'] );
	}

	/**
	 * DELETE /jobs/{id} returns 400 when the job is already in a terminal state.
	 */
	public function test_cancel_terminal_job_returns_400(): void {
		$job = new Sync_Job();
		$job->set_data( [ 'post_ids' => [ 1 ] ] );
		$job->mark_completed();
		$this->scheduler->persist_job( $job );

		$request  = new WP_REST_Request( 'DELETE', '/onesearch/v1/jobs/' . $job->get_id() );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_job_terminal', $data['code'] );
	}

	/**
	 * GET /jobs/{id}/children returns 404 for an unknown job.
	 */
	public function test_get_children_returns_404_for_unknown_id(): void {
		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/nonexistent_id_12345/children' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * GET /jobs/{id}/children includes persisted child job statuses.
	 */
	public function test_get_children_includes_child_jobs(): void {
		$parent = new Reindex_Job();
		$child  = new Sync_Job();

		$child->set_parent_id( $parent->get_id() );
		$child->set_data( [ 'post_ids' => [ 1 ] ] );
		$this->scheduler->schedule( $child );

		$parent->set_child_ids( [ $child->get_id() ] );
		$this->scheduler->persist_job( $parent );

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/' . $parent->get_id() . '/children' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertContains( $child->get_id(), array_column( $data['children'], 'id' ) );
	}

	/**
	 * POST /jobs/{id}/retry on a failed child batch resets the parent job to running.
	 */
	public function test_retry_single_failed_child_resets_parent_to_running(): void {
		$parent = new Reindex_Job();
		$child  = new Sync_Job();

		$child->set_parent_id( $parent->get_id() );
		$child->set_data( [ 'post_ids' => [ 1 ] ] );
		$child->fail( 'Permanent batch failure.' );
		$this->scheduler->persist_job( $child );

		$parent->set_child_ids( [ $child->get_id() ] );
		$parent->set_progress_total( 1 );
		$parent->fail( '1/1 child batches failed' );
		$this->scheduler->persist_job( $parent );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $child->get_id() . '/retry' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		$parent_status = $this->scheduler->get_status( $parent->get_id() );
		$this->assertSame( Abstract_Job::STATUS_RUNNING, $parent_status['status'] );
	}

	/**
	 * POST /jobs/{id}/retry reconciles a parent stuck as failed when all children actually completed.
	 */
	public function test_retry_parent_reconciles_stale_failed_status(): void {
		$parent = new Reindex_Job();
		$child  = new Sync_Job();

		$child->set_parent_id( $parent->get_id() );
		$child->set_data( [ 'post_ids' => [ 1 ] ] );
		$child->mark_completed();
		$this->scheduler->persist_job( $child );

		$parent->set_child_ids( [ $child->get_id() ] );
		$parent->set_progress_total( 1 );
		$parent->fail( 'Stale failure.' );
		$this->scheduler->persist_job( $parent );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $parent->get_id() . '/retry' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 0, $data['retried'] );

		$parent_status = $this->scheduler->get_status( $parent->get_id() );
		$this->assertSame( Abstract_Job::STATUS_COMPLETED, $parent_status['status'] );
	}

	/**
	 * POST /jobs/{id}/retry returns 400 when the parent has no retryable or completed children.
	 */
	public function test_retry_parent_returns_400_when_no_retryable_children(): void {
		$parent = new Reindex_Job();
		$parent->set_child_ids( [ 'ghost_child_aaa', 'ghost_child_bbb' ] );
		$parent->set_progress_total( 2 );
		$parent->fail( 'All children gone.' );
		$this->scheduler->persist_job( $parent );

		$request  = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $parent->get_id() . '/retry' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'onesearch_retry_no_failed_children', $data['code'] );
	}

	/**
	 * GET /jobs/{id} allows token-authenticated remote requests.
	 */
	public function test_get_job_allows_token_authenticated_read(): void {
		$api_key = Settings::regenerate_api_key();
		$job     = new Sync_Job();
		$job->set_data( [ 'post_ids' => [ 1 ] ] );
		$job->mark_running();
		$this->scheduler->persist_job( $job );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/' . $job->get_id() );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $job->get_id(), $data['job']['id'] );
	}

	/**
	 * GET /jobs/remote-status returns 404 when the site_url is not in shared sites.
	 */
	public function test_remote_status_returns_404_for_unknown_site(): void {
		$request = new WP_REST_Request( 'GET', '/onesearch/v1/jobs/remote-status' );
		$request->set_param( 'site_url', 'https://unknown.example.com' );
		$request->set_param( 'job_id', 'some_job_id' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'onesearch_unknown_site', $data['code'] );
	}

	/**
	 * POST /jobs/remote-retry returns 404 when the site_url is not in shared sites.
	 */
	public function test_remote_retry_returns_404_for_unknown_site(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/remote-retry' );
		$request->set_param( 'site_url', 'https://unknown.example.com' );
		$request->set_param( 'job_id', 'some_job_id' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'onesearch_unknown_site', $data['code'] );
	}

	/**
	 * POST /jobs/remote-cancel returns success with a warning when the site is unreachable.
	 */
	public function test_remote_cancel_returns_success_with_warning_for_unknown_site(): void {
		$request = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/remote-cancel' );
		$request->set_param( 'site_url', 'https://unknown.example.com' );
		$request->set_param( 'job_id', 'some_job_id' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'warning', $data );
	}
}
