/**
 * External dependencies
 */
import { describe, expect, it, type MockedFunction, vi } from 'vitest';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

import SiteIndexableEntities from '@/components/SiteIndexableEntities';
import { POLL_INTERVAL, type JobStatusData } from '@/js/use-index-status';

const mockedApiFetch = apiFetch as MockedFunction< typeof apiFetch >;

const currentSiteUrl = 'https://governing.example.com/';
const brandSiteUrl = 'https://brand.example.com/';

type Options = { path?: string; method?: string };

/**
 * Answers the requests the component sends, by path and method.
 *
 * @param answers The answers, keyed by method and path, e.g. `GET /onesearch/v1/indexer`. A function is called with the options, and its result is the answer.
 */
const answer = ( answers: Record< string, unknown > ) =>
	mockedApiFetch.mockImplementation( ( async ( options: Options ) => {
		const key = `${ options.method ?? 'GET' } ${ options.path }`;
		const value = answers[ key ];

		if ( value === undefined ) {
			return { sites: {}, jobs: [] };
		}

		const result =
			typeof value === 'function' ? await value( options ) : value;
		if ( result instanceof Error ) {
			throw result;
		}

		return result;
	} ) as unknown as typeof apiFetch );

const renderEntities = (
	props: Partial< Parameters< typeof SiteIndexableEntities >[ 0 ] > = {}
) =>
	render(
		<SiteIndexableEntities
			sites={ [] }
			allPostTypes={ {
				[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
			} }
			currentSiteUrl={ currentSiteUrl }
			indexableEntities={ { [ currentSiteUrl ]: [ 'post' ] } }
			setNotice={ vi.fn() }
			onEntitiesSaved={ vi.fn() }
			saving={ false }
			setSaving={ vi.fn() }
			{ ...props }
		/>
	);

describe( 'SiteIndexableEntities', () => {
	it( 'can re-index whatever is saved', async () => {
		answer( {} );
		renderEntities( { indexableEntities: {} } );

		await screen.findByText( 'Select Entities to Index' );
		expect(
			screen.getByRole( 'button', { name: 'Re-index' } )
		).toBeEnabled();
	} );

	it( 'shows a message when a brand site has no selectable entities', async () => {
		answer( {} );
		renderEntities( {
			sites: [
				{ name: 'Brand Site', url: brandSiteUrl, api_key: 'brand-key' },
			],
			indexableEntities: {},
		} );

		expect(
			await screen.findByText(
				'No entities to select. Please check site configuration'
			)
		).toBeInTheDocument();
	} );

	it( 'saves entities and re-indexes after changes', async () => {
		const setNotice = vi.fn();
		const onEntitiesSaved = vi.fn();
		const setSaving = vi.fn();
		const saved = vi.fn( () => ( {} ) );
		const reindexed = vi.fn( () => ( { started: true } ) );

		answer( {
			'POST /wp/v2/settings': saved,
			'POST /onesearch/v1/indexer': reindexed,
		} );
		renderEntities( {
			indexableEntities: {},
			setNotice,
			onEntitiesSaved,
			setSaving,
		} );

		await screen.findByText( 'Select Entities to Index' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Select entities…' } )
		);
		fireEvent.click( screen.getByLabelText( 'Posts' ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save Changes' } )
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				message:
					"Indexing started. Each site shows how it's going below.",
				type: 'success',
			} );
		} );

		expect( saved ).toHaveBeenCalledWith( {
			path: '/wp/v2/settings',
			method: 'POST',
			data: {
				onesearch_indexable_entities: {
					entities: { [ currentSiteUrl ]: [ 'post' ] },
				},
			},
		} );
		expect( reindexed ).toHaveBeenCalled();
		expect( onEntitiesSaved ).toHaveBeenCalled();
		expect( setSaving ).toHaveBeenCalledWith( true );
		expect( setSaving ).toHaveBeenLastCalledWith( false );
	} );

	it( 'shows why post types failed to load for a brand site', () => {
		answer( {} );
		renderEntities( {
			sites: [ { name: 'Brand Site', url: brandSiteUrl } ],
			indexableEntities: {},
			postTypeErrors: [
				{ site_url: brandSiteUrl, message: 'Request timed out.' },
			],
		} );

		expect( screen.getByText( 'Request timed out.' ) ).toBeInTheDocument();
		expect(
			screen.queryByText(
				'No entities to select. Please check site configuration'
			)
		).not.toBeInTheDocument();
	} );

	it( 'disables saving when the saved entities failed to load', () => {
		answer( {} );
		renderEntities( { indexableEntities: {}, loadFailed: true } );

		expect(
			screen.getByRole( 'button', { name: 'Select entities…' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Save Changes' } )
		).toBeDisabled();
	} );

	it( 'shows the error notice when saving entities fails', async () => {
		const setNotice = vi.fn();
		const setSaving = vi.fn();

		answer( {
			'POST /wp/v2/settings': new Error(
				'Invalid parameter(s): onesearch_indexable_entities'
			),
		} );
		renderEntities( { indexableEntities: {}, setNotice, setSaving } );

		await screen.findByText( 'Select Entities to Index' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Select entities…' } )
		);
		fireEvent.click( screen.getByLabelText( 'Posts' ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save Changes' } )
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				message: 'Invalid parameter(s): onesearch_indexable_entities',
				type: 'error',
			} );
		} );
		expect( setSaving ).toHaveBeenLastCalledWith( false );
	} );

	it( 'opens and closes the re-index modal, and says why the re-index failed', async () => {
		const setNotice = vi.fn();

		answer( {
			'POST /onesearch/v1/indexer': new Error(
				"The job couldn't be queued."
			),
		} );
		renderEntities( { setNotice } );

		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Re-index' } )
		);
		expect(
			await screen.findByText( 'Re-index saved entities' )
		).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect(
			screen.queryByText( 'Re-index saved entities' )
		).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Re-index' } ) );
		fireEvent.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Re-index',
			} )
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				message: "The job couldn't be queued.",
				type: 'error',
			} );
		} );
	} );

	it( "shows each site's index, or why its status couldn't be loaded", async () => {
		answer( {
			'GET /onesearch/v1/indexer': {
				sites: {
					[ currentSiteUrl ]: {
						index: { total: 10, indexed: 9, failed: [] },
					},
					[ brandSiteUrl ]: { error: 'cURL error 7' },
				},
				jobs: [
					{
						job: 'index_site',
						site: currentSiteUrl,
						status: 'completed',
						error: null,
					},
				],
			},
		} );
		renderEntities( {
			sites: [ { name: 'Brand Site', url: brandSiteUrl } ],
		} );

		await screen.findByText( '9 of 10 items are in search.' );
		const brand = screen
			.getByText( 'Brand Site' )
			.closest( '.onesearch-entity-site' ) as HTMLElement;

		expect(
			within( brand ).getByText(
				"This site's status couldn't be loaded. Check that the site is reachable. cURL error 7"
			)
		).toBeInTheDocument();
	} );

	it( 'shows indexing while it runs, and checks on it until it ends', async () => {
		const running: JobStatusData = {
			job: 'index_site',
			site: currentSiteUrl,
			status: 'running',
			error: null,
			progress: { total: 2, done: 1, failed: 0 },
		};
		let status = {
			sites: {
				[ currentSiteUrl ]: {
					index: { total: 2, indexed: 0, failed: [] },
				},
			},
			jobs: [ running ],
		};
		answer( { 'GET /onesearch/v1/indexer': () => status } );
		vi.useFakeTimers();

		try {
			renderEntities();

			expect(
				await screen.findByText( 'Indexing…' )
			).toBeInTheDocument();
			expect( screen.getByText( '1 of 2 items' ) ).toBeInTheDocument();

			status = {
				...status,
				sites: {
					[ currentSiteUrl ]: {
						index: { total: 2, indexed: 2, failed: [] },
					},
				},
				jobs: [ { ...running, status: 'completed' } ],
			};

			await act( async () => {
				await vi.advanceTimersByTimeAsync( POLL_INTERVAL );
			} );

			expect(
				screen.getByRole( 'button', { name: 'Re-index' } )
			).toBeEnabled();
			expect(
				screen.getByText( '2 of 2 items are in search.' )
			).toBeInTheDocument();
			expect( screen.queryByText( 'Indexing…' ) ).toBeNull();
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'says a re-index starts over while one is queued, or a brand site re-indexes', async () => {
		const jobs: JobStatusData[] = [
			{
				job: 'index_site',
				site: currentSiteUrl,
				status: 'queued',
				error: null,
			},
		];
		answer( {
			'GET /onesearch/v1/indexer': () => ( { sites: {}, jobs } ),
		} );
		const { unmount } = renderEntities( {
			sites: [ { name: 'Brand Site', url: brandSiteUrl } ],
		} );

		expect(
			await screen.findByText( 'Indexing will start soon.' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Re-index' } ) );
		expect(
			screen.getByText(
				'Indexing is already running. Re-index to start it over.'
			)
		).toBeInTheDocument();

		unmount();
		jobs[ 0 ] = {
			job: 'index_site',
			site: brandSiteUrl,
			status: 'running',
			error: null,
			progress: { total: 3, done: 1, failed: 0 },
		};
		renderEntities( {
			sites: [ { name: 'Brand Site', url: brandSiteUrl } ],
		} );

		expect( await screen.findByText( 'Indexing…' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Re-index' } ) );
		expect(
			screen.getByText(
				'Indexing is already running. Re-index to start it over.'
			)
		).toBeInTheDocument();
	} );
} );
