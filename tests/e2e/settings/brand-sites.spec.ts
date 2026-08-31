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
	UNREACHABLE_BRAND,
	connectSites,
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

	test( 'requires every field before the site can be submitted', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await page.getByRole( 'button', { name: 'Add Brand Site' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Add Brand Site' } );
		const submit = dialog.getByRole( 'button', { name: 'Add Site' } );

		await expect( submit ).toBeDisabled();

		await dialog
			.getByRole( 'textbox', { name: 'Site Name*' } )
			.fill( BRAND_SITE.name );
		await expect( submit ).toBeDisabled();

		await dialog
			.getByRole( 'textbox', { name: 'Site URL*' } )
			.fill( BRAND_SITE.url );
		await expect( submit ).toBeDisabled();

		await dialog
			.getByRole( 'textbox', { name: 'API Key*' } )
			.fill( BRAND_SITE.api_key );
		await expect( submit ).toBeEnabled();
	} );

	test( 'rejects a malformed site URL before probing the site', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await page.getByRole( 'button', { name: 'Add Brand Site' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Add Brand Site' } );
		await fillSiteModal( dialog, {
			name: BRAND_SITE.name,
			url: 'brand-alpha',
			apiKey: BRAND_SITE.api_key,
		} );
		await dialog.getByRole( 'button', { name: 'Add Site' } ).click();

		await expect(
			dialog.getByText(
				'Enter a valid URL (must start with http or https).'
			)
		).toBeVisible();
	} );

	test( 'surfaces a failed health check instead of saving', async ( {
		admin,
		brandSite,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page.getByRole( 'button', { name: 'Add Brand Site' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Add Brand Site' } );
		await fillSiteModal( dialog, {
			name: BRAND_SITE.name,
			url: BRAND_SITE.url,
			apiKey: 'wrong-key',
		} );
		await dialog.getByRole( 'button', { name: 'Add Site' } ).click();

		await expect(
			dialog.getByText( 'Health check failed', { exact: false } )
		).toBeVisible();
		await expect( page.getByText( 'No Brand Sites found.' ) ).toBeVisible();

		// A rejected key must not connect the brand site either.
		const brandState = await brandSite.getState();
		expect( brandState.options[ OPTION.parentSiteUrl ] ).toBeNull();
	} );

	test( 'rejects a URL that is already registered', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		await connectSites( oneSearch, brandSite );
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page.getByRole( 'button', { name: 'Add Brand Site' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Add Brand Site' } );
		await fillSiteModal( dialog, {
			name: 'Duplicate',
			url: BRAND_SITE.url,
			apiKey: BRAND_SITE.api_key,
		} );
		await dialog.getByRole( 'button', { name: 'Add Site' } ).click();

		await expect(
			dialog.getByText(
				'Site URL already exists. Please use a different URL.'
			)
		).toBeVisible();
	} );

	test( 'edits an existing brand site', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		await connectSites( oneSearch, brandSite );
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page
			.getByRole( 'row' )
			.filter( { hasText: BRAND_SITE.name } )
			.getByRole( 'button', { name: 'Edit' } )
			.click();

		const dialog = page.getByRole( 'dialog', { name: 'Edit Brand Site' } );
		const update = dialog.getByRole( 'button', { name: 'Update Site' } );

		// Nothing has changed yet, so there is nothing to save.
		await expect( update ).toBeDisabled();

		await dialog
			.getByRole( 'textbox', { name: 'Site Name*' } )
			.fill( 'Renamed Alpha' );
		await update.click();

		await expect( notices( page ) ).toContainText(
			'Brand Site saved successfully.'
		);

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await expect(
			page.getByRole( 'row' ).filter( { hasText: 'Renamed Alpha' } )
		).toBeVisible();
	} );

	test( 'deletes a brand site behind a confirmation', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite( {
			brandSites: [ BRAND_SITE, UNREACHABLE_BRAND ],
		} );
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page
			.getByRole( 'row' )
			.filter( { hasText: BRAND_SITE.name } )
			.getByRole( 'button', { name: 'Delete' } )
			.click();

		const dialog = page.getByRole( 'dialog', {
			name: 'Delete Brand Site',
		} );
		await expect(
			dialog.getByText(
				'Are you sure you want to delete this Brand Site? This action cannot be undone.'
			)
		).toBeVisible();

		await dialog.getByRole( 'button', { name: 'Delete' } ).click();

		await expect(
			page.getByRole( 'row' ).filter( { hasText: BRAND_SITE.name } )
		).toHaveCount( 0 );
		await expect(
			page
				.getByRole( 'row' )
				.filter( { hasText: UNREACHABLE_BRAND.name } )
		).toBeVisible();

		const stored = ( await oneSearch.getState() ).options[
			OPTION.sharedSites
		];
		expect( Array.isArray( stored ) && stored ).toHaveLength( 1 );
	} );

	test( 'keeps the delete confirmation cancellable', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite( { brandSites: [ BRAND_SITE ] } );
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await page
			.getByRole( 'row' )
			.filter( { hasText: BRAND_SITE.name } )
			.getByRole( 'button', { name: 'Delete' } )
			.click();

		await page
			.getByRole( 'dialog', { name: 'Delete Brand Site' } )
			.getByRole( 'button', { name: 'Cancel' } )
			.click();

		await expect(
			page.getByRole( 'row' ).filter( { hasText: BRAND_SITE.name } )
		).toBeVisible();
	} );
} );
