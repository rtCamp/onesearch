/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

import SiteSearchSettings from '@/components/SiteSearchSettings';

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const currentSiteUrl = 'https://governing.example.com/';
const brandSiteUrl = 'https://brand.example.com/';

const defaultEntities = {
	[ currentSiteUrl ]: [ 'post' ],
	[ brandSiteUrl ]: [ 'page' ],
};

const defaultPostTypes = {
	[ currentSiteUrl ]: [ { slug: 'post' } ],
	[ brandSiteUrl ]: [ { slug: 'page' } ],
};

describe( 'SiteSearchSettings', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		window.OneSearchSettings = {
			...window.OneSearchSettings,
			currentSiteUrl,
		};

		Object.defineProperty( window.OneSearchSettings, 'sharedSites', {
			value: [ { name: 'Brand Site', url: brandSiteUrl } ],
			writable: true,
			configurable: true,
		} );
	} );

	it( 'loads and saves search settings', async () => {
		const setNotice = jest.fn();

		mockedApiFetch
			.mockResolvedValueOnce( { onesearch_sites_search_settings: {} } )
			.mockResolvedValueOnce( {
				onesearch_sites_search_settings: {
					[ currentSiteUrl ]: {
						algolia_enabled: true,
						searchable_sites: [ currentSiteUrl ],
					},
					[ brandSiteUrl ]: {
						algolia_enabled: true,
						searchable_sites: [ brandSiteUrl ],
					},
				},
			} )
			.mockResolvedValueOnce( {
				onesearch_sites_search_settings: {
					[ currentSiteUrl ]: {
						algolia_enabled: true,
						searchable_sites: [ currentSiteUrl ],
					},
					[ brandSiteUrl ]: {
						algolia_enabled: true,
						searchable_sites: [ brandSiteUrl ],
					},
				},
			} );

		render(
			<SiteSearchSettings
				indexableEntities={ defaultEntities }
				allPostTypes={ defaultPostTypes }
				setNotice={ setNotice }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await screen.findByText( 'Site Search Configuration' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable All' } ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save Settings' } )
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				type: 'success',
				message: 'Search settings saved successfully.',
			} );
		} );
	} );

	it( 'warns when enabling sites with no indexable entities', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			onesearch_sites_search_settings: {},
		} );

		render(
			<SiteSearchSettings
				indexableEntities={ {
					[ currentSiteUrl ]: [ 'post' ],
					[ brandSiteUrl ]: [],
				} }
				allPostTypes={ defaultPostTypes }
				setNotice={ jest.fn() }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await screen.findByText( 'Site Search Configuration' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Enable All' } ) );

		expect(
			await screen.findAllByText(
				'Some sites were skipped because they have no content types selected for indexing. Please configure indexable entities for these sites first.'
			)
		).toHaveLength( 2 );
	} );

	it( 'auto-disables sites when entities are removed', async () => {
		const setNotice = jest.fn();

		mockedApiFetch
			.mockResolvedValueOnce( {
				onesearch_sites_search_settings: {
					[ currentSiteUrl ]: {
						algolia_enabled: true,
						searchable_sites: [ currentSiteUrl ],
					},
				},
			} )
			.mockResolvedValueOnce( {
				onesearch_sites_search_settings: {
					[ currentSiteUrl ]: {
						algolia_enabled: false,
						searchable_sites: [],
					},
				},
			} )
			.mockResolvedValueOnce( {
				onesearch_sites_search_settings: {
					[ currentSiteUrl ]: {
						algolia_enabled: false,
						searchable_sites: [],
					},
				},
			} );

		const { rerender } = render(
			<SiteSearchSettings
				indexableEntities={ { [ currentSiteUrl ]: [ 'post' ] } }
				allPostTypes={ { [ currentSiteUrl ]: [ { slug: 'post' } ] } }
				setNotice={ setNotice }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await screen.findByText( 'Site Search Configuration' );

		rerender(
			<SiteSearchSettings
				indexableEntities={ { [ currentSiteUrl ]: [] } }
				allPostTypes={ { [ currentSiteUrl ]: [ { slug: 'post' } ] } }
				setNotice={ setNotice }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				type: 'success',
				message:
					'Sites without indexable entities have been automatically disabled and saved.',
			} );
		} );
	} );

	it( 'does not save over unsaved edits when entities are removed', async () => {
		const setNotice = jest.fn();

		mockedApiFetch.mockResolvedValueOnce( {
			onesearch_sites_search_settings: {
				[ currentSiteUrl ]: {
					algolia_enabled: true,
					searchable_sites: [ currentSiteUrl ],
				},
				[ brandSiteUrl ]: {
					algolia_enabled: false,
					searchable_sites: [],
				},
			},
		} );

		const { rerender } = render(
			<SiteSearchSettings
				indexableEntities={ defaultEntities }
				allPostTypes={ defaultPostTypes }
				setNotice={ setNotice }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await screen.findByText( 'Site Search Configuration' );

		// An unsaved edit.
		fireEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Enable Algolia search for Brand Site',
			} )
		);

		rerender(
			<SiteSearchSettings
				indexableEntities={ {
					[ currentSiteUrl ]: [],
					[ brandSiteUrl ]: [ 'page' ],
				} }
				allPostTypes={ defaultPostTypes }
				setNotice={ setNotice }
				isIndexableEntitiesSaving={ false }
			/>
		);

		expect(
			await screen.findAllByText(
				'Sites without indexable entities have been disabled. Save your settings to apply this.'
			)
		).not.toHaveLength( 0 );
		// Only the initial load, nothing was saved.
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Enable Algolia search for Brand Site',
			} )
		).toBeChecked();
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Enable Algolia search for Governing Site',
			} )
		).not.toBeChecked();
		expect( setNotice ).not.toHaveBeenCalled();
	} );

	it( 'shows error when settings fail to load', async () => {
		const setNotice = jest.fn();
		mockedApiFetch.mockRejectedValueOnce( new Error( 'load failed' ) );

		render(
			<SiteSearchSettings
				indexableEntities={ defaultEntities }
				allPostTypes={ defaultPostTypes }
				setNotice={ setNotice }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				type: 'error',
				message: 'Failed to load search settings.',
			} );
		} );
	} );

	it( 'shows error when settings fail to save', async () => {
		const setNotice = jest.fn();

		mockedApiFetch
			.mockResolvedValueOnce( { onesearch_sites_search_settings: {} } )
			.mockRejectedValueOnce( new Error( 'save failed' ) )
			.mockResolvedValueOnce( { onesearch_sites_search_settings: {} } );

		render(
			<SiteSearchSettings
				indexableEntities={ defaultEntities }
				allPostTypes={ defaultPostTypes }
				setNotice={ setNotice }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await screen.findByText( 'Site Search Configuration' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Enable All' } ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save Settings' } )
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				type: 'error',
				message: 'Failed to save search settings.',
			} );
		} );
	} );

	it( 'disables all sites', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			onesearch_sites_search_settings: {
				[ currentSiteUrl ]: {
					algolia_enabled: true,
					searchable_sites: [ currentSiteUrl ],
				},
				[ brandSiteUrl ]: {
					algolia_enabled: true,
					searchable_sites: [ brandSiteUrl ],
				},
			},
		} );

		render(
			<SiteSearchSettings
				indexableEntities={ defaultEntities }
				allPostTypes={ defaultPostTypes }
				setNotice={ jest.fn() }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await screen.findByText( 'Site Search Configuration' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Disable All' } )
		);

		expect(
			screen.getAllByText( 'Using default WordPress search' )
		).toHaveLength( 2 );
		expect(
			screen.getByRole( 'button', { name: 'Save Settings' } )
		).toBeEnabled();
	} );

	it( 'disables buttons while entities are saving', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			onesearch_sites_search_settings: {},
		} );

		render(
			<SiteSearchSettings
				indexableEntities={ defaultEntities }
				allPostTypes={ defaultPostTypes }
				setNotice={ jest.fn() }
				isIndexableEntitiesSaving
			/>
		);

		await screen.findByText( 'Site Search Configuration' );

		expect(
			screen.getByRole( 'button', { name: 'Enable All' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Disable All' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Save Settings' } )
		).toBeDisabled();
	} );

	it( 'locks current site checkbox in searchable sites list', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			onesearch_sites_search_settings: {
				[ currentSiteUrl ]: {
					algolia_enabled: true,
					searchable_sites: [ currentSiteUrl, brandSiteUrl ],
				},
			},
		} );

		render(
			<SiteSearchSettings
				indexableEntities={ defaultEntities }
				allPostTypes={ defaultPostTypes }
				setNotice={ jest.fn() }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await screen.findByText( 'Site Search Configuration' );

		const currentSiteCheckbox = screen.getByRole( 'checkbox', {
			name: /Governing Site.*Current Site - Always Included/,
		} );

		expect( ( currentSiteCheckbox as HTMLInputElement ).disabled ).toBe(
			true
		);
		expect( ( currentSiteCheckbox as HTMLInputElement ).checked ).toBe(
			true
		);
	} );

	it( 'toggles searchable sites', async () => {
		const setNotice = jest.fn();

		// 1. Initial load, 2. Save, 3. Reload after save
		mockedApiFetch
			.mockResolvedValueOnce( {
				onesearch_sites_search_settings: {
					[ currentSiteUrl ]: {
						algolia_enabled: true,
						searchable_sites: [ currentSiteUrl ],
					},
				},
			} )
			.mockResolvedValueOnce( {
				onesearch_sites_search_settings: {
					[ currentSiteUrl ]: {
						algolia_enabled: true,
						searchable_sites: [ currentSiteUrl, brandSiteUrl ],
					},
				},
			} )
			.mockResolvedValueOnce( {
				onesearch_sites_search_settings: {
					[ currentSiteUrl ]: {
						algolia_enabled: true,
						searchable_sites: [ currentSiteUrl, brandSiteUrl ],
					},
				},
			} );

		render(
			<SiteSearchSettings
				indexableEntities={ defaultEntities }
				allPostTypes={ defaultPostTypes }
				setNotice={ setNotice }
				isIndexableEntitiesSaving={ false }
			/>
		);

		await screen.findByText( 'Site Search Configuration' );

		const brandSiteToggle = screen
			.getAllByRole( 'checkbox', { name: /Brand Site/ } )
			.find( ( cb ) => ! ( cb as HTMLInputElement ).disabled );

		expect( brandSiteToggle ).toBeTruthy();
		expect( ( brandSiteToggle as HTMLInputElement ).checked ).toBe( false );

		fireEvent.click( brandSiteToggle! );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save Settings' } )
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				type: 'success',
				message: 'Search settings saved successfully.',
			} );
		} );

		expect( mockedApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				data: {
					onesearch_sites_search_settings: {
						[ currentSiteUrl ]: {
							algolia_enabled: true,
							searchable_sites: expect.arrayContaining( [
								currentSiteUrl,
								brandSiteUrl,
							] ),
						},
					},
				},
			} )
		);
	} );
} );
