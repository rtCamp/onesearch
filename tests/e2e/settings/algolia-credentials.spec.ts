/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	ALGOLIA_CREDENTIALS,
	expect,
	notices,
	test,
} from '../scaffold';

/**
 * The save endpoint validates the key against Algolia over the network, so the
 * browser-side call is stubbed here. What is asserted is the UI contract: which
 * controls are live, and how success and failure are reported.
 */
test.describe( 'Algolia credentials', () => {
	test.beforeEach( async ( { oneSearch } ) => {
		await oneSearch.setUpGoverningSite();
	} );

	test( 'keeps saving disabled until both fields are filled', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		const save = page.getByRole( 'button', { name: 'Save Credentials' } );
		await expect( save ).toBeDisabled();

		await page
			.getByRole( 'textbox', { name: 'Application ID*' } )
			.fill( ALGOLIA_CREDENTIALS.app_id );
		await expect( save ).toBeDisabled();

		await page
			.getByLabel( 'Write API Key*' )
			.fill( ALGOLIA_CREDENTIALS.write_key );
		await expect( save ).toBeEnabled();
	} );

	test( 'reports a successful save', async ( { admin, oneSearch, page } ) => {
		await oneSearch.stubAlgoliaSave();
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page
			.getByRole( 'textbox', { name: 'Application ID*' } )
			.fill( ALGOLIA_CREDENTIALS.app_id );
		await page
			.getByLabel( 'Write API Key*' )
			.fill( ALGOLIA_CREDENTIALS.write_key );
		await page.getByRole( 'button', { name: 'Save Credentials' } ).click();

		await expect( notices( page ) ).toContainText(
			'Algolia credentials saved successfully.'
		);

		// Saved values are no longer a pending change.
		await expect(
			page.getByRole( 'button', { name: 'Save Credentials' } )
		).toBeDisabled();
	} );

	test( 'reports rejected credentials', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.stubAlgoliaSave( false );
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page
			.getByRole( 'textbox', { name: 'Application ID*' } )
			.fill( 'WRONG' );
		await page.getByLabel( 'Write API Key*' ).fill( 'wrong-key' );
		await page.getByRole( 'button', { name: 'Save Credentials' } ).click();

		await expect( notices( page ) ).toContainText(
			'Error saving Algolia credentials. Please try again later.'
		);
	} );

	test( 'loads stored credentials and masks the write key', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite( { algolia: true } );
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await expect(
			page.getByRole( 'textbox', { name: 'Application ID*' } )
		).toHaveValue( ALGOLIA_CREDENTIALS.app_id );

		const writeKey = page.getByLabel( 'Write API Key*' );
		await expect( writeKey ).toHaveValue( ALGOLIA_CREDENTIALS.write_key );
		await expect( writeKey ).toHaveAttribute( 'type', 'password' );
	} );
} );
