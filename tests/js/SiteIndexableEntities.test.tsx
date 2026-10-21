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
import { INDEX_POLL_INTERVAL } from '@/js/use-index-progress';

const mockedApiFetch = apiFetch as MockedFunction< typeof apiFetch >;

const currentSiteUrl = 'https://governing.example.com/';
const brandSiteUrl = 'https://brand.example.com/';

const progress = ( overrides: Record< string, unknown > = {} ) => ( {
	id: 'run-id',
	status: 'queued',
	total: null,
	done: 0,
	failed: 0,
	errors: [],
	started_at: 1767225600,
	...overrides,
} );

type ApiResult = unknown | Error;

// Answers apiFetch by method and path. An Error is thrown as the request's failure.
const mockApi = ( routes: Record< string, ApiResult | ApiResult[] > ) => {
	mockedApiFetch.mockImplementation( ( async ( options: {
		path?: string;
		method?: string;
	} ) => {
		const route =
			routes[ `${ options.method ?? 'GET' } ${ options.path }` ];

		// A list answers each request in turn, and repeats its last answer.
		let result = route;
		if ( Array.isArray( route ) ) {
			result = route.length > 1 ? route.shift() : route[ 0 ];
		}

		if ( result instanceof Error ) {
			throw result;
		}

		return result;
	} ) as unknown as typeof apiFetch );
};

describe( 'SiteIndexableEntities', () => {
	it( 'loads saved entities and enables reindexing when data exists', async () => {
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
			/>
		);

		await screen.findByText( 'Select Entities to Index' );
		await waitFor( () => {
			expect(
				screen.getByRole( 'button', { name: 'Re-index' } )
			).toBeEnabled();
		} );
	} );

	it( 'shows a message when a brand site has no selectable entities', async () => {
		render(
			<SiteIndexableEntities
				sites={ [
					{
						name: 'Brand Site',
						url: brandSiteUrl,
						api_key: 'brand-key',
					},
				] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ {} }
				setNotice={ vi.fn() }
				onEntitiesSaved={ vi.fn() }
				saving={ false }
				setSaving={ vi.fn() }
			/>
		);

		expect(
			await screen.findByText(
				'No entities to select. Please check site configuration'
			)
		).toBeInTheDocument();
	} );

	it( 'saves entities and reindexes after changes', async () => {
		const setNotice = vi.fn();
		const onEntitiesSaved = vi.fn();
		const setSaving = vi.fn();

		mockApi( {
			'GET /onesearch/v1/indexer': {
				sites: { [ currentSiteUrl ]: null },
			},
			'POST /wp/v2/settings': {},
			'POST /onesearch/v1/indexer': {
				sites: { [ currentSiteUrl ]: progress() },
			},
		} );

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ {} }
				setNotice={ setNotice }
				onEntitiesSaved={ onEntitiesSaved }
				saving={ false }
				setSaving={ setSaving }
			/>
		);

		await screen.findByText( 'Select Entities to Index' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Select entities…' } )
		);
		fireEvent.click( screen.getByLabelText( 'Posts' ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save Changes' } )
		);

		await waitFor( () => {
			expect( onEntitiesSaved ).toHaveBeenCalled();
		} );

		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/settings',
			method: 'POST',
			data: {
				onesearch_indexable_entities: {
					entities: { [ currentSiteUrl ]: [ 'post' ] },
				},
			},
		} );

		expect( setSaving ).toHaveBeenCalledWith( true );
		expect( setSaving ).toHaveBeenLastCalledWith( false );
		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				message:
					'Indexing started. Each site shows its progress below.',
				type: 'success',
			} );
		} );
		expect( screen.getByText( 'Waiting to start…' ) ).toBeInTheDocument();
	} );

	it( "shows each site's indexing progress, and keeps checking while one runs", async () => {
		vi.useFakeTimers();

		mockApi( {
			'GET /onesearch/v1/indexer': [
				{
					sites: {
						[ currentSiteUrl ]: progress( {
							status: 'running',
							total: 200,
							done: 100,
						} ),
						[ brandSiteUrl ]: progress( {
							status: 'failed',
							total: 100,
							done: 100,
							failed: 100,
							errors: [ 'Algolia admin credentials missing.' ],
						} ),
					},
				},
				{
					sites: {
						[ currentSiteUrl ]: progress( {
							status: 'completed',
							total: 200,
							done: 200,
						} ),
						[ brandSiteUrl ]: null,
					},
				},
			],
		} );

		render(
			<SiteIndexableEntities
				sites={ [ { name: 'Brand Site', url: brandSiteUrl } ] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ { [ currentSiteUrl ]: [ 'post' ] } }
				setNotice={ vi.fn() }
				saving={ false }
				setSaving={ vi.fn() }
			/>
		);

		expect(
			await screen.findByText( 'Indexing… 100 of 200 items.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Algolia admin credentials missing.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( "100 items couldn't be indexed." )
		).toBeInTheDocument();
		// One live region for every site, so screen readers don't hear each site's counter.
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Indexing…' );

		await act( async () => {
			vi.advanceTimersByTime( INDEX_POLL_INTERVAL );
		} );

		expect(
			await screen.findByText( 'Search is up to date.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Not indexed yet.' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Indexing complete.'
		);

		vi.useRealTimers();
	} );

	it( "says search is up to date only for this site, since it doesn't know which of a brand site's changes failed to sync", async () => {
		mockApi( {
			'GET /onesearch/v1/indexer': {
				sites: {
					[ currentSiteUrl ]: progress( {
						status: 'completed',
						total: 200,
						done: 200,
					} ),
					[ brandSiteUrl ]: progress( {
						status: 'completed',
						total: 50,
						done: 50,
					} ),
				},
			},
		} );

		render(
			<SiteIndexableEntities
				sites={ [ { name: 'Brand Site', url: brandSiteUrl } ] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ { [ currentSiteUrl ]: [ 'post' ] } }
				setNotice={ vi.fn() }
				saving={ false }
				setSaving={ vi.fn() }
			/>
		);

		expect(
			await screen.findAllByText( 'Search is up to date.' )
		).toHaveLength( 1 );
	} );

	it( "keeps checking when the progress doesn't load", async () => {
		vi.useFakeTimers();

		mockApi( {
			'GET /onesearch/v1/indexer': [
				new Error( 'Internal server error.' ),
				{
					sites: {
						[ currentSiteUrl ]: progress( {
							status: 'running',
							total: 200,
							done: 100,
						} ),
					},
				},
			],
		} );

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ { [ currentSiteUrl ]: [ 'post' ] } }
				setNotice={ vi.fn() }
				saving={ false }
				setSaving={ vi.fn() }
			/>
		);

		expect(
			screen.queryByText( 'Not indexed yet.' )
		).not.toBeInTheDocument();

		await act( async () => {
			vi.advanceTimersByTime( INDEX_POLL_INTERVAL );
		} );

		expect(
			screen.getByText( 'Indexing… 100 of 200 items.' )
		).toBeInTheDocument();

		vi.useRealTimers();
	} );

	it( 'says that re-indexing starts over a run that is going', async () => {
		mockApi( {
			'GET /onesearch/v1/indexer': {
				sites: {
					[ currentSiteUrl ]: progress( {
						status: 'running',
						total: 200,
						done: 100,
					} ),
				},
			},
		} );

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ { [ currentSiteUrl ]: [ 'post' ] } }
				setNotice={ vi.fn() }
				saving={ false }
				setSaving={ vi.fn() }
			/>
		);

		await screen.findByText( 'Indexing… 100 of 200 items.' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Re-index' } ) );

		expect(
			within( await screen.findByRole( 'dialog' ) ).getByText(
				'Indexing is already running. Re-indexing will start it over.'
			)
		).toBeInTheDocument();
	} );

	it( "shows that a failed index didn't finish, without announcing it when the screen loads", async () => {
		mockApi( {
			'GET /onesearch/v1/indexer': {
				sites: {
					[ currentSiteUrl ]: progress( {
						status: 'failed',
						total: 267,
						done: 100,
						errors: [ 'Indexing was cancelled.' ],
					} ),
				},
			},
		} );

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ { [ currentSiteUrl ]: [ 'post' ] } }
				setNotice={ vi.fn() }
				saving={ false }
				setSaving={ vi.fn() }
			/>
		);

		expect(
			await screen.findByText(
				"Indexing didn't finish, so search may show outdated or missing content. Re-index to try again."
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Indexing was cancelled.' )
		).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toBeEmptyDOMElement();
	} );

	it( 'shows why post types failed to load for a brand site', async () => {
		render(
			<SiteIndexableEntities
				sites={ [ { name: 'Brand Site', url: brandSiteUrl } ] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ {} }
				postTypeErrors={ [
					{ site_url: brandSiteUrl, message: 'Request timed out.' },
				] }
				setNotice={ vi.fn() }
				saving={ false }
				setSaving={ vi.fn() }
			/>
		);

		expect(
			await screen.findByText( 'Request timed out.' )
		).toBeInTheDocument();
		expect(
			screen.queryByText(
				'No entities to select. Please check site configuration'
			)
		).not.toBeInTheDocument();
	} );

	it( 'disables saving when the saved entities failed to load', async () => {
		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ {} }
				loadFailed
				setNotice={ vi.fn() }
				saving={ false }
				setSaving={ vi.fn() }
			/>
		);

		expect(
			await screen.findByRole( 'button', { name: 'Select entities…' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Save Changes' } )
		).toBeDisabled();
	} );

	it( 'shows the error notice when saving entities fails', async () => {
		const setNotice = vi.fn();
		const setSaving = vi.fn();

		mockApi( {
			'GET /onesearch/v1/indexer': {
				sites: { [ currentSiteUrl ]: null },
			},
			'POST /wp/v2/settings': new Error(
				'Invalid parameter(s): onesearch_indexable_entities'
			),
		} );

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ {} }
				setNotice={ setNotice }
				onEntitiesSaved={ vi.fn() }
				saving={ false }
				setSaving={ setSaving }
			/>
		);

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

	it( 'opens and closes re-index modal and handles server errors', async () => {
		const setNotice = vi.fn();

		mockApi( {
			'GET /onesearch/v1/indexer': {
				sites: { [ currentSiteUrl ]: null },
			},
			'POST /wp/v2/settings': new Error( 'Internal server error.' ),
			'POST /onesearch/v1/indexer': new Error(
				"Indexing couldn't be started."
			),
		} );

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				indexableEntities={ { [ currentSiteUrl ]: [ 'post' ] } }
				setNotice={ setNotice }
				onEntitiesSaved={ vi.fn() }
				saving={ false }
				setSaving={ vi.fn() }
			/>
		);

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
				message: 'Internal server error.',
				type: 'error',
			} );
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Re-index' } ) );

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
				message: "Indexing couldn't be started.",
				type: 'error',
			} );
		} );
	} );
} );
