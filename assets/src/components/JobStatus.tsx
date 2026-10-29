/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import type { JobStatusData } from '@/js/use-index-status';

// What each job is, and what its failure means for search and what to do.
const getJobCopy = (
	job: JobStatusData
): { label?: string; failed: string } | null => {
	switch ( job.job ) {
		case 'index_site':
			return {
				label: __( 'Indexing', 'onesearch' ),
				failed: __(
					'Search may show outdated or missing content from this site. Re-index to try again.',
					'onesearch'
				),
			};
		case 'delete_site_records':
			return {
				failed: __(
					'Content from a removed site may still show in search. Re-index to try again.',
					'onesearch'
				),
			};
		default:
			return null;
	}
};

/**
 * Shows a job that's queued, running or failed. A completed job shows nothing, since the index summary shows the results.
 *
 * @param {Object}        props     The props.
 * @param {JobStatusData} props.job The job.
 */
const JobStatus = ( { job }: { job: JobStatusData } ) => {
	const copy = getJobCopy( job );

	if ( ! copy ) {
		return null;
	}

	if ( job.status === 'failed' ) {
		return (
			<div className="onesearch-job-status">
				<p className="onesearch-job-status-failed">{ copy.failed }</p>
				{ !! job.error && (
					<p className="onesearch-job-status-error">{ job.error }</p>
				) }
			</div>
		);
	}

	if ( job.status === 'completed' || ! copy.label ) {
		return null;
	}

	const { progress } = job;

	return (
		<div className="onesearch-job-status">
			<p>
				{ job.status === 'queued'
					? sprintf(
							/* translators: %s: what the job does. */
							__( '%s will start soon.', 'onesearch' ),
							copy.label
						)
					: sprintf(
							/* translators: %s: what the job does. */
							__( '%s…', 'onesearch' ),
							copy.label
						) }
			</p>
			{ progress && (
				<>
					<progress
						className="onesearch-job-status-bar"
						aria-label={ sprintf(
							/* translators: 1: what the job does. 2: site URL. */
							__( '%1$s progress for %2$s', 'onesearch' ),
							copy.label,
							job.site
						) }
						value={ progress.done }
						max={ progress.total }
					/>
					<p>
						{ sprintf(
							/* translators: 1: number of items done. 2: total number of items. */
							__( '%1$d of %2$d items', 'onesearch' ),
							progress.done,
							progress.total
						) }
					</p>
					{ progress.failed > 0 && (
						<p className="onesearch-job-status-failed">
							{ sprintf(
								/* translators: %d: number of items. */
								_n(
									"%d item couldn't be indexed.",
									"%d items couldn't be indexed.",
									progress.failed,
									'onesearch'
								),
								progress.failed
							) }
						</p>
					) }
				</>
			) }
		</div>
	);
};

export default JobStatus;
