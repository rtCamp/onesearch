/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	ALGOLIA_CREDENTIALS,
	OPTION,
	expect,
	notices,
	test,
} from '../scaffold';

/**
 * The save endpoint validates the key by asking Algolia for its ACL. Only that
 * outbound call is mocked, so the endpoint itself runs: its permission check,
 * its required-arg validation, the ACL test, the encrypted option write, and
 * the mapping from a rejected key to a 400.
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

	test( 'saves credentials that Algolia accepts', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
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

		// The endpoint really stored them, rather than the UI merely saying so.
		const stored = ( await oneSearch.getState() ).options[
			OPTION.algoliaCredentials
		];
		expect( stored ).toMatchObject( {
			app_id: ALGOLIA_CREDENTIALS.app_id,
		} );

		// Reloading proves it came back from the database, not from component state.
		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await expect(
			page.getByRole( 'textbox', { name: 'Application ID*' } )
		).toHaveValue( ALGOLIA_CREDENTIALS.app_id );
	} );

	test( 'refuses a key Algolia says cannot write', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		// The key resolves, but its ACL lacks addObject/deleteObject.
		await oneSearch.setAlgoliaMode( 'invalid_key' );
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page
			.getByRole( 'textbox', { name: 'Application ID*' } )
			.fill( 'WRONG' );
		await page.getByLabel( 'Write API Key*' ).fill( 'wrong-key' );
		await page.getByRole( 'button', { name: 'Save Credentials' } ).click();

		await expect( notices( page ) ).toContainText(
			'Error saving Algolia credentials. Please try again later.'
		);

		// A rejected key must not be persisted.
		const stored = ( await oneSearch.getState() ).options[
			OPTION.algoliaCredentials
		];
		expect( stored ).toBeNull();
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
