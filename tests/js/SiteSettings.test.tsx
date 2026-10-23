/**
 * External dependencies
 */
import {
	describe,
	expect,
	it,
	type Mock,
	type MockedFunction,
	vi,
} from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

import SiteSettings from '@/components/SiteSettings';

const mockedApiFetch = apiFetch as MockedFunction< typeof apiFetch >;

const okJson = ( data: unknown ) =>
	( {
		ok: true,
		json: vi.fn().mockResolvedValue( data ),
	} ) as unknown as Response;

const siteSettingsFetch = ( handlers: {
	governingSiteUrl?: string;
	regeneratedKey?: string;
	deleteOk?: boolean;
	disconnectResponse?: Record< string, unknown >;
	failGoverningSite?: boolean;
	failRegenerate?: boolean;
	failDisconnect?: boolean;
} ) =>
	vi.fn( async ( input: RequestInfo | URL, init?: RequestInit ) => {
		const url = String( input );
		const method = init?.method ?? 'GET';

		if ( url.includes( '/secret-key' ) && method === 'POST' ) {
			if ( handlers.failRegenerate ) {
				return { ok: false } as Response;
			}

			return okJson( { secret_key: handlers.regeneratedKey ?? '' } );
		}

		if ( url.endsWith( '/wp/v2/settings' ) && method === 'GET' ) {
			if ( handlers.failGoverningSite ) {
				return { ok: false } as Response;
			}

			return okJson( {
				onesearch_parent_site_url: handlers.governingSiteUrl ?? '',
			} );
		}

		if ( url.endsWith( '/governing-site' ) && method === 'DELETE' ) {
			if ( handlers.failDisconnect ) {
				return { ok: false } as Response;
			}

			return {
				ok: handlers.deleteOk ?? true,
				json: vi.fn().mockResolvedValue(
					handlers.disconnectResponse ?? {
						governing_site_notified: true,
					}
				),
			} as unknown as Response;
		}

		return { ok: false } as Response;
	} );

describe( 'SiteSettings', () => {
	it( 'loads the api key and governing site details', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
		} );

		render( <SiteSettings /> );

		expect(
			await screen.findByDisplayValue( 'api-key' )
		).toBeInTheDocument();
		expect(
			screen.getByDisplayValue( 'https://governing.example.com/' )
		).toBeInTheDocument();
	} );

	it( 'copies the api key to the clipboard and shows success feedback', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'api-key' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Copy API Key' } )
		);

		await waitFor( () => {
			expect( navigator.clipboard.writeText ).toHaveBeenCalledWith(
				'api-key'
			);
		} );

		expect(
			await screen.findByText( 'API key copied to clipboard.', {
				selector: '.components-notice__content',
			} )
		).toBeInTheDocument();
	} );

	it( 'regenerates the api key and reports success', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
			regeneratedKey: 'new-secret',
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'api-key' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Regenerate API Key' } )
		);

		// Wait for the API call to complete - that's the important behavior
		await waitFor( () => {
			expect( global.fetch ).toHaveBeenCalledWith(
				expect.stringContaining( '/secret-key' ),
				expect.objectContaining( { method: 'POST' } )
			);
		} );
	} );

	it( 'disconnects from the governing site after confirmation', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
			deleteOk: true,
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'https://governing.example.com/' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Disconnect Governing Site' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Disconnect' } ) );

		expect(
			await screen.findByText(
				'Governing site disconnected successfully.',
				{
					selector: '.components-notice__content',
				}
			)
		).toBeInTheDocument();
		expect( screen.getByDisplayValue( '' ) ).toBeInTheDocument();
	} );

	it( 'warns when the governing site could not be notified of the disconnection', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
			disconnectResponse: {
				governing_site_notified: false,
				message: 'The governing site could not be notified.',
			},
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'https://governing.example.com/' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Disconnect Governing Site' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Disconnect' } ) );

		expect(
			await screen.findByText(
				'The governing site could not be notified.',
				{
					selector: '.components-notice__content',
				}
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Governing site disconnected successfully.' )
		).not.toBeInTheDocument();
		expect( screen.getByDisplayValue( '' ) ).toBeInTheDocument();
	} );

	it( 'shows an error notice when copying the api key fails', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
		} );
		navigator.clipboard.writeText = vi
			.fn()
			.mockRejectedValueOnce( new Error( 'clipboard failed' ) );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'api-key' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Copy API Key' } )
		);

		expect(
			await screen.findByText(
				'Failed to copy api key. Please try again. Error: clipboard failed',
				{ selector: '.components-notice__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows an error notice when the clipboard api is unavailable', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
		} );
		const clipboard = navigator.clipboard;
		Object.defineProperty( navigator, 'clipboard', {
			value: undefined,
			configurable: true,
		} );

		try {
			render( <SiteSettings /> );

			await screen.findByDisplayValue( 'api-key' );
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Copy API Key' } )
			);

			expect(
				await screen.findByText(
					'Failed to copy api key. Please try again.',
					{ selector: '.components-notice__content' }
				)
			).toBeInTheDocument();
		} finally {
			Object.defineProperty( navigator, 'clipboard', {
				value: clipboard,
				configurable: true,
			} );
		}
	} );

	it( 'shows an error notice when disconnecting the governing site fails', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
			failDisconnect: true,
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'https://governing.example.com/' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Disconnect Governing Site' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Disconnect' } ) );

		expect(
			await screen.findByText(
				'Failed to disconnect governing site. Please try again later.',
				{ selector: '.components-notice__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows an error notice when loading the governing site fails', async () => {
		global.fetch = siteSettingsFetch( {
			failGoverningSite: true,
		} );

		render( <SiteSettings /> );

		expect(
			await screen.findByText(
				'Failed to fetch governing site. Please try again later.',
				{ selector: '.components-notice__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows an error notice when regeneration returns no secret key', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
			regeneratedKey: '',
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'api-key' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Regenerate API Key' } )
		);

		expect(
			await screen.findByText(
				'Failed to regenerate API key. Please try again later.',
				{ selector: '.components-notice__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows an error notice when regenerating the api key fails', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
			failRegenerate: true,
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'api-key' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Regenerate API Key' } )
		);

		expect(
			await screen.findByText(
				'Error regenerating API key. Please try again later.',
				{ selector: '.components-notice__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'closes the disconnect modal when cancel is clicked', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'https://governing.example.com/' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Disconnect Governing Site' } )
		);

		expect(
			screen.getByText(
				'Are you sure you want to disconnect from the governing site? This action cannot be undone.'
			)
		).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );

		await waitFor( () => {
			expect(
				screen.queryByText(
					'Are you sure you want to disconnect from the governing site? This action cannot be undone.'
				)
			).not.toBeInTheDocument();
		} );
	} );

	it( 'loads once on mount with the nonce only, and does not reload after regenerating', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
			regeneratedKey: 'new-secret',
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'api-key' );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		( global.fetch as Mock ).mock.calls.forEach( ( [ , init ] ) => {
			expect( init?.headers ).not.toHaveProperty( 'X-OneSearch-Token' );
			expect( init?.headers ).toHaveProperty( 'X-WP-Nonce', 'nonce' );
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Regenerate API Key' } )
		);

		expect( await screen.findByDisplayValue( 'new-secret' ) ).toBeVisible();
		// Nothing but the regeneration request was made after the initial load.
		expect( global.fetch ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'keeps the key field selectable for a manual copy', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
		} );

		render( <SiteSettings /> );

		const field = await screen.findByDisplayValue( 'api-key' );
		expect( field ).toBe( screen.getByLabelText( 'API Key' ) );
		expect( field ).not.toBeDisabled();
		expect( field ).toHaveAttribute( 'readonly' );
	} );

	it( "shows the site's own indexing progress once it's connected, and that the governing site re-indexes it", async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
		} );
		mockedApiFetch.mockResolvedValueOnce( {
			sites: {
				[ window.OneSearchSettings.currentSiteUrl ]: {
					id: 'run-id',
					status: 'failed',
					total: 10,
					done: 10,
					failed: 0,
					errors: [ 'No governing site is configured.' ],
					started_at: 1767225600,
				},
			},
		} );

		render( <SiteSettings /> );

		expect( await screen.findByText( 'Index Status' ) ).toBeInTheDocument();
		expect(
			await screen.findByText( 'No governing site is configured.' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				"Indexing didn't finish, so search may show outdated or missing content. Re-index from the governing site to try again."
			)
		).toBeInTheDocument();
	} );

	it( 'says which changes failed to sync since the last indexing run, and why', async () => {
		global.fetch = siteSettingsFetch( {
			governingSiteUrl: 'https://governing.example.com/',
		} );
		mockedApiFetch.mockResolvedValueOnce( {
			sites: {
				[ window.OneSearchSettings.currentSiteUrl ]: {
					id: 'run-id',
					status: 'completed',
					total: 10,
					done: 10,
					failed: 0,
					errors: [],
					started_at: 1767225600,
				},
			},
			failed_jobs: {
				[ window.OneSearchSettings.currentSiteUrl ]: [
					{
						job: 'onesearch_sync_post',
						args: [ 12 ],
						error: 'Failed to save records to Algolia index. Unreachable hosts',
					},
					{
						job: 'onesearch_sync_post',
						args: [ 10 ],
						error: 'unexpected shutdown: PHP Fatal error Allowed memory size exhausted in render.php on line 3',
					},
				],
			},
		} );

		render( <SiteSettings /> );

		expect(
			await screen.findByText(
				'2 changes failed to sync. Re-index from the governing site to fix them.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Search is up to date.' )
		).not.toBeInTheDocument();
		expect(
			screen.getByText(
				'Post 10: unexpected shutdown: PHP Fatal error Allowed memory size exhausted in render.php on line 3'
			)
		).toBeInTheDocument();
	} );

	it( 'hides the index status while the site is not connected', async () => {
		global.fetch = siteSettingsFetch( {} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'api-key' );
		expect( screen.queryByText( 'Index Status' ) ).not.toBeInTheDocument();
	} );
} );
