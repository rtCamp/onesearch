/**
 * External dependencies
 */
import type { Locator } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	BRAND_SITE,
	GOVERNING_SITE_ORIGIN,
	OPTION,
	expect,
	notices,
	test,
} from '../scaffold';

/**
 * Fill the add/edit brand site modal.
 *
 * @param dialog      The open modal.
 * @param site        The values to enter.
 * @param site.name   Brand site name.
 * @param site.url    Brand site URL.
 * @param site.apiKey Brand site API key.
 */
async function fillSiteModal(
	dialog: Locator,
	site: { name: string; url: string; apiKey: string }
): Promise< void > {
	await dialog
		.getByRole( 'textbox', { name: 'Site Name*' } )
		.fill( site.name );
	await dialog.getByRole( 'textbox', { name: 'Site URL*' } ).fill( site.url );
	await dialog
		.getByRole( 'textbox', { name: 'API Key*' } )
		.fill( site.apiKey );
}

test.describe( 'brand site management', () => {
	test.beforeEach( async ( { brandSite, oneSearch } ) => {
		await oneSearch.setUpGoverningSite();
		await brandSite.setUpBrandSite( { apiKey: BRAND_SITE.api_key } );
	} );

	test( 'adds a brand site and keeps it after a reload', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page.getByRole( 'button', { name: 'Add Brand Site' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Add Brand Site' } );
		await fillSiteModal( dialog, {
			name: BRAND_SITE.name,
			url: BRAND_SITE.url,
			apiKey: BRAND_SITE.api_key,
		} );
		await dialog.getByRole( 'button', { name: 'Add Site' } ).click();

		await expect( notices( page ) ).toContainText(
			'Brand Site saved successfully.'
		);

		const row = page
			.getByRole( 'row' )
			.filter( { hasText: BRAND_SITE.name } );
		await expect( row ).toBeVisible();
		await expect( row ).toContainText( BRAND_SITE.url );

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await expect(
			page.getByRole( 'row' ).filter( { hasText: BRAND_SITE.name } )
		).toBeVisible();

		const stored = ( await oneSearch.getState() ).options[
			OPTION.sharedSites
		];
		expect( Array.isArray( stored ) && stored ).toHaveLength( 1 );

		// The health check is what tells the brand site who governs it.
		const brandState = await brandSite.getState();
		expect( brandState.options[ OPTION.parentSiteUrl ] ).toBe(
			GOVERNING_SITE_ORIGIN
		);
	} );
} );
