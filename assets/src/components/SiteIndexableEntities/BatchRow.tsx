/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import type { JobStatus } from './types';
import JobStatusBadge from './JobStatusBadge';
import ProgressBar from './ProgressBar';

interface BatchRowProps {
	child: JobStatus;
	idx: number;
}

const BatchRow = ( { child, idx }: BatchRowProps ) => (
	<div className="onesearch-batch-row">
		<span className="onesearch-batch-label">
			{ sprintf(
				/* translators: %d: batch number */
				__( 'Batch %d', 'onesearch' ),
				idx + 1
			) }
		</span>
		<JobStatusBadge status={ child.status } size="small" />
		{ ( child.status === 'running' || child.status === 'pending' ) && (
			<ProgressBar percent={ child.progress_percent || 0 } />
		) }
		{ child.error && (
			<span className="onesearch-batch-error">
				{ child.error.substring( 0, 80 ) }
			</span>
		) }
	</div>
);

export default BatchRow;
