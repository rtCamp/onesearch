/**
 * WordPress dependencies
 */
import { __experimentalText as Text } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import JobStatusBadge from './JobStatusBadge';
import type { JobStatus } from './types';
import { formatDuration, formatTimestamp } from './utils';

interface HistoryTableProps {
	history: JobStatus[];
	onOpenDetails: ( job: JobStatus ) => void;
}

const HistoryTable = ( { history, onOpenDetails }: HistoryTableProps ) => {
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
				</tr>
			</thead>
			<tbody>
				{ history.map( ( job ) => {
					const totalBatches =
						( job.data?.[ 'total_batches' ] as number ) ||
						job.children_total ||
						job.progress_total;
					const completedBatches =
						job.children_completed ?? job.progress;
					const batchDisplay =
						totalBatches > 0
							? `${ completedBatches }/${ totalBatches }`
							: '—';

					const duration =
						job.finished_at && job.created_at
							? job.finished_at - job.created_at
							: null;

					const handleKeyDown = ( e: React.KeyboardEvent ) => {
						if ( e.key === 'Enter' || e.key === ' ' ) {
							e.preventDefault();
							void onOpenDetails( job );
						}
					};

					return (
						<tr
							key={ job.id }
							className="onesearch-history-table-row"
							onClick={ () => void onOpenDetails( job ) }
							onKeyDown={ handleKeyDown }
							role="button"
							tabIndex={ 0 }
						>
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
								<Text variant="muted">{ batchDisplay }</Text>
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
};

export default HistoryTable;
