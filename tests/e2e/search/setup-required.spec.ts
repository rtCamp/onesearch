/**
 * Internal dependencies
 */
import { ADMIN_PAGE, BRAND_SITE, expect, test } from '../scaffold';

test.describe( 'indices and search prerequisites', () => {
	test( 'blocks the screen until a brand site and Algolia are configured', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite();
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		await expect(
			page.getByRole( 'heading', { name: 'Setup Required' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Go to Settings' } )
		).toBeVisible();
	} );

	test( 'opens the screen once both prerequisites are met', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite( {
			brandSites: [ BRAND_SITE ],
			algolia: true,
		} );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		await expect(
			page.getByRole( 'heading', { name: 'Setup Required' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'heading', { name: 'Select Entities to Index' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Site Search Configuration' } )
		).toBeVisible();
	} );
} );
