/**
 * External dependencies
 */
import { act, renderHook } from '@testing-library/react';

import { useReindexJob } from '@/components/SiteIndexableEntities/useReindexJob';

const currentSiteUrl = 'https://governing.example.com/';
const brandSiteUrl = 'https://brand.example.com/';

const governingSite = {
	site_name: 'Governing Site',
	site_url: currentSiteUrl,
	job_id: 'gov-job',
	batch_count: 1,
};
const brandSite = {
	site_name: 'Brand',
	site_url: brandSiteUrl,
	job_id: 'brand-job',
	batch_count: 1,
};

const respond = ( data: unknown, ok = true ) =>
	( {
		ok,
		status: ok ? 200 : 404,
		json: jest.fn().mockResolvedValue( data ),
	} ) as unknown as Response;

const tick = ( ms: number ) =>
	act( async () => {
		await jest.advanceTimersByTimeAsync( ms );
	} );

describe( 'useReindexJob', () => {
	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'keeps polling while a job status read fails', async () => {
		jest.useFakeTimers();

		let governingJob = { id: 'gov-job', status: 'running' };
		let brandStatus = respond( {
			success: true,
			job: { id: 'brand-job', status: 'running' },
			children: [],
		} );

		global.fetch = jest.fn( async ( input: RequestInfo | URL ) => {
			const url = String( input );
			if ( url.endsWith( '/re-index' ) ) {
				return respond( {
					success: true,
					jobs: [ governingSite, brandSite ],
				} );
			}
			if ( url.includes( '/jobs/remote-status' ) ) {
				return brandStatus;
			}
			if ( url.includes( '/jobs/gov-job/children' ) ) {
				return respond( {
					success: true,
					children: [ { id: 'batch', status: 'completed' } ],
				} );
			}
			if ( url.includes( '/jobs/gov-job' ) ) {
				return respond( { success: true, job: governingJob } );
			}
			return respond( { jobs: [], page: 1, total_pages: 0 } );
		} ) as typeof fetch;

		const { result } = renderHook( () =>
			useReindexJob( { currentSiteUrl, setNotice: jest.fn() } )
		);

		await act( async () => {
			await result.current.handleReIndex();
		} );
		await tick( 0 );

		// This site's only batch is done; the brand's run is still going when its status read fails.
		brandStatus = respond(
			{
				code: 'onesearch_remote_job_not_found',
				message: 'Job not found on remote site.',
			},
			false
		);
		await tick( 2000 );

		expect( result.current.reindexing ).toBe( true );
		expect( result.current.siteStates ).toHaveLength( 2 );

		governingJob = { id: 'gov-job', status: 'completed' };
		brandStatus = respond( {
			success: true,
			job: { id: 'brand-job', status: 'completed' },
			children: [],
		} );
		await tick( 2000 );

		expect( result.current.reindexing ).toBe( false );
	} );

	it( 'follows and can cancel the runs that started when another brand site failed to start', async () => {
		jest.useFakeTimers();

		const setNotice = jest.fn();
		const fetchMock = jest.fn( async ( input: RequestInfo | URL ) => {
			const url = String( input );
			if ( url.endsWith( '/re-index' ) ) {
				return respond( {
					success: false,
					message:
						'Failed to connect to the child site. Error code 500',
					jobs: [ governingSite, brandSite ],
				} );
			}
			if ( url.includes( '/jobs/remote-status' ) ) {
				return respond( {
					success: true,
					job: { id: 'brand-job', status: 'running' },
					children: [],
				} );
			}
			if ( url.includes( '/jobs/gov-job/children' ) ) {
				return respond( {
					success: true,
					children: [ { id: 'batch', status: 'running' } ],
				} );
			}
			if ( url.includes( '/jobs/gov-job' ) ) {
				return respond( {
					success: true,
					job: { id: 'gov-job', status: 'running' },
				} );
			}
			return respond( { jobs: [], page: 1, total_pages: 0 } );
		} );
		global.fetch = fetchMock as typeof fetch;

		const { result } = renderHook( () =>
			useReindexJob( { currentSiteUrl, setNotice } )
		);

		act( () => result.current.setShowReindexingModal( true ) );
		await act( async () => {
			await result.current.handleReIndex();
		} );
		await tick( 2000 );

		expect( setNotice ).toHaveBeenCalledWith( {
			message: 'Failed to connect to the child site. Error code 500',
			type: 'error',
		} );
		expect( result.current.reindexing ).toBe( true );
		expect( result.current.showReindexingModal ).toBe( true );
		expect(
			result.current.siteStates.map( ( s ) => s.reindexJob?.status )
		).toEqual( [ 'running', 'running' ] );

		await act( async () => {
			await result.current.handleCancelJob();
		} );

		expect( fetchMock ).toHaveBeenCalledWith(
			expect.stringMatching( /\/jobs\/gov-job$/ ),
			expect.objectContaining( { method: 'DELETE' } )
		);
		expect( fetchMock ).toHaveBeenCalledWith(
			expect.stringContaining( '/jobs/remote-cancel' ),
			expect.objectContaining( {
				body: JSON.stringify( {
					site_url: brandSiteUrl,
					job_id: 'brand-job',
				} ),
			} )
		);
		expect( result.current.reindexing ).toBe( false );
	} );

	it( 'resumes a run that was in progress before a page refresh', async () => {
		jest.useFakeTimers();

		let governingJob = { id: 'gov-job', status: 'running' };

		global.fetch = jest.fn( async ( input: RequestInfo | URL ) => {
			const url = String( input );
			if ( url.endsWith( '/re-index/status' ) ) {
				return respond( {
					success: true,
					active: true,
					jobs: [ governingSite ],
				} );
			}
			if ( url.includes( '/jobs/gov-job/children' ) ) {
				return respond( { success: true, children: [] } );
			}
			if ( url.includes( '/jobs/gov-job' ) ) {
				return respond( { success: true, job: governingJob } );
			}
			return respond( { jobs: [], page: 1, total_pages: 0 } );
		} ) as typeof fetch;

		const { result } = renderHook( () =>
			useReindexJob( { currentSiteUrl, setNotice: jest.fn() } )
		);
		await tick( 0 );

		expect( result.current.reindexing ).toBe( true );
		expect( result.current.showReindexingModal ).toBe( true );
		expect( result.current.siteStates[ 0 ]?.reindexJob?.status ).toBe(
			'running'
		);

		governingJob = { id: 'gov-job', status: 'completed' };
		await tick( 2000 );

		expect( result.current.reindexing ).toBe( false );
	} );
} );
