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

/**
 * Renders a single history row and returns the text of its Batches cell.
 *
 * @param {JobStatus} job The job to render.
 * @return {string} The Batches cell text.
 */
const renderBatches = ( job: JobStatus ): string => {
	render(
		<HistoryTable
			history={ [ job ] }
			retryingJobId={ null }
			onRetry={ jest.fn() }
		/>
	);

	const [ , row ] = screen.getAllByRole( 'row' );
	const cells = within( row as HTMLElement ).getAllByRole( 'cell' );

	return cells[ 5 ]?.textContent ?? '';
};

describe( 'HistoryTable', () => {
	it( 'counts a completed watcher sync job as its one finished batch', () => {
		expect(
			renderBatches( {
				...baseJob,
				type: 'sync',
				group: 'watcher',
				progress: 1,
				progress_total: 1,
				children_completed: 0,
				children_total: 0,
			} )
		).toBe( '1/1' );
	} );

	it( 'counts a failed watcher sync job as its one unfinished batch', () => {
		expect(
			renderBatches( {
				...baseJob,
				type: 'sync',
				group: 'watcher',
				status: 'failed',
				progress: 1,
				progress_total: 1,
				children_completed: 0,
				children_total: 0,
			} )
		).toBe( '0/1' );
	} );

	it( 'shows finished child batches out of the total for a reindex', () => {
		expect(
			renderBatches( {
				...baseJob,
				type: 'reindex',
				status: 'failed',
				progress: 3,
				progress_total: 3,
				child_ids: [ 'a', 'b', 'c' ],
				children_completed: 2,
				children_total: 3,
			} )
		).toBe( '2/3' );
	} );
} );
