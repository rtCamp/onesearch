/**
 * Internal dependencies
 */
import { ADMIN_PAGE, OPTION, SITE_TYPE, expect, test } from '../scaffold';

/**
 * The onboarding screen renders into a container the plugin prints on every
 * admin screen until a site type is stored. The container carries no role of its
 * own, so it is addressed by id and everything inside it by role.
 */
const ONBOARDING = '#onesearch-site-selection-modal';

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

		const onboarding = page.locator( ONBOARDING );

		await expect(
			onboarding.getByRole( 'heading', { name: 'OneSearch' } )
		).toBeVisible();
		await expect(
			onboarding.getByRole( 'combobox', { name: 'Site Type' } )
		).toHaveValue( '' );
		await expect(
			onboarding.getByRole( 'button', {
				name: 'Select Current Site Type',
			} )
		).toBeDisabled();
	} );

	test( 'choosing the governing role opens settings and adds the indices menu', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.plugins );

		const onboarding = page.locator( ONBOARDING );

		await onboarding
			.getByRole( 'combobox', { name: 'Site Type' } )
			.selectOption( SITE_TYPE.governing );
		await onboarding
			.getByRole( 'button', { name: 'Select Current Site Type' } )
			.click();

		await page.waitForURL( /page=onesearch-settings/ );

		await expect(
			page.getByRole( 'heading', { name: 'Brand Sites' } )
		).toBeVisible();
		await expect(
			page
				.getByRole( 'navigation', { name: 'Main menu' } )
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

		const onboarding = page.locator( ONBOARDING );

		await onboarding
			.getByRole( 'combobox', { name: 'Site Type' } )
			.selectOption( SITE_TYPE.brand );
		await onboarding
			.getByRole( 'button', { name: 'Select Current Site Type' } )
			.click();

		await page.waitForURL( /page=onesearch-settings/ );

		await expect(
			page.getByRole( 'heading', { name: 'API Key' } )
		).toBeVisible();
		await expect(
			page
				.getByRole( 'navigation', { name: 'Main menu' } )
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

		await expect( page.locator( ONBOARDING ) ).toHaveCount( 0 );
	} );
} );
