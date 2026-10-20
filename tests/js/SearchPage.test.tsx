/**
 * External dependencies
 */
import { screen, waitFor } from '@testing-library/react';

jest.mock( '@/components/SiteIndexableEntities', () => ( {
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

jest.mock( '@/components/SiteSearchSettings', () => ( {
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
	global.fetch = jest.fn( async ( input: RequestInfo | URL ) => {
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

const mountPage = () => {
	document.body.innerHTML = '<div id="onesearch-search-settings"></div>';
	jest.isolateModules( () => {
		// The isolated modules get their own apiFetch mock, which loads the entities from /wp/v2/settings.
		require( '@wordpress/api-fetch' ).default.mockImplementation(
			loadEntities
		);
		// The entry point mounts itself into the container on import.
		require( '@/admin/search/index' );
	} );
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
		jest.useRealTimers();
		document.body.innerHTML = '';
	} );

	it( 'reads the site type and brand sites from the localized data', async () => {
		mockFetch();
		mountPage();

		expect( await screen.findByTestId( 'sites' ) ).toHaveTextContent(
			'Brand'
		);
	} );

	it( 'flags the entities as failed to load after an error response', async () => {
		mockFetch( { entitiesOk: false } );
		mountPage();

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
		mountPage();

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
		mountPage();

		expect(
			await screen.findByRole( 'dialog', { name: 'Setup Required' } )
		).toBeInTheDocument();
	} );

	it( 'removes the snackbar once it times out', async () => {
		mockFetch();
		mountPage();

		const notify = await screen.findByRole( 'button', { name: 'Notify' } );
		jest.useFakeTimers();
		notify.click();
		await waitFor( () => {
			expect(
				document.querySelector( '.components-snackbar' )
			).not.toBeNull();
		} );

		jest.advanceTimersByTime( 10000 );
		await waitFor( () => {
			expect(
				document.querySelector( '.components-snackbar' )
			).toBeNull();
		} );
	} );
} );
