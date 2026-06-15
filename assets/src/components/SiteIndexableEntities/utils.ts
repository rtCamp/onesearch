/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import type { PostTypeOption } from '../SiteSearchSettings';
import type { EntitiesMap, JobStatus, SiteJob } from './types';

export const formatDuration = ( seconds: number ): string => {
	if ( seconds < 60 ) {
		return sprintf(
			/* translators: %d: seconds */
			__( '%ds', 'onesearch' ),
			seconds
		);
	}
	const mins = Math.floor( seconds / 60 );
	const secs = seconds % 60;
	if ( mins < 60 ) {
		return sprintf(
			/* translators: 1: minutes, 2: seconds */
			__( '%1$dm %2$ds', 'onesearch' ),
			mins,
			secs
		);
	}
	const hrs = Math.floor( mins / 60 );
	const remainingMins = mins % 60;
	return sprintf(
		/* translators: 1: hours, 2: minutes */
		__( '%1$dh %2$dm', 'onesearch' ),
		hrs,
		remainingMins
	);
};

export const formatTimestamp = ( ts?: number ): string =>
	ts ? new Date( ts * 1000 ).toLocaleString() : '—';

export const normalizeEntities = ( map: EntitiesMap = {} ): EntitiesMap => {
	const results: EntitiesMap = {};
	Object.keys( map || {} )
		.sort()
		.forEach( ( site ) => {
			const arr = Array.isArray( map[ site ] ) ? map[ site ] : [];
			const clean = Array.from( new Set( arr.map( String ) ) ).sort();
			results[ site ] = clean;
		} );
	return results;
};

export const toMultiSelectOptions = (
	options: PostTypeOption[]
): Array< { slug: string; label: string; restBase: string } > =>
	options.map( ( opt ) => ( {
		slug: opt.slug,
		label: opt.label ?? opt.slug,
		restBase: opt.restBase ?? opt.slug,
	} ) );

export const getHistorySites = (
	job: JobStatus,
	currentSiteUrl: string
): SiteJob[] => {
	const sitesFromJob = job.data?.sites;
	if ( Array.isArray( sitesFromJob ) && sitesFromJob.length > 0 ) {
		return sitesFromJob;
	}
	return [
		{
			site_name: __( 'Governing Site', 'onesearch' ),
			site_url: currentSiteUrl,
			job_id: job.id,
			batch_count:
				job.children_total ||
				job.data?.total_batches ||
				job.progress_total ||
				0,
		},
	];
};
