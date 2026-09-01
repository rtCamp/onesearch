/**
 * Internal dependencies
 */
import { ADMIN_PAGE, expect, test } from '../scaffold';

test.describe( 'settings screen', () => {
	test( 'renders the governing controls for a governing site', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		const uncaught: string[] = [];
		page.on( 'pageerror', ( error ) => uncaught.push( error.message ) );

		await oneSearch.setUpGoverningSite();
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await expect(
			page.getByRole( 'heading', { name: 'Brand Sites' } )
		).toBeVisible();
		await expect( page.getByText( 'No Brand Sites found.' ) ).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Algolia Credentials' } )
		).toBeVisible();

		// The API key and governing-site cards belong to brand sites only.
		await expect(
			page.getByRole( 'heading', { name: 'API Key' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'heading', { name: 'Governing Site Connection' } )
		).toHaveCount( 0 );

		expect( uncaught ).toEqual( [] );
	} );

	test( 'renders the brand controls for a brand site', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		const uncaught: string[] = [];
		page.on( 'pageerror', ( error ) => uncaught.push( error.message ) );

		await oneSearch.setUpBrandSite();
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await expect(
			page.getByRole( 'heading', { name: 'API Key' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Governing Site Connection' } )
		).toBeVisible();

		// Brand sites do not manage other sites, nor Algolia.
		await expect(
			page.getByRole( 'heading', { name: 'Brand Sites' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'heading', { name: 'Algolia Credentials' } )
		).toHaveCount( 0 );

		expect( uncaught ).toEqual( [] );
	} );

	test( 'reaches the settings screen from the plugin action link', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite();
		await admin.visitAdminPage( ADMIN_PAGE.plugins );

		await page
			.getByRole( 'row' )
			.filter( { hasText: 'OneSearch' } )
			.getByRole( 'link', { name: 'Settings' } )
			.click();

		await page.waitForURL( /page=onesearch-settings/ );
		await expect(
			page.getByRole( 'heading', { name: 'Brand Sites' } )
		).toBeVisible();
	} );
} );
