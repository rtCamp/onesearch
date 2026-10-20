/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';

vi.mock( '@/components/SiteIndexableEntities', () => ( {
	__esModule: true,
	default: ( {
		setNotice,
		postTypeErrors,
		loadFailed,
		sites,
	}: {
		setNotice: ( notice: { type: string; message: string } ) => void;
		postTypeErrors?: Array< { message: string } >;
		loadFailed?: boolean;
		sites: Array< { name: string } >;
	} ) => (
		<div data-testid="entities">
			<span data-testid="load-failed">{ String( !! loadFailed ) }</span>
			<span data-testid="sites">
				{ sites.map( ( site ) => site.name ).join( ',' ) }
			</span>
			<span data-testid="errors">
				{ ( postTypeErrors ?? [] )
					.map( ( error ) => error.message )
					.join( ',' ) }
			</span>
			<button
				type="button"
				onClick={ () =>
					setNotice( { type: 'success', message: 'Hello there' } )
				}
			>
				Notify
			</button>
		</div>
	),
} ) );

vi.mock( '@/components/SiteSearchSettings', () => ( {
	__esModule: true,
	default: () => <div data-testid="search-settings" />,
} ) );

const json = ( data: unknown, ok = true ) =>
	( {
		ok,
		status: ok ? 200 : 500,
		json: () => Promise.resolve( data ),
	} ) as unknown as Response;

let loadEntities: () => Promise< unknown >;

const mockFetch = ( {
	allPostTypes = { sites: {}, errors: [] },
	entitiesOk = true,
}: {
	allPostTypes?: Record< string, unknown >;
	entitiesOk?: boolean;
} = {} ) => {
	global.fetch = vi.fn( async ( input: RequestInfo | URL ) => {
		const url = String( input );
		if ( url.endsWith( '/all-post-types' ) ) {
			return json( allPostTypes );
		}
		return json( {}, false );
	} ) as unknown as typeof fetch;
	loadEntities = () =>
		entitiesOk
			? Promise.resolve( {
					onesearch_indexable_entities: { entities: {} },
				} )
			: Promise.reject( new Error( 'load failed' ) );
};

const mountPage = async () => {
	document.body.innerHTML = '<div id="onesearch-search-settings"></div>';
	vi.resetModules();
	// The fresh modules get their own apiFetch mock, which loads the entities from /wp/v2/settings.
	const { default: apiFetch } = await import( '@wordpress/api-fetch' );
	vi.mocked( apiFetch ).mockImplementation( loadEntities );
	// The entry point mounts itself into the container on import.
	await import( '@/admin/search/index' );
};

describe( 'search settings page', () => {
	beforeEach( () => {
		window.OneSearchSettings = {
			...window.OneSearchSettings,
			sharedSites: [
				{ name: 'Brand', url: 'https://brand.example.com/' },
			],
			hasAlgoliaCredentials: true,
			siteType: 'governing-site',
		};
		// The page mounts its own root, outside of act().
		( globalThis as Record< string, unknown > )[
			'IS_REACT_ACT_ENVIRONMENT'
		] = false;
	} );

	afterEach( () => {
		( globalThis as Record< string, unknown > )[
			'IS_REACT_ACT_ENVIRONMENT'
		] = true;
		vi.useRealTimers();
		document.body.innerHTML = '';
	} );

	it( 'reads the site type and brand sites from the localized data', async () => {
		mockFetch();
		await mountPage();

		expect( await screen.findByTestId( 'sites' ) ).toHaveTextContent(
			'Brand'
		);
	} );

	it( 'flags the entities as failed to load after an error response', async () => {
		mockFetch( { entitiesOk: false } );
		await mountPage();

		await waitFor( () => {
			expect( screen.getByTestId( 'load-failed' ) ).toHaveTextContent(
				'true'
			);
		} );
	} );

	it( 'passes on the sites that failed to return post types', async () => {
		mockFetch( {
			allPostTypes: {
				sites: {},
				errors: [
					{
						site_url: 'https://brand.example.com/',
						message: 'Request timed out.',
					},
				],
			},
		} );
		await mountPage();

		await waitFor( () => {
			expect( screen.getByTestId( 'errors' ) ).toHaveTextContent(
				'Request timed out.'
			);
		} );
	} );

	it( 'gives the setup modal an accessible name', async () => {
		window.OneSearchSettings = {
			...window.OneSearchSettings,
			hasAlgoliaCredentials: false,
		};
		mockFetch();
		await mountPage();

		expect(
			await screen.findByRole( 'dialog', { name: 'Setup Required' } )
		).toBeInTheDocument();
	} );

	it( 'removes the snackbar once it times out', async () => {
		mockFetch();
		await mountPage();

		const notify = await screen.findByRole( 'button', { name: 'Notify' } );
		vi.useFakeTimers();
		notify.click();
		await waitFor( () => {
			expect(
				document.querySelector( '.components-snackbar' )
			).not.toBeNull();
		} );

		vi.advanceTimersByTime( 10000 );
		await waitFor( () => {
			expect(
				document.querySelector( '.components-snackbar' )
			).toBeNull();
		} );
	} );
} );
