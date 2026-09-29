/**
 * External dependencies
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import SiteIndexableEntities from '@/components/SiteIndexableEntities';

const currentSiteUrl = 'https://governing.example.com/';
const brandSiteUrl = 'https://brand.example.com/';

const okJson = ( data: unknown ) =>
	( {
		ok: true,
		json: jest.fn().mockResolvedValue( data ),
	} ) as unknown as Response;

const noActiveReindex = okJson( { success: true, active: false } );

describe( 'SiteIndexableEntities', () => {
	it( 'loads saved entities and enables reindexing when data exists', async () => {
		global.fetch = jest
			.fn()
			.mockResolvedValueOnce( noActiveReindex )
			.mockResolvedValueOnce(
				okJson( {
					indexableEntities: {
						entities: {
							[ currentSiteUrl ]: [ 'post' ],
						},
					},
				} )
			) as typeof fetch;

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				setNotice={ jest.fn() }
				onEntitiesSaved={ jest.fn() }
				saving={ false }
				setSaving={ jest.fn() }
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
		global.fetch = jest
			.fn()
			.mockResolvedValueOnce( noActiveReindex )
			.mockResolvedValueOnce(
				okJson( {
					indexableEntities: {
						entities: {},
					},
				} )
			) as typeof fetch;

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
				setNotice={ jest.fn() }
				onEntitiesSaved={ jest.fn() }
				saving={ false }
				setSaving={ jest.fn() }
			/>
		);

		expect(
			await screen.findByText(
				'No entities to select. Please check site configuration'
			)
		).toBeInTheDocument();
	} );

	it( 'saves entities and handles reindex error', async () => {
		const setNotice = jest.fn();
		const onEntitiesSaved = jest.fn();
		const setSaving = jest.fn();

		global.fetch = jest
			.fn()
			.mockResolvedValueOnce( noActiveReindex )
			.mockResolvedValueOnce(
				okJson( {
					indexableEntities: {
						entities: {},
					},
				} )
			)
			.mockResolvedValueOnce( okJson( { success: true } ) )
			.mockResolvedValueOnce(
				okJson( {
					jobs: [],
					total: 0,
					page: 1,
					per_page: 5,
					total_pages: 0,
				} )
			)
			.mockResolvedValueOnce(
				okJson( { success: false, message: 'Reindex failed.' } )
			) as typeof fetch;

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
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

		expect( setSaving ).toHaveBeenCalledWith( true );
		expect( setSaving ).toHaveBeenLastCalledWith( false );
		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				message: 'Reindex failed.',
				type: 'error',
			} );
		} );
	} );

	it( 'shows a fetch error when loading entities fails', async () => {
		const setNotice = jest.fn();
		global.fetch = jest
			.fn()
			.mockRejectedValue( new Error( 'load failed' ) ) as typeof fetch;

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ { [ currentSiteUrl ]: [] } }
				currentSiteUrl={ currentSiteUrl }
				setNotice={ setNotice }
				onEntitiesSaved={ jest.fn() }
				saving={ false }
				setSaving={ jest.fn() }
			/>
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith( {
				type: 'error',
				message: 'Error fetching indexable entities.',
			} );
		} );
	} );

	it( 'shows network error notice when saving entities gets non-ok response', async () => {
		const setNotice = jest.fn();
		const setSaving = jest.fn();

		global.fetch = jest
			.fn()
			.mockResolvedValueOnce( noActiveReindex )
			.mockResolvedValueOnce(
				okJson( {
					indexableEntities: {
						entities: {},
					},
				} )
			)
			.mockResolvedValueOnce( {
				ok: false,
				json: jest.fn(),
			} ) as typeof fetch;

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				setNotice={ setNotice }
				onEntitiesSaved={ jest.fn() }
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
				message: 'Network response was not ok.',
				type: 'error',
			} );
		} );
		expect( setSaving ).toHaveBeenLastCalledWith( false );
	} );

	it( 'opens and closes re-index modal and handles server errors', async () => {
		const setNotice = jest.fn();

		global.fetch = jest
			.fn()
			.mockResolvedValueOnce( noActiveReindex )
			.mockResolvedValueOnce(
				okJson( {
					indexableEntities: {
						entities: {
							[ currentSiteUrl ]: [ 'post' ],
						},
					},
				} )
			)
			.mockResolvedValueOnce(
				okJson( {
					success: false,
					data: { status: 500 },
				} )
			) as typeof fetch;

		render(
			<SiteIndexableEntities
				sites={ [] }
				allPostTypes={ {
					[ currentSiteUrl ]: [ { slug: 'post', label: 'Posts' } ],
				} }
				currentSiteUrl={ currentSiteUrl }
				setNotice={ setNotice }
				onEntitiesSaved={ jest.fn() }
				saving={ false }
				setSaving={ jest.fn() }
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

		global.fetch = jest
			.fn()
			.mockResolvedValueOnce(
				okJson( {
					jobs: [],
					total: 0,
					page: 1,
					per_page: 5,
					total_pages: 0,
				} )
			)
			.mockResolvedValueOnce(
				okJson( {
					jobs: [],
					total: 0,
					page: 1,
					per_page: 5,
					total_pages: 0,
				} )
			)
			.mockResolvedValueOnce(
				okJson( {
					jobs: [],
					total: 0,
					page: 1,
					per_page: 5,
					total_pages: 0,
				} )
			)
			.mockResolvedValueOnce(
				okJson( {
					success: false,
					message: 'failed',
				} )
			) as typeof fetch;

		fireEvent.click( screen.getByRole( 'button', { name: 'Re-index' } ) );

		expect(
			await screen.findByText( 'Re-index saved entities' )
		).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		await waitFor( () => {
			expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		} );

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
