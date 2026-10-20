/**
 * External dependencies
 */
import { describe, expect, it, type MockedFunction, vi } from 'vitest';
import {
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

const mockedApiFetch = apiFetch as MockedFunction< typeof apiFetch >;

const currentSiteUrl = 'https://governing.example.com/';
const brandSiteUrl = 'https://brand.example.com/';

const okJson = ( data: unknown ) =>
	( {
		ok: true,
		json: vi.fn().mockResolvedValue( data ),
	} ) as unknown as Response;

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

		mockedApiFetch.mockResolvedValueOnce( {} );
		global.fetch = vi.fn().mockResolvedValueOnce(
			okJson( {
				message: 'Reindex complete.',
				errors: [],
			} )
		) as typeof fetch;

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

		expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
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
		expect( setNotice ).toHaveBeenCalledWith( {
			message: 'Reindex complete.',
			type: 'success',
		} );
	} );

	it( 'shows why post types failed to load for a brand site', () => {
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

		expect( screen.getByText( 'Request timed out.' ) ).toBeInTheDocument();
		expect(
			screen.queryByText(
				'No entities to select. Please check site configuration'
			)
		).not.toBeInTheDocument();
	} );

	it( 'disables saving when the saved entities failed to load', () => {
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
			screen.getByRole( 'button', { name: 'Select entities…' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Save Changes' } )
		).toBeDisabled();
	} );

	it( 'shows the error notice when saving entities fails', async () => {
		const setNotice = vi.fn();
		const setSaving = vi.fn();

		mockedApiFetch.mockRejectedValueOnce( {
			code: 'rest_invalid_param',
			message: 'Invalid parameter(s): onesearch_indexable_entities',
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

		mockedApiFetch.mockRejectedValueOnce( {
			code: 'internal_server_error',
			message: 'Internal server error.',
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

		global.fetch = vi.fn().mockResolvedValueOnce(
			okJson( {
				message: 'failed',
				errors: [ { site_url: currentSiteUrl, message: 'timed out' } ],
			} )
		) as typeof fetch;

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
				message: 'failed',
				type: 'error',
			} );
		} );
	} );
} );
