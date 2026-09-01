/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	BRAND_SITE,
	UNREACHABLE_BRAND,
	connectSites,
	expect,
	test,
} from '../scaffold';

/**
 * The governing site builds the indices screen by asking every brand site for
 * its post types. These cover what happens when that request does not come
 * back, which is only observable because the request is a real one.
 */
test.describe( 'brand site fan-out', () => {
	test( 'reports a brand site it cannot reach', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		await connectSites( oneSearch, brandSite, {
			brandSites: [ UNREACHABLE_BRAND ],
			algolia: true,
		} );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const brand = page.getByRole( 'group', {
			name: `Indexable entities for ${ UNREACHABLE_BRAND.name }`,
		} );

		await expect( brand ).toContainText(
			'No entities to select. Please check site configuration'
		);
	} );

	test( 'reports a brand site that rejects the stored key', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		// The brand site is reachable, but expects a different key, so its
		// permission check turns the fan-out away.
		await connectSites( oneSearch, brandSite, {
			brandApiKey: 'a-different-key-entirely',
			algolia: true,
		} );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const brand = page.getByRole( 'group', {
			name: `Indexable entities for ${ BRAND_SITE.name }`,
		} );

		await expect( brand ).toContainText(
			'No entities to select. Please check site configuration'
		);
	} );

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
