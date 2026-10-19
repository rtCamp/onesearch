/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

import AlgoliaSettings from '@/components/AlgoliaSettings';

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

describe( 'AlgoliaSettings', () => {
	beforeEach( () => {
		window.OneSearchSettings = {
			...window.OneSearchSettings,
			algoliaAppId: 'app-id',
			hasAlgoliaCredentials: true,
		};
	} );

	afterEach( () => {
		jest.clearAllMocks();
	} );

	it( 'starts from the saved app ID and needs the write key typed in again to save', () => {
		render( <AlgoliaSettings setNotice={ jest.fn() } /> );

		const appIdInput = screen.getByRole( 'textbox', {
			name: /Application ID/i,
		} );
		const writeKeyInput = screen.getByLabelText( 'Write API Key*' );
		const saveButton = screen.getByRole( 'button', {
			name: /Save Credentials/i,
		} );

		expect( appIdInput ).toHaveValue( 'app-id' );
		expect( writeKeyInput ).toHaveValue( '' );
		expect( writeKeyInput ).toHaveAttribute(
			'placeholder',
			'A key is saved. Enter it again to save changes.'
		);
		expect( mockedApiFetch ).not.toHaveBeenCalled();

		fireEvent.change( appIdInput, { target: { value: 'updated-app' } } );
		expect( saveButton ).toBeDisabled();

		fireEvent.change( writeKeyInput, { target: { value: 'write-key' } } );
		expect( saveButton ).toBeEnabled();
	} );

	it( 'saves changed credentials and reports success', async () => {
		const setNotice = jest.fn();

		mockedApiFetch.mockImplementation( ( params ) => {
			if (
				params &&
				typeof params === 'object' &&
				( params as Record< string, unknown > )[ 'method' ] === 'POST'
			) {
				return Promise.resolve( {
					message: 'Algolia credentials updated successfully.',
				} );
			}
			return Promise.resolve( {} );
		} );

		render( <AlgoliaSettings setNotice={ setNotice } /> );

		const appIdInput = screen.getByRole( 'textbox', {
			name: /Application ID/i,
		} );
		fireEvent.change( appIdInput, { target: { value: 'updated-app' } } );
		fireEvent.change( screen.getByLabelText( /Write API Key/i ), {
			target: { value: 'write-key' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Credentials/i } )
		);

		await waitFor( () => {
			expect( mockedApiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					path: '/onesearch/v1/algolia-credentials',
					method: 'POST',
					data: expect.objectContaining( {
						app_id: 'updated-app',
						write_key: 'write-key',
					} ),
				} )
			);
		} );

		expect( setNotice ).toHaveBeenCalledWith(
			expect.objectContaining( {
				type: 'success',
				message: expect.stringMatching(
					/Algolia credentials saved successfully/i
				),
			} )
		);
	} );

	it( 'reports an error when saving fails', async () => {
		const setNotice = jest.fn();

		mockedApiFetch.mockImplementation( ( params ) => {
			if (
				params &&
				typeof params === 'object' &&
				( params as Record< string, unknown > )[ 'method' ] === 'POST'
			) {
				return Promise.reject( new Error( 'save failed' ) );
			}
			return Promise.resolve( {} );
		} );

		render( <AlgoliaSettings setNotice={ setNotice } /> );

		// Use getByLabelText for password field (not a textbox role)
		const writeKeyInput = screen.getByLabelText( /Write API Key/i );
		fireEvent.change( writeKeyInput, { target: { value: 'new-key' } } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Credentials/i } )
		);

		await waitFor( () => {
			expect( setNotice ).toHaveBeenCalledWith(
				expect.objectContaining( {
					type: 'error',
					message: expect.stringMatching(
						/Error saving Algolia credentials/i
					),
				} )
			);
		} );
	} );
} );
