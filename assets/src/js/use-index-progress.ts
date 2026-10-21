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

interface IndexerResponse {
	sites?: IndexProgressMap;
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

	const load = useCallback( async () => {
		try {
			const data = await apiFetch< IndexerResponse >( {
				path: INDEXER_PATH,
			} );
			setSites( data?.sites ?? {} );
		} catch {
			// Keep showing the last progress, and try again on the next check.
		}
	}, [] );

	// Throws when indexing can't be started, so the caller can tell the admin why.
	const start = useCallback( async () => {
		const data = await apiFetch< IndexerResponse >( {
			path: INDEXER_PATH,
			method: 'POST',
		} );
		setSites( data?.sites ?? {} );
	}, [] );

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

	return { sites, start, isActive };
};
