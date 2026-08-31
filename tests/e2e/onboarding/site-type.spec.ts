/**
 * Internal dependencies
 */
import { ADMIN_PAGE, OPTION, SITE_TYPE, expect, test } from '../scaffold';

test.describe( 'onboarding', () => {
	test( 'prompts for a site type on first run', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		expect(
			( await oneSearch.getState() ).options[ OPTION.siteType ]
		).toBe( null );

		await admin.visitAdminPage( ADMIN_PAGE.plugins );

		await expect( page.locator( 'body' ) ).toHaveClass(
			/onesearch-site-selection-modal/
		);

		const modal = page.locator( '#onesearch-site-selection-modal' );

		await expect(
			modal.getByRole( 'heading', { name: 'OneSearch' } )
		).toBeVisible();
		await expect(
			modal.getByRole( 'combobox', { name: 'Site Type' } )
		).toHaveValue( '' );
		await expect(
			modal.getByRole( 'button', { name: 'Select Current Site Type' } )
		).toBeDisabled();
	} );

	test( 'choosing the governing role opens settings and adds the indices menu', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.plugins );

		const modal = page.locator( '#onesearch-site-selection-modal' );

		await modal
			.getByRole( 'combobox', { name: 'Site Type' } )
			.selectOption( SITE_TYPE.governing );
		await modal
			.getByRole( 'button', { name: 'Select Current Site Type' } )
			.click();

		await page.waitForURL( /page=onesearch-settings/ );

		await expect(
			page.getByRole( 'heading', { name: 'Brand Sites' } )
		).toBeVisible();
		await expect(
			page
				.locator( '#adminmenu' )
				.getByRole( 'link', { name: 'Indices and Search' } )
		).toBeVisible();

		expect(
			( await oneSearch.getState() ).options[ OPTION.siteType ]
		).toBe( SITE_TYPE.governing );
	} );

	test( 'choosing the brand role exposes the API key and hides the indices menu', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.plugins );

		const modal = page.locator( '#onesearch-site-selection-modal' );

		await modal
			.getByRole( 'combobox', { name: 'Site Type' } )
			.selectOption( SITE_TYPE.brand );
		await modal
			.getByRole( 'button', { name: 'Select Current Site Type' } )
			.click();

		await page.waitForURL( /page=onesearch-settings/ );

		await expect(
			page.getByRole( 'heading', { name: 'API Key' } )
		).toBeVisible();
		await expect(
			page
				.locator( '#adminmenu' )
				.getByRole( 'link', { name: 'Indices and Search' } )
		).toHaveCount( 0 );

		expect(
			( await oneSearch.getState() ).options[ OPTION.siteType ]
		).toBe( SITE_TYPE.brand );
	} );

	test( 'does not prompt again once a site type is stored', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite();

		await admin.visitAdminPage( ADMIN_PAGE.plugins );

		await expect(
			page.locator( '#onesearch-site-selection-modal' )
		).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).not.toHaveClass(
			/onesearch-site-selection-modal/
		);
	} );
} );
