/**
 * Internal dependencies
 */
import { ADMIN_PAGE, expect, notices, OPTION, test } from '../e2e/scaffold';

const APP_ID = process.env[ 'ALGOLIA_APP_ID' ] ?? '';
const WRITE_KEY = process.env[ 'ALGOLIA_WRITE_KEY' ] ?? '';

/**
 * The one suite that talks to Algolia for real.
 *
 * The mocked suite proves the plugin handles the shapes it expects, not that
 * those are still the shapes Algolia sends. Kept out of the default run and out
 * of CI: it needs paid credentials, writes to a shared index, and fails whenever
 * Algolia does.
 *
 *     ALGOLIA_APP_ID=… ALGOLIA_WRITE_KEY=… npm run test:e2e:smoke
 */
test.describe( 'Algolia, for real', () => {
	test.skip(
		! APP_ID || ! WRITE_KEY,
		'Set ALGOLIA_APP_ID and ALGOLIA_WRITE_KEY to run the live smoke test.'
	);

	test.beforeEach( async ( { oneSearch } ) => {
		// Remove the mock transport, so the SDK reaches Algolia itself.
		await oneSearch.setAlgoliaMode( 'live' );
	} );

	test( 'accepts credentials Algolia recognises', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite();
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page
			.getByRole( 'textbox', { name: 'Application ID*' } )
			.fill( APP_ID );
		await page.getByLabel( 'Write API Key*' ).fill( WRITE_KEY );
		await page.getByRole( 'button', { name: 'Save Credentials' } ).click();

		await expect( notices( page ) ).toContainText(
			'Algolia credentials saved successfully.'
		);

		const stored = ( await oneSearch.getState() ).options[
			OPTION.algoliaCredentials
		];
		expect( stored ).toMatchObject( { app_id: APP_ID } );
	} );

	test( 'rejects a key Algolia does not recognise', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite();
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page
			.getByRole( 'textbox', { name: 'Application ID*' } )
			.fill( APP_ID );
		await page
			.getByLabel( 'Write API Key*' )
			.fill( 'definitely-not-a-real-write-key' );
		await page.getByRole( 'button', { name: 'Save Credentials' } ).click();

		await expect( notices( page ) ).toContainText(
			'Error saving Algolia credentials. Please try again later.'
		);

		expect(
			( await oneSearch.getState() ).options[ OPTION.algoliaCredentials ]
		).toBeNull();
	} );
} );
