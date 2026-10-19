/**
 * External dependencies
 */
import { useState } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

import SiteModal from '@/components/SiteModal';
import { defaultBrandSite } from '@/admin/settings/page';

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const baseFormData = {
	...defaultBrandSite,
	name: 'Brand Site',
	url: 'https://brand.example.com',
	api_key: 'secret-key',
};

function SiteModalHarness( {
	initialData = baseFormData,
	editing = false,
	sites = [],
	originalData,
	onSubmit = jest.fn().mockResolvedValue( true ),
}: {
	initialData?: typeof defaultBrandSite;
	editing?: boolean;
	sites?: Array< typeof defaultBrandSite >;
	originalData?: typeof defaultBrandSite;
	onSubmit?: jest.Mock< Promise< boolean >, [] >;
} ) {
	const [ formData, setFormData ] = useState( initialData );

	return (
		<SiteModal
			formData={ formData }
			setFormData={ setFormData }
			onSubmit={ onSubmit }
			onClose={ jest.fn() }
			editing={ editing }
			sites={ sites }
			originalData={ originalData }
		/>
	);
}

describe( 'SiteModal', () => {
	it( 'disables submit when editing without changes', () => {
		render(
			<SiteModalHarness
				editing
				initialData={ baseFormData }
				originalData={ baseFormData }
			/>
		);

		expect(
			screen.getByRole( 'button', { name: 'Update Site' } )
		).toBeDisabled();
	} );

	it( 'shows validation feedback for invalid input', async () => {
		render(
			<SiteModalHarness
				initialData={ {
					name: 'A very long site name beyond',
					url: 'invalid-url',
					api_key: 'secret-key',
				} }
			/>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Add Site' } ) );

		// WordPress renders notices in both accessibility region and visible content
		expect(
			await screen.findAllByText(
				'Site Name must be under 20 characters.'
			)
		).toHaveLength( 2 );
	} );

	it( 'prevents duplicate site urls', async () => {
		const onSubmit = jest.fn().mockResolvedValue( true );

		render(
			<SiteModalHarness
				onSubmit={ onSubmit }
				sites={ [
					{
						name: 'Existing',
						url: 'https://brand.example.com/',
						api_key: 'existing-key',
					},
				] }
			/>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Add Site' } ) );

		// WordPress renders notices in both accessibility region and visible content
		expect(
			await screen.findAllByText(
				'Site URL already exists. Please use a different URL.'
			)
		).toHaveLength( 2 );
		expect( onSubmit ).not.toHaveBeenCalled();
	} );

	it( 'shows an error when saving the site fails', async () => {
		const onSubmit = jest.fn().mockResolvedValue( false );

		render( <SiteModalHarness onSubmit={ onSubmit } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add Site' } ) );

		// WordPress renders notices in both accessibility region and visible content
		expect(
			await screen.findAllByText(
				'An error occurred while saving the site. Please try again.'
			)
		).toHaveLength( 2 );
		expect( onSubmit ).toHaveBeenCalled();
	} );

	it( 'submits without calling the brand site, which the server checks on save', async () => {
		const onSubmit = jest.fn().mockResolvedValue( true );

		render( <SiteModalHarness onSubmit={ onSubmit } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Add Site' } ) );

		await waitFor( () => {
			expect( onSubmit ).toHaveBeenCalled();
		} );

		expect( mockedApiFetch ).not.toHaveBeenCalled();
		expect( global.fetch ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );
} );
