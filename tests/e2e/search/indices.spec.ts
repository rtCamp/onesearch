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
	OPTION,
	connectSites,
	expect,
	notices,
	regenerateBrandApiKeyThroughUI,
	test,
	type OneSearchUtils,
	type TestPost,
} from '../_support';

// The open entity picker: a list of post-type checkboxes in a popover.
function entityMenu( page: Page ) {
	return page.getByRole( 'list' ).filter( {
		has: page.getByRole( 'checkbox', { name: 'Posts', exact: true } ),
	} );
}

// Indexing runs in background jobs, which can wait up to a minute for WP-Cron.
const INDEXING_TIMEOUT = 70_000;
// The screen refreshes job status every ten seconds.
const STATUS_TIMEOUT = 15_000;
// An indexing run, plus setup, recorder persistence and the UI's status polling.
const ONE_RUN_TIMEOUT = INDEXING_TIMEOUT + 60_000;
const TWO_RUNS_TIMEOUT = ONE_RUN_TIMEOUT + INDEXING_TIMEOUT;

const INDEXED = /\b([1-9]\d*) of \1 items are in search\./;
const INDEXING_FAILED =
	'Search may show outdated or missing content from this site. Re-index to try again.';

async function expectIndexingRun(
	site: OneSearchUtils,
	statuses: Record< string, 'completed' | 'failed' >,
	checkRecords: () => Promise< void >
) {
	// The queue runner can finish its jobs before saving the recorder at shutdown.
	await expect( async () => {
		const { jobs } = await site.requestUtils.rest< {
			jobs: { job: string; site: string; status: string }[];
		} >( { path: '/onesearch/v1/indexer' } );

		expect( jobs.filter( ( { job } ) => job === 'index_site' ) ).toEqual(
			expect.arrayContaining(
				Object.entries( statuses ).map( ( [ url, status ] ) =>
					expect.objectContaining( { site: url, status } )
				)
			)
		);
		await checkRecords();
	} ).toPass( { timeout: INDEXING_TIMEOUT } );
}

async function expectIndexedPost(
	site: OneSearchUtils,
	post: TestPost,
	siteUrl: string
) {
	expect( await site.indexedRecords() ).toContainEqual( {
		path: '/1/indexes/onesearch_localhost_wp_posts/batch',
		record: expect.objectContaining( {
			post_id: post.id,
			post_title: post.title,
			site_url: siteUrl,
			objectID: expect.any( String ),
		} ),
	} );
}

async function reIndex( page: Page ) {
	await page.getByRole( 'button', { name: 'Re-index' } ).click();
	const dialog = page.getByRole( 'dialog', {
		name: 'Re-index saved entities',
	} );
	await expect( dialog ).toContainText(
		'Re-index only indexes the entities you saved.'
	);
	await dialog.getByRole( 'button', { name: 'Re-index' } ).click();
	await expect( notices( page ) ).toContainText(
		"Indexing started. Each site shows how it's going below."
	);
}

test.describe( 'indices and search screen', () => {
	test( 'entities picked for both sites index each site’s own posts and survive a reload', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		test.setTimeout( ONE_RUN_TIMEOUT );
		const token = `onesearch${ Date.now() }`;
		const governingPost = await oneSearch.createPost(
			`Governing post ${ token }`
		);
		const brandPost = await brandSite.createPost( `Brand post ${ token }` );
		await connectSites( oneSearch, brandSite, { algolia: true } );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page.getByRole( 'group', {
			name: 'Indexable entities for Governing Site',
		} );
		const governingChips = governing.getByRole( 'button', {
			name: 'Select entities… for Governing Site',
		} );
		const brand = page.getByRole( 'group', {
			name: `Indexable entities for ${ BRAND_SITE.name }`,
		} );
		const brandChips = brand.getByRole( 'button', {
			name: `Select entities… for ${ BRAND_SITE.name }`,
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
				"Indexing started. Each site shows how it's going below."
			);
			await expectIndexingRun(
				oneSearch,
				{
					[ GOVERNING_SITE_URL ]: 'completed',
					[ BRAND_SITE_KEY ]: 'completed',
				},
				async () => {
					await expectIndexedPost(
						oneSearch,
						governingPost,
						GOVERNING_SITE_URL
					);
					await expectIndexedPost(
						brandSite,
						brandPost,
						BRAND_SITE_KEY
					);
				}
			);
		} );

		await admin.visitAdminPage( ADMIN_PAGE.indices );
		await expect(
			governing.getByRole( 'button', { name: 'Remove Posts' } )
		).toBeVisible();
		await expect(
			brand.getByRole( 'button', { name: 'Remove Posts' } )
		).toBeVisible();
	} );

	test( 'a brand site’s new API key stops only its own indexing until the governing site has it', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		test.setTimeout( TWO_RUNS_TIMEOUT );
		const token = `onesearch${ Date.now() }`;
		const governingPost = await oneSearch.createPost(
			`Governing post ${ token }`
		);
		const brandPost = await brandSite.createPost( `Brand post ${ token }` );
		await connectSites( oneSearch, brandSite, { algolia: true } );
		await oneSearch.updateSettings( {
			[ OPTION.indexableEntities ]: {
				entities: {
					[ GOVERNING_SITE_URL ]: [ 'post' ],
					[ BRAND_SITE_KEY ]: [ 'post' ],
				},
			},
		} );
		const newKey = await regenerateBrandApiKeyThroughUI( brandSite, page );
		await oneSearch.logIn( page );

		const governing = page.getByRole( 'group', {
			name: 'Indexable entities for Governing Site',
		} );
		const brand = page.getByRole( 'group', {
			name: `Indexable entities for ${ BRAND_SITE.name }`,
		} );
		const unreachable =
			"This site's status couldn't be loaded. Check that the site is reachable.";

		await test.step( 'the brand site’s row reports it, and only its indexing fails', async () => {
			await admin.visitAdminPage( ADMIN_PAGE.indices );
			await expect( brand ).toContainText( unreachable );
			await expect( governing ).not.toContainText( unreachable );

			await reIndex( page );
			await expectIndexingRun(
				oneSearch,
				{
					[ GOVERNING_SITE_URL ]: 'completed',
					[ BRAND_SITE_KEY ]: 'failed',
				},
				async () => {
					await expectIndexedPost(
						oneSearch,
						governingPost,
						GOVERNING_SITE_URL
					);
					expect(
						( await brandSite.indexedRecords() ).map(
							( { record } ) => record[ 'post_id' ]
						)
					).not.toContain( brandPost.id );
				}
			);
			await expect( brand ).toContainText( INDEXING_FAILED, {
				timeout: STATUS_TIMEOUT,
			} );
			await expect( governing ).toContainText( INDEXED, {
				timeout: STATUS_TIMEOUT,
			} );
			await expect( governing ).not.toContainText( INDEXING_FAILED );
		} );

		await test.step( 'saving the new key and re-indexing fixes it', async () => {
			await admin.visitAdminPage( ADMIN_PAGE.settings );
			await page
				.getByRole( 'row' )
				.filter( { hasText: BRAND_SITE.name } )
				.getByRole( 'button', { name: 'Edit' } )
				.click();
			const dialog = page.getByRole( 'dialog', {
				name: 'Edit Brand Site',
			} );
			await dialog
				.getByRole( 'textbox', { name: 'API Key' } )
				.fill( newKey );
			await dialog.getByRole( 'button', { name: 'Update Site' } ).click();
			await expect( notices( page ) ).toContainText(
				'Brand Site saved successfully.'
			);

			await admin.visitAdminPage( ADMIN_PAGE.indices );
			await expect( brand ).not.toContainText( unreachable );
			await reIndex( page );
			await expectIndexingRun(
				oneSearch,
				{
					[ GOVERNING_SITE_URL ]: 'completed',
					[ BRAND_SITE_KEY ]: 'completed',
				},
				() => expectIndexedPost( brandSite, brandPost, BRAND_SITE_KEY )
			);
			await expect( brand ).toContainText( INDEXED, {
				timeout: STATUS_TIMEOUT,
			} );
			await expect( brand ).not.toContainText( INDEXING_FAILED );
		} );
	} );
} );
