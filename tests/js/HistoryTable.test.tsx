/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';

import HistoryTable from '@/components/SiteIndexableEntities/HistoryTable';
import type { JobStatus } from '@/components/SiteIndexableEntities/types';

const baseJob: JobStatus = {
	id: 'job-0000000000000001',
	status: 'completed',
	progress: 0,
	progress_total: 0,
	progress_percent: 100,
	created_at: 1760000000,
	finished_at: 1760000039,
};

describe( 'HistoryTable', () => {
	it( 'shows finished batches out of the total for each kind of job', () => {
		const watcherSync: JobStatus = {
			...baseJob,
			type: 'sync',
			group: 'watcher',
			progress: 1,
			progress_total: 1,
			children_completed: 0,
			children_total: 0,
		};

		render(
			<HistoryTable
				history={ [
					{ ...watcherSync, id: 'watcher-completed' },
					{ ...watcherSync, id: 'watcher-failed', status: 'failed' },
					{
						...baseJob,
						id: 'reindex',
						type: 'reindex',
						status: 'failed',
						progress: 3,
						progress_total: 3,
						child_ids: [ 'a', 'b', 'c' ],
						children_completed: 2,
						children_total: 3,
					},
				] }
				retryingJobId={ null }
				onRetry={ jest.fn() }
			/>
		);

		const [ , ...rows ] = screen.getAllByRole( 'row' );

		// A watcher sync is its own single batch, finished only if it completed.
		expect(
			rows.map(
				( row ) =>
					within( row as HTMLElement ).getAllByRole( 'cell' )[ 5 ]
						?.textContent
			)
		).toEqual( [ '1/1', '0/1', '2/3' ] );
	} );

	it( 'offers a retry for a failed run but not for a cancelled one', () => {
		const withFailedBatch: JobStatus = {
			...baseJob,
			type: 'reindex',
			child_ids: [ 'a', 'b' ],
			children_completed: 1,
			children_failed: 1,
			children_total: 2,
		};

		render(
			<HistoryTable
				history={ [
					{ ...withFailedBatch, id: 'failed-run', status: 'failed' },
					{
						...withFailedBatch,
						id: 'cancelled-run',
						status: 'cancelled',
					},
				] }
				retryingJobId={ null }
				onRetry={ jest.fn() }
			/>
		);

		const [ , failedRow, cancelledRow ] = screen.getAllByRole( 'row' );

		expect(
			within( failedRow as HTMLElement ).getByRole( 'button', {
				name: 'Retry',
			} )
		).toBeInTheDocument();
		expect(
			within( cancelledRow as HTMLElement ).queryByRole( 'button', {
				name: 'Retry',
			} )
		).not.toBeInTheDocument();
	} );
} );
