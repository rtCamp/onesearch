/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

// Mock child components to isolate SettingsPage behavior
jest.mock( '@/components/SiteTable', () => ( {
	__esModule: true,
	default: ( {
		sites,
		onEdit,
		onDelete,
		setFormData,
		setShowModal,
	}: {
		sites: Array< { name: string; url: string; api_key: string } >;
		onEdit: ( index: number ) => void;
		onDelete: ( index: number | null ) => void;
		setFormData: ( data: {
			name: string;
			url: string;
			api_key: string;
		} ) => void;
		setShowModal: ( show: boolean ) => void;
	} ) => (
		<div data-testid="site-table">
			<span data-testid="site-count">{ sites.length }</span>
			<button
				type="button"
				onClick={ () => {
					onEdit( 0 );
					setFormData( {
						name: 'Edited Brand Site',
						url: 'https://edited.example.com/',
						api_key: 'edited-key',
					} );
				} }
			>
				Edit Site
			</button>
			<button type="button" onClick={ () => setShowModal( true ) }>
				Open Modal
			</button>
			<button type="button" onClick={ () => onDelete( 0 ) }>
				Delete Site
			</button>
		</div>
	),
} ) );

jest.mock( '@/components/SiteModal', () => ( {
	__esModule: true,
	default: ( {
		onSubmit,
		onClose,
	}: {
		onSubmit: () => Promise< boolean >;
		onClose: () => void;
	} ) => (
		<div data-testid="site-modal">
			<button type="button" onClick={ () => void onSubmit() }>
				Submit Modal
			</button>
			<button type="button" onClick={ onClose }>
				Close Modal
			</button>
		</div>
	),
} ) );

jest.mock( '@/components/SiteSettings', () => ( {
	__esModule: true,
	default: () => <div data-testid="site-settings">Brand Site Settings</div>,
} ) );

jest.mock( '@/components/AlgoliaSettings', () => ( {
	__esModule: true,
	default: () => <div data-testid="algolia-settings">Algolia Settings</div>,
} ) );

import SettingsPage from '@/admin/settings/page';

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

describe( 'SettingsPage', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		window.OneSearchSettings = {
			...window.OneSearchSettings,
			siteType: 'governing-site',
		};
		document.body.className = '';
	} );

	it( 'loads shared sites and renders the page structure', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			shared_sites: [
				{
					name: 'Brand Site',
					url: 'https://brand.example.com/',
					api_key: 'key',
				},
			],
		} );

		render( <SettingsPage /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'site-count' ) ).toHaveTextContent(
				'1'
			);
		} );

		expect( screen.getByTestId( 'algolia-settings' ) ).toBeInTheDocument();
	} );

	it( 'shows an error notice when initial data load fails', async () => {
		mockedApiFetch.mockRejectedValueOnce( new Error( 'load failed' ) );

		render( <SettingsPage /> );

		// Snackbar component renders once (unlike Notice which renders twice for accessibility)
		const notices = await screen.findAllByText(
			'Error fetching settings data.'
		);
		expect( notices.length ).toBeGreaterThanOrEqual( 1 );
	} );

	it( 'saves site changes and shows success feedback', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( { shared_sites: [] } )
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Brand Site',
						url: 'https://brand.example.com/',
						api_key: 'key',
					},
				],
			} );

		render( <SettingsPage /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Open Modal' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Submit Modal' } )
		);

		// Snackbar renders in both accessibility region and visible content
		const notices = await screen.findAllByText(
			'Brand Site saved successfully.'
		);
		expect( notices.length ).toBeGreaterThanOrEqual( 1 );
	} );

	it( 'shows an error notice when saving fails', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( { shared_sites: [] } )
			.mockRejectedValueOnce( new Error( 'save failed' ) );

		render( <SettingsPage /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Open Modal' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Submit Modal' } )
		);

		// Snackbar renders in both accessibility region and visible content
		const notices = await screen.findAllByText(
			'Failed to update shared sites'
		);
		expect( notices.length ).toBeGreaterThanOrEqual( 1 );
	} );

	it( 'shows an error when response lacks shared sites', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Brand Site',
						url: 'https://brand.example.com/',
						api_key: 'key',
					},
				],
			} )
			.mockResolvedValueOnce( {} );

		render( <SettingsPage /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Open Modal' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Submit Modal' } )
		);

		// Snackbar renders in both accessibility region and visible content
		const notices = await screen.findAllByText(
			'Failed to update shared sites'
		);
		expect( notices.length ).toBeGreaterThanOrEqual( 1 );
	} );

	it( 'submits edit changes correctly', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Brand Site',
						url: 'https://brand.example.com/',
						api_key: 'key',
					},
				],
			} )
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Edited Brand Site',
						url: 'https://edited.example.com/',
						api_key: 'edited-key',
					},
				],
			} );

		render( <SettingsPage /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Edit Site' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Open Modal' } ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Submit Modal' } )
		);

		await waitFor( () => {
			expect( mockedApiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					method: 'POST',
					data: {
						sites_data: [
							{
								name: 'Edited Brand Site',
								url: 'https://edited.example.com/',
								api_key: 'edited-key',
							},
						],
					},
				} )
			);
		} );
	} );

	it( 'closes the modal via close action', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			shared_sites: [],
		} );

		render( <SettingsPage /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Open Modal' } )
		);
		expect( screen.getByTestId( 'site-modal' ) ).toBeInTheDocument();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Close Modal' } )
		);

		expect( screen.queryByTestId( 'site-modal' ) ).not.toBeInTheDocument();
	} );

	it( 'removes the missing sites body class when governing sites load', async () => {
		document.body.classList.add( 'onesearch-missing-brand-sites' );
		mockedApiFetch.mockResolvedValueOnce( {
			shared_sites: [
				{
					name: 'Brand Site',
					url: 'https://brand.example.com/',
					api_key: 'key',
				},
			],
		} );

		render( <SettingsPage /> );

		await waitFor( () => {
			expect( screen.getByTestId( 'site-count' ) ).toHaveTextContent(
				'1'
			);
		} );

		expect(
			document.body.classList.contains( 'onesearch-missing-brand-sites' )
		).toBe( false );
	} );

	it( 'removes missing sites class when deleting the last site', async () => {
		document.body.classList.add( 'onesearch-missing-brand-sites' );
		mockedApiFetch
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Brand Site',
						url: 'https://brand.example.com/',
						api_key: 'key',
					},
				],
			} )
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Remaining Site',
						url: 'https://remaining.example.com/',
						api_key: 'other-key',
					},
				],
			} );
		global.fetch = jest
			.fn()
			.mockResolvedValue( { ok: true, status: 200 } as Response );

		render( <SettingsPage /> );

		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Delete Site' } )
		);

		await waitFor( () => {
			expect(
				document.body.classList.contains(
					'onesearch-missing-brand-sites'
				)
			).toBe( false );
		} );
	} );

	it( 'shows an error and leaves the brand site alone when deleting fails', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Brand Site',
						url: 'https://brand.example.com/',
						api_key: 'brand-key',
					},
				],
			} )
			.mockRejectedValueOnce( new Error( 'delete failed' ) );
		global.fetch = jest.fn();

		render( <SettingsPage /> );

		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Delete Site' } )
		);

		const notices = await screen.findAllByText(
			'Failed to update shared sites'
		);
		expect( notices.length ).toBeGreaterThanOrEqual( 1 );
		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	it( 'tells a deleted brand site to disconnect from this site', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Brand Site',
						url: 'https://brand.example.com/',
						api_key: 'brand-key',
					},
					{
						name: 'Remaining Site',
						url: 'https://remaining.example.com/',
						api_key: 'other-key',
					},
				],
			} )
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Remaining Site',
						url: 'https://remaining.example.com/',
						api_key: 'other-key',
					},
				],
			} );
		global.fetch = jest
			.fn()
			.mockResolvedValue( { ok: true, status: 200 } as Response );

		render( <SettingsPage /> );

		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Delete Site' } )
		);

		await waitFor( () => {
			expect( global.fetch ).toHaveBeenCalledWith(
				'https://brand.example.com/wp-json/onesearch/v1/brand-site',
				expect.objectContaining( {
					method: 'DELETE',
					headers: expect.objectContaining( {
						'X-OneSearch-Token': 'brand-key',
					} ),
				} )
			);
		} );
		expect(
			screen.queryByText( /was removed, but/, {
				selector: '.components-notice__content',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'warns when a deleted brand site could not be notified', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Brand Site',
						url: 'https://brand.example.com/',
						api_key: 'brand-key',
					},
				],
			} )
			.mockResolvedValueOnce( { shared_sites: [] } );
		global.fetch = jest
			.fn()
			.mockRejectedValue( new TypeError( 'Failed to fetch' ) );

		render( <SettingsPage /> );

		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Delete Site' } )
		);

		// Deleting the last site would normally reload the page, which would clear this notice.
		const message =
			'Brand Site was removed, but it could not be notified. To finish disconnecting, click Disconnect Governing Site in its OneSearch settings.';
		expect(
			await screen.findByText( message, {
				selector: '.components-notice__content',
			} )
		).toBeInTheDocument();

		// The admin can dismiss it once they have acted on it.
		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		expect(
			screen.queryByText( message, {
				selector: '.components-notice__content',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'warns when a deleted brand site does not recognize this site', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Brand Site',
						url: 'https://brand.example.com/',
						api_key: 'brand-key',
					},
					{
						name: 'Remaining Site',
						url: 'https://remaining.example.com/',
						api_key: 'other-key',
					},
				],
			} )
			.mockResolvedValueOnce( {
				shared_sites: [
					{
						name: 'Remaining Site',
						url: 'https://remaining.example.com/',
						api_key: 'other-key',
					},
				],
			} );
		global.fetch = jest
			.fn()
			.mockResolvedValue( { ok: false, status: 401 } as Response );

		render( <SettingsPage /> );

		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Delete Site' } )
		);

		expect(
			await screen.findByText(
				/Brand Site was removed, but it did not recognize this site/,
				{ selector: '.components-notice__content' }
			)
		).toBeInTheDocument();
	} );
} );
