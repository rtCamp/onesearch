/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	ALGOLIA_CREDENTIALS,
	SITE_TYPE,
	expect,
	notices,
	test,
} from '../_support';

test.describe( 'Algolia credentials', () => {
	test( 'saved credentials come back after a reload', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		// Credentials outlive the reset, so a fresh app ID is what proves this save stored it.
		const appId = `E2E${ Date.now() }`;

		await oneSearch.setSiteType( SITE_TYPE.governing );
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page
			.getByRole( 'textbox', { name: 'Application ID*' } )
			.fill( appId );
		await page
			.getByLabel( 'Write API Key*' )
			.fill( ALGOLIA_CREDENTIALS.write_key );
		await page.getByRole( 'button', { name: 'Save Credentials' } ).click();

		await expect( notices( page ) ).toContainText(
			'Algolia credentials saved successfully.'
		);

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await expect(
			page.getByRole( 'textbox', { name: 'Application ID*' } )
		).toHaveValue( appId );
	} );
} );
