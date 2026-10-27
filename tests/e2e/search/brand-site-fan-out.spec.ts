/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	BRAND_SITE,
	connectSites,
	expect,
	test,
} from '../scaffold';

/**
 * The governing site builds the indices screen by asking every brand site for
 * its post types, so this needs both installs running.
 */
test.describe( 'brand site fan-out', () => {
	test( 'lists a brand site that answers', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		await connectSites( oneSearch, brandSite, { algolia: true } );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const brand = page.getByRole( 'group', {
			name: `Indexable entities for ${ BRAND_SITE.name }`,
		} );

		await expect(
			brand.getByRole( 'button', {
				name: `Entities to index for ${ BRAND_SITE.name }`,
			} )
		).toBeVisible();
		await expect( brand ).not.toContainText(
			'No entities to select. Please check site configuration'
		);
	} );
} );
