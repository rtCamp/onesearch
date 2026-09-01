/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	GOVERNING_SITE_URL,
	OPTION,
	expect,
	notices,
	test,
} from '../scaffold';

test.describe( 'brand site connection', () => {
	test( 'exposes an API key the governing site can be given', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpBrandSite();
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		const apiKeyField = page.getByRole( 'textbox' ).first();
		await expect( apiKeyField ).toBeDisabled();
		await expect( apiKeyField ).not.toHaveValue( '' );
	} );

	test( 'regenerates the API key', async ( { admin, oneSearch, page } ) => {
		await oneSearch.setUpBrandSite();
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		const apiKeyField = page.getByRole( 'textbox' ).first();
		await expect( apiKeyField ).not.toHaveValue( '' );
		const original = await apiKeyField.inputValue();

		await page
			.getByRole( 'button', { name: 'Regenerate API Key' } )
			.click();

		await expect( notices( page ) ).toContainText(
			'API key regenerated successfully.'
		);
		await expect( apiKeyField ).not.toHaveValue( original );
	} );

	test( 'cannot disconnect when no governing site is connected', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpBrandSite();
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await expect(
			page.getByRole( 'textbox', { name: 'Governing Site URL' } )
		).toHaveValue( '' );
		await expect(
			page.getByRole( 'button', { name: 'Disconnect Governing Site' } )
		).toBeDisabled();
	} );

	test( 'disconnects the governing site behind a confirmation', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpBrandSite( {
			governingSiteUrl: GOVERNING_SITE_URL,
		} );
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		const governingUrlField = page.getByRole( 'textbox', {
			name: 'Governing Site URL',
		} );
		await expect( governingUrlField ).toHaveValue(
			GOVERNING_SITE_URL.replace( /\/$/, '' )
		);

		await page
			.getByRole( 'button', { name: 'Disconnect Governing Site' } )
			.click();

		const dialog = page.getByRole( 'dialog', {
			name: 'Disconnect Governing Site',
		} );
		await expect(
			dialog.getByText(
				'Are you sure you want to disconnect from the governing site? This action cannot be undone.'
			)
		).toBeVisible();

		await dialog.getByRole( 'button', { name: 'Disconnect' } ).click();

		await expect( notices( page ) ).toContainText(
			'Governing site disconnected successfully.'
		);
		await expect( governingUrlField ).toHaveValue( '' );

		expect(
			( await oneSearch.getState() ).options[ OPTION.parentSiteUrl ]
		).toBe( null );
	} );
} );
