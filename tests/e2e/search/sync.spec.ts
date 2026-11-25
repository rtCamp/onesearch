/**
 * Internal dependencies
 */
import {
	BRAND_SITE_KEY,
	GOVERNING_SITE_URL,
	OPTION,
	connectSites,
	expect,
	test,
} from '../_support';

// The sync runs in a background job, which can wait up to a minute for WP-Cron.
const SYNC_TIMEOUT = 70_000;

test.describe( 'content sync', () => {
	test( 'an updated brand post syncs with the entities the governing site picked for it', async ( {
		brandSite,
		oneSearch,
	} ) => {
		const token = `onesearch${ Date.now() }`;
		const post = await brandSite.createPost( `Brand post ${ token }` );
		await connectSites( oneSearch, brandSite, { algolia: true } );
		await oneSearch.updateSettings( {
			[ OPTION.indexableEntities ]: {
				entities: {
					[ GOVERNING_SITE_URL ]: [ 'post' ],
					[ BRAND_SITE_KEY ]: [ 'post' ],
				},
			},
		} );

		const updatedTitle = `Updated brand post ${ token }`;
		await brandSite.requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ post.id }`,
			data: { title: updatedTitle },
		} );

		await expect( async () => {
			expect( await brandSite.indexedRecords() ).toContainEqual( {
				path: '/1/indexes/onesearch_localhost_wp_posts/batch',
				record: expect.objectContaining( {
					post_id: post.id,
					post_title: updatedTitle,
					site_url: BRAND_SITE_KEY,
				} ),
			} );
		} ).toPass( { timeout: SYNC_TIMEOUT } );
	} );
} );
