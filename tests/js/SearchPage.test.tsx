/**
 * External dependencies
 */
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

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

let answerRequest: ( options: {
	path?: string;
	method?: string;
} ) => Promise< unknown >;

const mockRequests = ( {
	allPostTypes = { sites: {}, errors: [] },
	entitiesOk = true,
}: {
	allPostTypes?: Record< string, unknown >;
	entitiesOk?: boolean;
} = {} ) => {
	answerRequest = async ( { path } ) => {
		if ( path === '/onesearch/v1/all-post-types' ) {
			return allPostTypes;
		}
		if ( ! entitiesOk ) {
			throw new Error( 'load failed' );
		}
		return { onesearch_indexable_entities: { entities: {} } };
	};
};

const mountEntry = async (
	containerId: string,
	loadEntry: () => Promise< unknown >
) => {
	document.body.innerHTML = `<div id="${ containerId }"></div>`;
	vi.resetModules();
	// The fresh modules get their own apiFetch mock.
	const { default: apiFetch } = await import( '@wordpress/api-fetch' );
	vi.mocked( apiFetch ).mockImplementation(
		answerRequest as unknown as typeof apiFetch
	);
	// The entry point mounts itself into the container on import.
	await loadEntry();
};

const mountPage = () =>
	mountEntry(
		'onesearch-search-settings',
		() => import( '@/admin/search/index' )
	);

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
		mockRequests();
		await mountPage();

		expect( await screen.findByTestId( 'sites' ) ).toHaveTextContent(
			'Brand'
		);
	} );

	it( 'flags the entities as failed to load after an error response', async () => {
		mockRequests( { entitiesOk: false } );
		await mountPage();

		await waitFor( () => {
			expect( screen.getByTestId( 'load-failed' ) ).toHaveTextContent(
				'true'
			);
		} );
	} );

	it( 'passes on the sites that failed to return post types', async () => {
		mockRequests( {
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

	it.each( [
		[ 'Algolia credentials', { hasAlgoliaCredentials: false } ],
		[ 'a brand site', { sharedSites: [] } ],
	] )(
		'blocks the page with the setup modal until it has %s',
		async ( _, settings ) => {
			window.OneSearchSettings = {
				...window.OneSearchSettings,
				...settings,
			};
			mockRequests();
			await mountPage();

			expect(
				await screen.findByRole( 'dialog', { name: 'Setup Required' } )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'link', { name: 'Go to Settings' } )
			).toHaveAttribute(
				'href',
				'/wp-admin/admin.php?page=onesearch-settings'
			);
		}
	);

	it( 'shows the setup modal again after every brand site is deleted', async () => {
		let storedSites = [
			{ id: 'brand', name: 'Brand', url: 'https://brand.example.com/' },
		];
		answerRequest = async ( { path, method = 'GET' } ) => {
			if (
				method === 'DELETE' &&
				path === '/onesearch/v1/shared-sites/brand'
			) {
				storedSites = [];
				return {
					governing_site_notified: true,
					message: 'Brand site removed successfully.',
				};
			}
			if ( path === '/onesearch/v1/all-post-types' ) {
				return { sites: {}, errors: [] };
			}
			return { onesearch_indexable_entities: { entities: {} } };
		};
		window.OneSearchSettings = {
			...window.OneSearchSettings,
			sharedSites: storedSites,
		};

		await mountEntry(
			'onesearch-settings-page',
			() => import( '@/admin/settings/index' )
		);
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Delete' } )
		);
		fireEvent.click(
			within(
				await screen.findByRole( 'dialog', {
					name: 'Delete Brand Site',
				} )
			).getByRole( 'button', { name: 'Delete' } )
		);
		await waitFor( () => {
			expect( screen.queryByText( 'Brand' ) ).not.toBeInTheDocument();
		} );

		window.OneSearchSettings = {
			...window.OneSearchSettings,
			sharedSites: storedSites,
		};
		await mountPage();

		expect(
			await screen.findByRole( 'dialog', { name: 'Setup Required' } )
		).toBeInTheDocument();
	} );

	it( 'shows no setup modal once a brand site and Algolia credentials are set', async () => {
		mockRequests();
		await mountPage();

		await screen.findByTestId( 'entities' );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'removes the snackbar once it times out', async () => {
		mockRequests();
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
