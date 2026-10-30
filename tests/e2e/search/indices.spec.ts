/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	BRAND_SITE,
	BRAND_SITE_KEY,
	GOVERNING_SITE_URL,
	SITE_TYPE,
	connectSites,
	expect,
	notices,
	test,
	type OneSearchUtils,
} from '../_support';

// The open entity picker: a list of post-type checkboxes in a popover.
function entityMenu( page: Page ) {
	return page.getByRole( 'list' ).filter( {
		has: page.getByRole( 'checkbox', { name: 'Posts', exact: true } ),
	} );
}

// Keep the destination with each saved record, so a write to the wrong index cannot pass.
async function indexedRecords( site: OneSearchUtils ) {
	return ( await site.algoliaRequests() )
		.filter( ( { path } ) => path.endsWith( '/batch' ) )
		.flatMap( ( { path, body } ) =>
			( body.requests ?? [] )
				.filter( ( { action } ) => action === 'addObject' )
				.map( ( { body: record } ) => ( { path, record } ) )
		);
}

test.describe( 'indices and search screen', () => {
	test( 'opens only once a brand site is connected', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setSiteType( SITE_TYPE.governing );
		await oneSearch.saveAlgoliaCredentials();

		await admin.visitAdminPage( ADMIN_PAGE.indices );
		await expect(
			page.getByRole( 'heading', { name: 'Setup Required' } )
		).toBeVisible();

		await connectSites( oneSearch, brandSite );

		await admin.visitAdminPage( ADMIN_PAGE.indices );
		await expect(
			page.getByRole( 'heading', { name: 'Select Entities to Index' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Site Search Configuration' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Setup Required' } )
		).toHaveCount( 0 );
	} );

	test( 'entities picked for both sites index each site’s own posts and survive a reload', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		const token = `onesearch${ Date.now() }`;
		await oneSearch.createPost( `Governing post ${ token }` );
		await brandSite.createPost( `Brand post ${ token }` );
		await connectSites( oneSearch, brandSite, { algolia: true } );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page.getByRole( 'group', {
			name: 'Indexable entities for Governing Site',
		} );
		const governingChips = governing.getByRole( 'button', {
			name: 'Entities to index for Governing Site',
		} );
		const brand = page.getByRole( 'group', {
			name: `Indexable entities for ${ BRAND_SITE.name }`,
		} );
		const brandChips = brand.getByRole( 'button', {
			name: `Entities to index for ${ BRAND_SITE.name }`,
		} );

		await test.step( 'pick Posts from each site’s own post types', async () => {
			await expect( governing ).toContainText( GOVERNING_SITE_URL );
			await governingChips.click();
			await expect(
				entityMenu( page ).getByRole( 'checkbox', {
					name: 'Brand Items',
				} )
			).toHaveCount( 0 );
			await entityMenu( page )
				.getByRole( 'checkbox', { name: 'Posts', exact: true } )
				.check();
			await governingChips.click();

			await brandChips.click();
			await expect(
				entityMenu( page ).getByRole( 'checkbox', {
					name: 'Brand Items',
				} )
			).toBeVisible();
			await entityMenu( page )
				.getByRole( 'checkbox', { name: 'Posts', exact: true } )
				.check();
			await brandChips.click();
		} );

		await test.step( 'saving re-indexes both sites', async () => {
			await page.getByRole( 'button', { name: 'Save Changes' } ).click();

			// The governing site re-indexes every brand site too, and reports success only if all of them did.
			await expect( notices( page ) ).toContainText(
				'Re-indexing scheduled successfully.'
			);
			// The recorder persists at PHP shutdown, so wait for the records themselves.
			const batchPath = '/1/indexes/onesearch_localhost_wp_posts/batch';
			await expect
				.poll( () => indexedRecords( oneSearch ) )
				.toContainEqual( {
					path: batchPath,
					record: expect.objectContaining( {
						post_title: `Governing post ${ token }`,
						site_url: GOVERNING_SITE_URL,
						objectID: expect.any( String ),
					} ),
				} );
			await expect
				.poll( () => indexedRecords( brandSite ) )
				.toContainEqual( {
					path: batchPath,
					record: expect.objectContaining( {
						post_title: `Brand post ${ token }`,
						site_url: BRAND_SITE_KEY,
						objectID: expect.any( String ),
					} ),
				} );
			expect(
				( await indexedRecords( oneSearch ) ).map(
					( { record } ) => record[ 'site_url' ]
				)
			).not.toContain( BRAND_SITE_KEY );
			expect(
				( await indexedRecords( brandSite ) ).map(
					( { record } ) => record[ 'site_url' ]
				)
			).not.toContain( GOVERNING_SITE_URL );
		} );

		await admin.visitAdminPage( ADMIN_PAGE.indices );
		await expect(
			governing.getByRole( 'button', { name: 'Remove Posts' } )
		).toBeVisible();
		await expect(
			brand.getByRole( 'button', { name: 'Remove Posts' } )
		).toBeVisible();
	} );
} );
