<?php
/**
 * Tests for Job_REST_Controller.
 *
 * @package OneSearch\Tests\Unit\Modules\Scheduler
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Unit\Modules\Rest;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Jobs\Reindex_Job;
use OneSearch\Modules\Jobs\Sync_Job;
use OneSearch\Modules\Scheduler\Job_REST_Controller;
use OneSearch\Modules\Scheduler\Job_Scheduler;
use OneSearch\Modules\Settings\Settings;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * REST endpoint tests for {@see Job_REST_Controller}.
 */
#[CoversClass( Job_REST_Controller::class )]
#[CoversClass( Job_Scheduler::class )]
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
	 * The /jobs endpoint returns an array of active jobs.
	 */
	public function test_list_jobs_returns_array(): void {
		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['jobs'] );
	}

	/**
	 * The /jobs endpoint includes a job after scheduling.
	 */
	public function test_list_jobs_includes_scheduled_job(): void {
		$job = $this->create_scheduled_job();

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$ids = array_column( $data['jobs'], 'id' );
		$this->assertContains( $job->get_id(), $ids );
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
	 * POST /jobs/{id}/retry allows token-authenticated remote requests.
	 */
	public function test_retry_job_allows_token_authenticated_request(): void {
		$api_key = Settings::regenerate_api_key();
		$job     = new Sync_Job();

		$job->set_data( [ 'post_ids' => [ 1 ] ] );
		$job->fail( 'Permanent batch failure.' );
		$this->scheduler->persist_job( $job );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/onesearch/v1/jobs/' . $job->get_id() . '/retry' );
		$request->set_header( 'X-OneSearch-Token', $api_key );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $job->get_id(), $data['job_id'] );
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

		$request  = new WP_REST_Request( 'GET', '/onesearch/v1/jobs' );
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
}
