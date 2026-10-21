/**
 * External dependencies
 */
import { useCallback, useEffect, useState } from 'react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

export type IndexStatus = 'queued' | 'running' | 'completed' | 'failed';

/**
 * The progress of a site's latest indexing run, as the /indexer route returns it.
 */
export interface IndexProgressData {
	id: string;
	status: IndexStatus;
	// Null until the site has counted its posts.
	total: number | null;
	// Includes the failed ones.
	done: number;
	failed: number;
	errors: string[];
	started_at: number;
}

// Keyed by site URL. Null for a site that hasn't been indexed yet.
export type IndexProgressMap = Record< string, IndexProgressData | null >;

// A job that failed, with the hook and args Action Scheduler ran it with.
export type FailedJob = {
	job: 'onesearch_sync_post';
	args: [ number ];
	error: string;
};

// Keyed by the URL of the site they're about.
export type FailedJobsMap = Record< string, FailedJob[] >;

interface IndexerResponse {
	sites?: IndexProgressMap;
	failed_jobs?: FailedJobsMap;
}

const INDEXER_PATH = '/onesearch/v1/indexer';

// How often to check on an indexing run that's going, in milliseconds.
export const INDEX_POLL_INTERVAL = 3000;

export const isIndexActive = (
	progress: IndexProgressData | null | undefined
): boolean => progress?.status === 'queued' || progress?.status === 'running';

/**
 * Loads the progress of the site's indexing run, and of a governing site's brand sites, and keeps it current until it loads and while any of them runs.
 */
export const useIndexProgress = () => {
	const [ sites, setSites ] = useState< IndexProgressMap >( {} );
	const [ failedJobs, setFailedJobs ] = useState< FailedJobsMap >( {} );

	const update = useCallback( ( data: IndexerResponse | undefined ) => {
		setSites( data?.sites ?? {} );
		setFailedJobs( data?.failed_jobs ?? {} );
	}, [] );

	const load = useCallback( async () => {
		try {
			update(
				await apiFetch< IndexerResponse >( {
					path: INDEXER_PATH,
				} )
			);
		} catch {
			// Keep showing the last progress, and try again on the next check.
		}
	}, [ update ] );

	// Throws when indexing can't be started, so the caller can tell the admin why.
	const start = useCallback( async () => {
		update(
			await apiFetch< IndexerResponse >( {
				path: INDEXER_PATH,
				method: 'POST',
			} )
		);
	}, [ update ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const isActive = Object.values( sites ).some( isIndexActive );
	// The response always has the site's own progress, so there's none until it loads.
	const isLoaded = Object.keys( sites ).length > 0;

	useEffect( () => {
		if ( isLoaded && ! isActive ) {
			return;
		}

		const timer = setInterval( load, INDEX_POLL_INTERVAL );
		return () => clearInterval( timer );
	}, [ isLoaded, isActive, load ] );

	return { sites, failedJobs, start, isActive };
};
