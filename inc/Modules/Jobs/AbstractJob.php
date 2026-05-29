<?php
/**
 * Base class for all async jobs in OneSearch.
 *
 * @package OneSearch\Modules\Jobs
 */

declare( strict_types = 1 );

namespace OneSearch\Modules\Jobs;

/**
 * Abstract base class for asynchronous jobs using Action Scheduler.
 *
 * Provides the full job lifecycle: status tracking, progress updates,
 * parent/child relationships for composite jobs, retry logic with
 * exponential backoff, and serialization for persistence.
 *
 * Lifecycle states: PENDING → RUNNING → COMPLETED | FAILED | CANCELLED
 *
 * Storage strategy (managed by JobScheduler):
 *   - Active jobs (PENDING/RUNNING) are stored in WordPress transients,
 *     auto-expiring after 12 hours.
 *   - Terminal jobs (COMPLETED/FAILED/CANCELLED) are moved to wp_options
 *     for permanent storage.
 *   - get_status() checks transient first, then falls back to wp_options.
 *
 * @see \OneSearch\Modules\Scheduler\JobScheduler::execute_job()  Where jobs are dispatched by Action Scheduler
 * @see \OneSearch\Modules\Scheduler\JobScheduler::schedule()     Where jobs are enqueued
 * @see \OneSearch\Modules\Jobs\SyncJob                           Concrete leaf job — syncs posts to Algolia
 * @see \OneSearch\Modules\Jobs\ReindexJob                        Concrete parent job — chunks posts into SyncJobs
 */
abstract class AbstractJob {
	/**
	 * Job is waiting to be processed.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Job is currently being processed.
	 *
	 * @var string
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Job completed successfully.
	 *
	 * @var string
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Job failed and may be retried.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Job was cancelled by user action.
	 *
	 * @var string
	 */
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Unique job identifier (e.g. "job_662f3a1b2c4d1").
	 *
	 * @var string
	 */
	protected string $id;

	/**
	 * Current lifecycle state of the job.
	 *
	 * @var string One of the STATUS_* constants.
	 */
	protected string $status = self::STATUS_PENDING;

	/**
	 * Number of units completed so far (e.g. posts synced, batches done).
	 *
	 * @var int
	 */
	protected int $progress = 0;

	/**
	 * Total number of units to complete. Defaults to 100.
	 *
	 * @var int
	 */
	protected int $progress_total = 100;

	/**
	 * Error message if the job failed, null otherwise.
	 *
	 * @var string|null
	 */
	protected ?string $error = null;

	/**
	 * Arbitrary data payload for the job (e.g. post_ids, credentials).
	 *
	 * @var array<string, mixed>
	 */
	protected array $data = [];

	/**
	 * Maximum number of retry attempts after the initial run.
	 * With max_retries=3, the job can run up to 4 times total.
	 *
	 * @var int
	 */
	protected int $max_retries = 3;

	/**
	 * Current retry attempt number (0 = first run, 1 = first retry, etc.).
	 *
	 * @var int
	 */
	protected int $retry_count = 0;

	/**
	 * Base delay in seconds between retries. Actual delay = retry_delay_seconds × retry_count.
	 *
	 * @var int
	 */
	protected int $retry_delay_seconds = 60;

	/**
	 * Action Scheduler group name for this job. Used for filtering and cancellation.
	 * Prefixed with "onesearch_" when registering with Action Scheduler.
	 *
	 * @var string
	 */
	protected string $group = 'default';

	/**
	 * ID of the parent job, if this is a child job in a composite workflow.
	 * Set by ReindexJob when creating SyncJob children.
	 *
	 * @var string|null
	 */
	protected ?string $parent_id = null;

	/**
	 * IDs of all child jobs spawned by this parent job.
	 * Used by ReindexJob to track its SyncJob children.
	 *
	 * @var string[]
	 */
	protected array $child_ids = [];

	/**
	 * Number of child jobs that have completed (success or failure).
	 * Incremented by JobScheduler::notify_parent() when a child finishes.
	 *
	 * @var int
	 */
	protected int $children_completed = 0;

	/**
	 * Unix timestamp when the job was created.
	 *
	 * @var int
	 */
	protected int $created_at;

	/**
	 * Unix timestamp when the job was last updated.
	 *
	 * @var int
	 */
	protected int $updated_at;

	/**
	 * Initialize a new job with a unique ID and current timestamps.
	 *
	 * Called when creating a job programmatically before scheduling.
	 * When reconstructing from storage, from_array() bypasses this
	 * via ReflectionClass::newInstanceWithoutConstructor().
	 */
	public function __construct() {
		$this->id         = uniqid( 'job_', true );
		$this->created_at = time();
		$this->updated_at = time();
	}

	/**
	 * Execute the job's concrete logic.
	 *
	 * Called by JobScheduler::execute_job() after the job has been
	 * loaded from storage and marked as RUNNING. Implementations
	 * should call update_progress() as they process units of work.
	 *
	 * Throwing an exception triggers the retry mechanism in execute_job().
	 *
	 * @throws \Throwable On any failure; caught by JobScheduler for retry logic.
	 */
	abstract public function handle(): void;

	/**
	 * Get the unique job identifier.
	 *
	 * @return string The job ID (e.g. "job_662f3a1b2c4d1").
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get the current lifecycle status.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Get the number of completed work units.
	 *
	 * @return int E.g. 5 if 5 out of 10 posts have been synced.
	 */
	public function get_progress(): int {
		return $this->progress;
	}

	/**
	 * Get the total number of work units to complete.
	 *
	 * @return int E.g. 10 if there are 10 posts to sync.
	 */
	public function get_progress_total(): int {
		return $this->progress_total;
	}

	/**
	 * Get the progress as a percentage (0.0–100.0).
	 *
	 * @return float Rounded to 1 decimal place. Returns 0 if progress_total is <= 0.
	 */
	public function get_progress_percent(): float {
		if ( $this->progress_total <= 0 ) {
			return 0;
		}

		return round( $this->progress / $this->progress_total * 100, 1 );
	}

	/**
	 * Get the error message if the job has failed.
	 *
	 * @return string|null The error message, or null if the job hasn't failed.
	 */
	public function get_error(): ?string {
		return $this->error;
	}

	/**
	 * Get the Action Scheduler group for this job.
	 *
	 * @return string The group name (e.g. "reindex", "reindex_job_cba321").
	 */
	public function get_group(): string {
		return $this->group;
	}

	/**
	 * Get the maximum number of retry attempts allowed.
	 *
	 * @return int Number of retries after the initial run (0 = no retries).
	 */
	public function get_max_retries(): int {
		return $this->max_retries;
	}

	/**
	 * Get the current retry attempt number.
	 *
	 * @return int 0 = first run, 1 = first retry, etc.
	 */
	public function get_retry_count(): int {
		return $this->retry_count;
	}

	/**
	 * Get the base delay in seconds between retry attempts.
	 *
	 * Actual delay is exponential: retry_delay_seconds × retry_count.
	 *
	 * @return int Seconds.
	 */
	public function get_retry_delay_seconds(): int {
		return $this->retry_delay_seconds;
	}

	/**
	 * Get the parent job ID, if this is a child job.
	 *
	 * @return string|null The parent's job ID, or null for top-level jobs.
	 */
	public function get_parent_id(): ?string {
		return $this->parent_id;
	}

	/**
	 * Get all child job IDs spawned by this parent job.
	 *
	 * @return string[] Array of child job IDs.
	 */
	public function get_child_ids(): array {
		return $this->child_ids;
	}

	/**
	 * Get the number of child jobs that have finished (completed or failed).
	 *
	 * @return int Number of finished children.
	 */
	public function get_children_completed(): int {
		return $this->children_completed;
	}

	/**
	 * Get the Unix timestamp when the job was created.
	 *
	 * @return int Unix timestamp.
	 */
	public function get_created_at(): int {
		return $this->created_at;
	}

	/**
	 * Get the Unix timestamp when the job was last updated.
	 *
	 * @return int Unix timestamp.
	 */
	public function get_updated_at(): int {
		return $this->updated_at;
	}

	/**
	 * Get the job's data payload.
	 *
	 * @return array<string, mixed> The data payload.
	 */
	public function get_data(): array {
		return $this->data;
	}

	/**
	 * Set the job ID. Used when reconstructing a job from storage.
	 *
	 * @param string $id The job identifier.
	 * @return $this
	 */
	public function set_id( string $id ): self {
		$this->id = $id;
		return $this;
	}

	/**
	 * Set the lifecycle status and touch the updated_at timestamp.
	 *
	 * Called by JobScheduler during scheduling (→ pending), execution
	 * (→ running), and cancellation (→ cancelled). For completed/failed
	 * states, use mark_completed()/fail() instead.
	 *
	 * @param string $status One of the STATUS_* constants.
	 */
	public function set_status( string $status ): void {
		$this->status     = $status;
		$this->updated_at = time();
	}

	/**
	 * Set the current progress value, clamped to [0, progress_total].
	 *
	 * @param int $progress Number of completed work units.
	 * @return $this
	 */
	public function set_progress( int $progress ): self {
		$this->progress   = max( 0, min( $progress, $this->progress_total ) );
		$this->updated_at = time();
		return $this;
	}

	/**
	 * Set the total number of work units, minimum 1.
	 *
	 * @param int $total Total work units (must be >= 1).
	 * @return $this
	 */
	public function set_progress_total( int $total ): self {
		$this->progress_total = max( 1, $total );
		$this->updated_at     = time();
		return $this;
	}

	/**
	 * Set the job's data payload with arbitrary configuration.
	 *
	 * @param array<string, mixed> $data Key-value configuration for the job.
	 * @return $this
	 */
	public function set_data( array $data ): self {
		$this->data = $data;
		return $this;
	}

	/**
	 * Set the maximum number of retry attempts (0 = no retries).
	 *
	 * @param int $max_retries Maximum retries after initial run.
	 * @return $this
	 */
	public function set_max_retries( int $max_retries ): self {
		$this->max_retries = max( 0, $max_retries );
		return $this;
	}

	/**
	 * Set the current retry attempt number.
	 *
	 * @param int $retry_count Current retry attempt (0 = first run).
	 * @return $this
	 */
	public function set_retry_count( int $retry_count ): self {
		$this->retry_count = max( 0, $retry_count );
		return $this;
	}

	/**
	 * Set the base delay in seconds between retry attempts.
	 *
	 * @param int $seconds Minimum 1 second.
	 * @return $this
	 */
	public function set_retry_delay_seconds( int $seconds ): self {
		$this->retry_delay_seconds = max( 1, $seconds );
		return $this;
	}

	/**
	 * Set the Action Scheduler group name.
	 *
	 * @param string $group Group name (e.g. "reindex", "reindex_job_abc123").
	 * @return $this
	 */
	public function set_group( string $group ): self {
		$this->group = $group;
		return $this;
	}

	/**
	 * Set the parent job ID to establish a parent-child relationship.
	 *
	 * @param string|null $parent_id The parent's job ID, or null for top-level.
	 * @return $this
	 */
	public function set_parent_id( ?string $parent_id ): self {
		$this->parent_id = $parent_id;
		return $this;
	}

	/**
	 * Replace the entire list of child job IDs.
	 *
	 * @param string[] $child_ids Array of child job IDs.
	 * @return $this
	 */
	public function set_child_ids( array $child_ids ): self {
		$this->child_ids = $child_ids;
		return $this;
	}

	/**
	 * Add a single child job ID, preventing duplicates.
	 *
	 * @param string $child_id The child job's ID.
	 * @return $this
	 */
	public function add_child_id( string $child_id ): self {
		if ( ! in_array( $child_id, $this->child_ids, true ) ) {
			$this->child_ids[] = $child_id;
		}
		return $this;
	}

	/**
	 * Increment the count of completed child jobs.
	 *
	 * @return $this
	 */
	public function increment_children_completed(): self {
		++$this->children_completed;
		$this->updated_at = time();
		return $this;
	}

	/**
	 * Update progress to a specific value, clamped to [0, progress_total].
	 *
	 * @param int $progress Number of completed work units.
	 * @return $this
	 */
	public function update_progress( int $progress ): self {
		$this->progress   = max( 0, min( $progress, $this->progress_total ) );
		$this->updated_at = time();
		return $this;
	}

	/**
	 * Mark the job as FAILED with an error message.
	 *
	 * @param string $error Description of what went wrong.
	 */
	public function fail( string $error ): void {
		$this->status     = self::STATUS_FAILED;
		$this->error      = $error;
		$this->updated_at = time();
	}

	/**
	 * Mark the job as RUNNING and touch updated_at.
	 */
	public function mark_running(): void {
		$this->status     = self::STATUS_RUNNING;
		$this->updated_at = time();
	}

	/**
	 * Mark the job as COMPLETED and set progress to 100%.
	 */
	public function mark_completed(): void {
		$this->status     = self::STATUS_COMPLETED;
		$this->progress   = $this->progress_total;
		$this->updated_at = time();
	}

	/**
	 * Mark the job as CANCELLED.
	 */
	public function mark_cancelled(): void {
		$this->status     = self::STATUS_CANCELLED;
		$this->updated_at = time();
	}

	/**
	 * Check if this parent job still has child jobs that haven't finished.
	 *
	 * @return bool True if at least one child hasn't completed yet.
	 */
	public function has_pending_children(): bool {
		return ! empty( $this->child_ids ) && $this->children_completed < count( $this->child_ids );
	}

	/**
	 * Check if the job is in a terminal state (no further execution will occur).
	 *
	 * @return bool True if status is COMPLETED, FAILED, or CANCELLED.
	 */
	public function is_finished(): bool {
		return in_array( $this->status, [ self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ], true );
	}

	/**
	 * Determine whether the job should be retried after a failure.
	 *
	 * @return bool True if retry_count <= max_retries (more retries available).
	 */
	public function should_retry(): bool {
		return $this->retry_count <= $this->max_retries;
	}

	/**
	 * Serialize the job to an associative array for wp_options storage.
	 *
	 * @return array<string, mixed> Complete job state with snake_case keys.
	 */
	public function to_array(): array {
		return [
			'id'                  => $this->id,
			'status'              => $this->status,
			'progress'            => $this->progress,
			'progress_total'      => $this->progress_total,
			'progress_percent'    => $this->get_progress_percent(),
			'error'               => $this->error,
			'data'                => $this->data,
			'max_retries'         => $this->max_retries,
			'retry_count'         => $this->retry_count,
			'retry_delay_seconds' => $this->retry_delay_seconds,
			'group'               => $this->group,
			'parent_id'           => $this->parent_id,
			'child_ids'           => $this->child_ids,
			'children_completed'  => $this->children_completed,
			'children_total'      => count( $this->child_ids ),
			'created_at'          => $this->created_at,
			'updated_at'          => $this->updated_at,
		];
	}

	/**
	 * Reconstruct a job instance from a stored associative array.
	 *
	 * Uses ReflectionClass to bypass the constructor (which generates
	 * a new ID) and restores all properties from the serialized state.
	 *
	 * @param array<string, mixed> $data The array produced by to_array().
	 * @return static The reconstructed job instance of the concrete class.
	 */
	public static function from_array( array $data ): static {
		$ref                      = new \ReflectionClass( static::class );
		$job                      = $ref->newInstanceWithoutConstructor();
		$job->id                  = $data['id'] ?? '';
		$job->status              = $data['status'] ?? $job->status;
		$job->progress            = (int) ( $data['progress'] ?? $job->progress );
		$job->progress_total      = (int) ( $data['progress_total'] ?? $job->progress_total );
		$job->error               = $data['error'] ?? $job->error;
		$job->data                = $data['data'] ?? $job->data;
		$job->max_retries         = (int) ( $data['max_retries'] ?? $job->max_retries );
		$job->retry_count         = (int) ( $data['retry_count'] ?? $job->retry_count );
		$job->retry_delay_seconds = (int) ( $data['retry_delay_seconds'] ?? $job->retry_delay_seconds );
		$job->group               = $data['group'] ?? $job->group;
		$job->parent_id           = $data['parent_id'] ?? $job->parent_id;
		$job->child_ids           = $data['child_ids'] ?? $job->child_ids;
		$job->children_completed  = (int) ( $data['children_completed'] ?? $job->children_completed );
		$job->created_at          = (int) ( $data['created_at'] ?? time() );
		$job->updated_at          = (int) ( $data['updated_at'] ?? time() );
		return $job;
	}
}
