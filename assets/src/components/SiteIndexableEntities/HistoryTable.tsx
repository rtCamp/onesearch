/**
 * WordPress dependencies
 */
import { Button, __experimentalText as Text } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import JobStatusBadge from './JobStatusBadge';
import type { JobStatus } from './types';
import { formatDuration, formatTimestamp } from './utils';

interface HistoryTableProps {
	history: JobStatus[];
	retryingJobId: string | null;
	onRetry: ( job: JobStatus ) => void;
}

/**
 * A job is worth retrying when it failed outright, or when it finished with
 * some batches still in a failed state. A cancelled job never is: cancelling
 * was the user's decision, not a failure.
 *
 * @param {JobStatus} job The job to check.
 * @return {boolean} Whether a retry should be offered.
 */
const isRetryable = ( job: JobStatus ): boolean =>
	job.status !== 'cancelled' &&
	( job.status === 'failed' || ( job.children_failed ?? 0 ) > 0 );

/**
 * Formats a job's batch progress as "done/total".
 *
 * A top-level sync job (e.g. from the Watcher) has no child batches because it
 * is itself the one batch, and its progress counters track posts, not batches.
 *
 * @param {JobStatus} job The job to format.
 * @return {string} The batch progress, or an em dash when the job has no batches.
 */
const formatBatches = ( job: JobStatus ): string => {
	if ( job.type === 'sync' ) {
		return `${ job.status === 'completed' ? 1 : 0 }/1`;
	}

	const totalBatches =
		( job.data?.[ 'total_batches' ] as number ) ||
		job.children_total ||
		job.progress_total;
	const completedBatches = job.children_completed ?? job.progress;

	return totalBatches > 0 ? `${ completedBatches }/${ totalBatches }` : '—';
};

const HistoryTable = ( {
	history,
	retryingJobId,
	onRetry,
}: HistoryTableProps ) => {
	if ( history.length === 0 ) {
		return (
			<Text variant="muted">{ __( 'No past jobs.', 'onesearch' ) }</Text>
		);
	}

	return (
		<table className="onesearch-history-table">
			<thead>
				<tr>
					<th>{ __( 'ID', 'onesearch' ) }</th>
					<th>{ __( 'Type', 'onesearch' ) }</th>
					<th>{ __( 'Created at', 'onesearch' ) }</th>
					<th>{ __( 'Duration', 'onesearch' ) }</th>
					<th>{ __( 'Status', 'onesearch' ) }</th>
					<th>{ __( 'Batches', 'onesearch' ) }</th>
					<th className="onesearch-history-actions-col">
						<span className="screen-reader-text">
							{ __( 'Actions', 'onesearch' ) }
						</span>
					</th>
				</tr>
			</thead>
			<tbody>
				{ history.map( ( job ) => {
					const duration =
						job.finished_at && job.created_at
							? job.finished_at - job.created_at
							: null;

					const retrying = retryingJobId === job.id;

					return (
						<tr key={ job.id }>
							<td>
								<code title={ job.id }>
									{ job.id.substring( 0, 16 ) }…
								</code>
							</td>
							<td>
								<Text variant="muted">
									{ job.group || 'reindex' }
								</Text>
							</td>
							<td>
								<Text variant="muted">
									{ formatTimestamp( job.created_at ) }
								</Text>
							</td>
							<td>
								<Text variant="muted">
									{ duration !== null
										? formatDuration( duration )
										: '—' }
								</Text>
							</td>
							<td>
								<JobStatusBadge
									status={ job.status }
									size="small"
								/>
							</td>
							<td>
								<Text variant="muted">
									{ formatBatches( job ) }
								</Text>
							</td>
							<td className="onesearch-history-actions-col">
								{ isRetryable( job ) && (
									<Button
										variant="link"
										isBusy={ retrying }
										disabled={ retryingJobId !== null }
										onClick={ () => onRetry( job ) }
									>
										{ retrying
											? __( 'Retrying…', 'onesearch' )
											: __( 'Retry', 'onesearch' ) }
									</Button>
								) }
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
};

export default HistoryTable;
