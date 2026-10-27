/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	OPTION,
	SITE_TYPE,
	type SiteType,
	expect,
	test,
} from '../scaffold';

function indicesMenuLink( page: Page ) {
	return page
		.getByRole( 'navigation', { name: 'Main menu' } )
		.getByRole( 'link', { name: 'Indices and Search' } );
}

test.describe( 'first-run setup', () => {
	// Called from the tests, so the `oneSearch` fixture has reset the plugin before its notice is followed.
	async function chooseSiteType( page: Page, siteType: SiteType ) {
		await page
			.getByRole( 'link', {
				name: 'Choose whether this is a governing or a brand site',
			} )
			.click();
		await page
			.getByRole( 'combobox', { name: 'Site Type' } )
			.selectOption( siteType );
		await page
			.getByRole( 'button', { name: 'Select Current Site Type' } )
			.click();
	}

	test( 'choosing governing shows the governing screens and menu', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		const uncaught: string[] = [];
		page.on( 'pageerror', ( error ) => uncaught.push( error.message ) );

		await admin.visitAdminPage( ADMIN_PAGE.plugins );
		await chooseSiteType( page, SITE_TYPE.governing );

		await expect(
			page.getByRole( 'heading', { name: 'Brand Sites' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Algolia Credentials' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'API Key' } )
		).toHaveCount( 0 );
		await expect( indicesMenuLink( page ) ).toBeVisible();

		await expect
			.poll(
				async () => ( await oneSearch.getSettings() )[ OPTION.siteType ]
			)
			.toBe( SITE_TYPE.governing );
		expect( uncaught ).toEqual( [] );
	} );

	test( 'choosing brand shows the API key and no indices menu', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		const uncaught: string[] = [];
		page.on( 'pageerror', ( error ) => uncaught.push( error.message ) );

		await admin.visitAdminPage( ADMIN_PAGE.plugins );
		await chooseSiteType( page, SITE_TYPE.brand );

		await expect(
			page.getByRole( 'heading', { name: 'API Key' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Governing Site Connection' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Brand Sites' } )
		).toHaveCount( 0 );
		await expect( indicesMenuLink( page ) ).toHaveCount( 0 );

		await expect
			.poll(
				async () => ( await oneSearch.getSettings() )[ OPTION.siteType ]
			)
			.toBe( SITE_TYPE.brand );
		expect( uncaught ).toEqual( [] );
	} );
} );
