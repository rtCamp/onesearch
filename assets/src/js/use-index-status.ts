/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export type JobState = 'queued' | 'running' | 'completed' | 'failed';

/**
 * A job's state, as the /indexer route returns it.
 */
export interface JobStatusData {
	job: string;
	// The site the job is about.
	site: string;
	status: JobState;
	// Why the job failed.
	error: string | null;
	// Only for the jobs that count their items, once they're counted.
	progress?: { total: number; done: number; failed: number };
}

export interface FailedPost {
	id: number;
	title: string;
	edit_url: string;
	error: string;
}

/**
 * Which of a site's posts are indexed, and the ones that failed.
 */
export interface IndexSummaryData {
	total: number;
	indexed: number;
	failed: FailedPost[];
}

// A site's index summary, null when the site can't tell what it indexes, or why its report couldn't be fetched.
export type SiteStatus = { index: IndexSummaryData | null } | { error: string };

interface StatusResponse {
	sites: Record< string, SiteStatus >;
	jobs: JobStatusData[];
}

const INDEXER_PATH = '/onesearch/v1/indexer';

// How often to check on the jobs while any of them is going, in milliseconds.
export const POLL_INTERVAL = 10000;

/**
 * Loads the index summary and jobs of the site and its brand sites, and keeps them current while any job is going or until a check succeeds.
 */
export const useIndexStatus = () => {
	const [ status, setStatus ] = useState< StatusResponse >( {
		sites: {},
		jobs: [],
	} );
	// Whether the status hasn't loaded yet, or the last check failed.
	const [ isStale, setIsStale ] = useState( true );

	const load = useCallback( async () => {
		try {
			setStatus(
				await apiFetch< StatusResponse >( { path: INDEXER_PATH } )
			);
			setIsStale( false );
		} catch {
			// Keep showing the last status, and try again on the next check.
			setIsStale( true );
		}
	}, [] );

	// Throws when indexing can't be started, so the caller can say why.
	const start = useCallback( async () => {
		try {
			await apiFetch( { path: INDEXER_PATH, method: 'POST' } );
		} finally {
			await load();
		}
	}, [ load ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// A re-index is going, which Re-index starts over.
	const isIndexing = status.jobs.some(
		( job ) => job.status === 'queued' || job.status === 'running'
	);

	useEffect( () => {
		if ( ! isIndexing && ! isStale ) {
			return;
		}

		const timer = setInterval( load, POLL_INTERVAL );
		return () => clearInterval( timer );
	}, [ isIndexing, isStale, load ] );

	return {
		sites: status.sites,
		jobs: status.jobs,
		start,
		isIndexing,
	};
};
