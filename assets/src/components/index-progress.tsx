/**
 * External dependencies
 */
import { useEffect, useState } from 'react';

/**
 * WordPress dependencies
 */
import { ProgressBar } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	isIndexActive,
	type FailedJob,
	type IndexProgressData,
	type IndexProgressMap,
} from '@/js/use-index-progress';

interface IndexProgressProps {
	progress: IndexProgressData | null | undefined;
	failedJobs?: FailedJob[] | undefined;
	// False on a brand site, which is re-indexed from its governing site.
	canReindex?: boolean;
	// False for a brand site's row on the governing site, which doesn't know which of the brand site's changes failed to sync.
	isCurrentSite?: boolean;
}

const ErrorList = ( { errors }: { errors: string[] } ) =>
	errors.length > 0 ? (
		<ul className="onesearch-index-progress-errors">
			{ errors.map( ( error ) => (
				<li key={ error }>{ error }</li>
			) ) }
		</ul>
	) : null;

/**
 * Says which of a site's jobs failed, and how to fix them.
 *
 * @param {Object}      props            The props.
 * @param {FailedJob[]} props.failedJobs The site's failed jobs.
 * @param {boolean}     props.canReindex Whether the site can be re-indexed here.
 */
const FailedJobs = ( {
	failedJobs,
	canReindex,
}: {
	failedJobs: FailedJob[];
	canReindex: boolean;
} ) => {
	const syncErrors = failedJobs.flatMap( ( failedJob ) =>
		failedJob.job === 'onesearch_sync_post'
			? [
					sprintf(
						/* translators: 1: post ID. 2: error message. */
						__( 'Post %1$d: %2$s', 'onesearch' ),
						failedJob.args[ 0 ],
						failedJob.error
					),
				]
			: []
	);

	const syncMessage = canReindex
		? sprintf(
				/* translators: %d: number of changes. */
				_n(
					'%d change failed to sync. Re-index to fix it.',
					'%d changes failed to sync. Re-index to fix them.',
					syncErrors.length,
					'onesearch'
				),
				syncErrors.length
			)
		: sprintf(
				/* translators: %d: number of changes. */
				_n(
					'%d change failed to sync. Re-index from the governing site to fix it.',
					'%d changes failed to sync. Re-index from the governing site to fix them.',
					syncErrors.length,
					'onesearch'
				),
				syncErrors.length
			);

	return (
		<>
			{ syncErrors.length > 0 && (
				<>
					<p className="onesearch-index-progress-failed">
						{ syncMessage }
					</p>
					<ErrorList errors={ syncErrors } />
				</>
			) }
		</>
	);
};

/**
 * Shows how a site's latest indexing run is going, or how it went.
 *
 * @param {IndexProgressProps} props The props.
 */
const IndexProgress = ( {
	progress,
	failedJobs = [],
	canReindex = true,
	isCurrentSite = true,
}: IndexProgressProps ) => {
	const content = ( () => {
		// Not loaded yet.
		if ( progress === undefined ) {
			return null;
		}

		if ( progress === null ) {
			return <p>{ __( 'Not indexed yet.', 'onesearch' ) }</p>;
		}

		const failed =
			progress.failed > 0 ? (
				<p className="onesearch-index-progress-failed">
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
			) : null;

		switch ( progress.status ) {
			case 'queued':
				return <p>{ __( 'Waiting to start…', 'onesearch' ) }</p>;

			case 'running': {
				const message =
					progress.total !== null
						? sprintf(
								/* translators: 1: number of items indexed. 2: total number of items. */
								__(
									'Indexing… %1$d of %2$d items.',
									'onesearch'
								),
								progress.done,
								progress.total
							)
						: __( 'Indexing…', 'onesearch' );

				return (
					<>
						{ /* Without a total, the bar shows that it's busy. */ }
						{ progress.total ? (
							<ProgressBar
								className="onesearch-index-progress-bar"
								value={
									( progress.done / progress.total ) * 100
								}
							/>
						) : (
							<ProgressBar className="onesearch-index-progress-bar" />
						) }
						<p>{ message }</p>
						{ failed }
					</>
				);
			}

			case 'completed':
				// The failed jobs say what's still out of date.
				return isCurrentSite && failedJobs.length === 0 ? (
					<p>{ __( 'Search is up to date.', 'onesearch' ) }</p>
				) : null;

			default:
				return (
					<>
						<p className="onesearch-index-progress-failed">
							{ canReindex
								? __(
										"Indexing didn't finish, so search may show outdated or missing content. Re-index to try again.",
										'onesearch'
									)
								: __(
										"Indexing didn't finish, so search may show outdated or missing content. Re-index from the governing site to try again.",
										'onesearch'
									) }
						</p>
						{ failed }
						<ErrorList errors={ progress.errors } />
					</>
				);
		}
	} )();

	return (
		<div className="onesearch-index-progress">
			{ content }
			{ ! isIndexActive( progress ) && (
				<FailedJobs
					failedJobs={ failedJobs }
					canReindex={ canReindex }
				/>
			) }
		</div>
	);
};

/**
 * Tells screen reader users when indexing starts and ends.
 *
 * Each site's own progress isn't announced, since it changes on every check, and neither is how the last run went when the screen loads.
 *
 * @param {Object}           props       The props.
 * @param {IndexProgressMap} props.sites The progress of each site.
 */
export const IndexLiveRegion = ( { sites }: { sites: IndexProgressMap } ) => {
	const progress = Object.values( sites );
	const isActive = progress.some( isIndexActive );
	const [ hasRun, setHasRun ] = useState( false );

	useEffect( () => {
		if ( isActive ) {
			setHasRun( true );
		}
	}, [ isActive ] );

	let message = '';
	if ( isActive ) {
		message = __( 'Indexing…', 'onesearch' );
	} else if ( hasRun ) {
		message = progress.some( ( site ) => site?.status === 'failed' )
			? __( 'Indexing failed.', 'onesearch' )
			: __( 'Indexing complete.', 'onesearch' );
	}

	return (
		<div className="screen-reader-text" role="status">
			{ message }
		</div>
	);
};

export default IndexProgress;
