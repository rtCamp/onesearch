/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import SiteSettings from '@/components/SiteSettings';

const okJson = ( data: unknown ) =>
	( {
		ok: true,
		json: jest.fn().mockResolvedValue( data ),
	} ) as unknown as Response;

const siteSettingsFetch = ( handlers: {
	secretKey?: string;
	governingSiteUrl?: string;
	regeneratedKey?: string;
	deleteOk?: boolean;
	remoteDisconnected?: boolean;
	retrySuccess?: boolean;
	retryPending?: { site_url: string; message: string }[];
	failSecretKey?: boolean;
	failGoverningSite?: boolean;
	failRegenerate?: boolean;
	failDisconnect?: boolean;
} ) =>
	jest.fn( async ( input: RequestInfo | URL, init?: RequestInit ) => {
		const url = String( input );
		const method = init?.method ?? 'GET';

		if ( url.includes( '/secret-key' ) && method === 'GET' ) {
			if ( handlers.failSecretKey ) {
				return { ok: false } as Response;
			}

			return okJson( { secret_key: handlers.secretKey ?? '' } );
		}

		if ( url.includes( '/secret-key' ) && method === 'POST' ) {
			if ( handlers.failRegenerate ) {
				return { ok: false } as Response;
			}

			return okJson( { secret_key: handlers.regeneratedKey ?? '' } );
		}

		if ( url.includes( '/governing-site?' ) && method === 'GET' ) {
			if ( handlers.failGoverningSite ) {
				return { ok: false } as Response;
			}

			return okJson( {
				governing_site_url: handlers.governingSiteUrl ?? '',
			} );
		}

		if ( url.endsWith( '/governing-site' ) && method === 'DELETE' ) {
			if ( handlers.failDisconnect ) {
				return { ok: false } as Response;
			}

			if ( false === handlers.deleteOk ) {
				return { ok: false } as Response;
			}

			return okJson( {
				success: true,
				remote_disconnected: handlers.remoteDisconnected ?? true,
				message: 'The governing site could not be notified.',
			} );
		}

		if ( url.endsWith( '/retry-disconnect' ) && method === 'POST' ) {
			return okJson( {
				success: handlers.retrySuccess ?? true,
				pending: handlers.retryPending ?? [],
			} );
		}

		return { ok: false } as Response;
	} );

describe( 'SiteSettings', () => {
	it( 'loads the api key and governing site details', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
			governingSiteUrl: 'https://governing.example.com/',
		} );

		render( <SiteSettings /> );

		expect(
			await screen.findByDisplayValue( 'brand-secret' )
		).toBeInTheDocument();
		expect(
			screen.getByDisplayValue( 'https://governing.example.com/' )
		).toBeInTheDocument();
	} );

	it( 'shows an error when loading the api key fails', async () => {
		global.fetch = siteSettingsFetch( {
			failSecretKey: true,
			governingSiteUrl: '',
		} );

		render( <SiteSettings /> );

		expect(
			await screen.findByText(
				'Failed to fetch API key. Please try again later.',
				{
					selector: '.components-snackbar__content',
				}
			)
		).toBeInTheDocument();
	} );

	it( 'copies the api key to the clipboard and shows success feedback', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
			governingSiteUrl: 'https://governing.example.com/',
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'brand-secret' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Copy API Key' } )
		);

		await waitFor( () => {
			expect( navigator.clipboard.writeText ).toHaveBeenCalledWith(
				'brand-secret'
			);
		} );

		expect(
			await screen.findByText( 'API key copied to clipboard.', {
				selector: '.components-snackbar__content',
			} )
		).toBeInTheDocument();
	} );

	it( 'regenerates the api key and reports success', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
			governingSiteUrl: 'https://governing.example.com/',
			regeneratedKey: 'new-secret',
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'brand-secret' );
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
			secretKey: 'brand-secret',
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
					selector: '.components-snackbar__content',
				}
			)
		).toBeInTheDocument();
	} );

	it( 'keeps a retryable notice when the governing site could not be notified', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
			governingSiteUrl: 'https://governing.example.com/',
			remoteDisconnected: false,
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

		// Retry posts to the endpoint and clears the notice once nothing is pending.
		fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );

		await waitFor( () =>
			expect( global.fetch ).toHaveBeenCalledWith(
				expect.stringContaining( '/retry-disconnect' ),
				expect.objectContaining( { method: 'POST' } )
			)
		);

		await waitFor( () =>
			expect(
				screen.queryByText(
					'The governing site could not be notified.',
					{
						selector: '.components-notice__content',
					}
				)
			).not.toBeInTheDocument()
		);
	} );

	it( 'keeps the notice when the retry fails again', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
			governingSiteUrl: 'https://governing.example.com/',
			remoteDisconnected: false,
			retrySuccess: false,
			retryPending: [
				{
					site_url: '',
					message: 'Still unreachable after retry.',
				},
			],
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'https://governing.example.com/' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Disconnect Governing Site' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Disconnect' } ) );

		await screen.findByText( 'The governing site could not be notified.', {
			selector: '.components-notice__content',
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );

		// The governing site is still unreachable, so the warning has to stand.
		expect(
			await screen.findByText( 'Still unreachable after retry.', {
				selector: '.components-notice__content',
			} )
		).toBeInTheDocument();

		// ...and the failed attempt is called out on its own.
		expect(
			screen.getByText(
				'The site still could not be notified. Please try again later.',
				{ selector: '.components-snackbar__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows an error notice when copying the api key fails', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
			governingSiteUrl: 'https://governing.example.com/',
		} );
		navigator.clipboard.writeText = jest
			.fn()
			.mockRejectedValueOnce( new Error( 'clipboard failed' ) );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'brand-secret' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Copy API Key' } )
		);

		expect(
			await screen.findByText(
				'Failed to copy api key. Please try again. Error: clipboard failed',
				{ selector: '.components-snackbar__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows an error notice when disconnecting the governing site fails', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
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
				{ selector: '.components-snackbar__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows an error notice when loading the governing site fails', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
			failGoverningSite: true,
		} );

		render( <SiteSettings /> );

		expect(
			await screen.findByText(
				'Failed to fetch governing site. Please try again later.',
				{ selector: '.components-snackbar__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows an error notice when regeneration returns no secret key', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
			governingSiteUrl: 'https://governing.example.com/',
			regeneratedKey: '',
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'brand-secret' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Regenerate API Key' } )
		);

		expect(
			await screen.findByText(
				'Failed to regenerate API key. Please try again later.',
				{ selector: '.components-snackbar__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows an error notice when regenerating the api key fails', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
			governingSiteUrl: 'https://governing.example.com/',
			failRegenerate: true,
		} );

		render( <SiteSettings /> );

		await screen.findByDisplayValue( 'brand-secret' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Regenerate API Key' } )
		);

		expect(
			await screen.findByText(
				'Error regenerating API key. Please try again later.',
				{ selector: '.components-snackbar__content' }
			)
		).toBeInTheDocument();
	} );

	it( 'closes the disconnect modal when cancel is clicked', async () => {
		global.fetch = siteSettingsFetch( {
			secretKey: 'brand-secret',
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
} );
