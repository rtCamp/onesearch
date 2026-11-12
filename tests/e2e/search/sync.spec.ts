/**
 * Internal dependencies
 */
import {
	BRAND_SITE_KEY,
	GOVERNING_SITE_URL,
	OPTION,
	connectSites,
	expect,
	searchResult,
	test,
} from '../_support';

// The sync runs in a background job, which can wait up to a minute for WP-Cron.
const SYNC_TIMEOUT = 70_000;

const INDEX_PATH = '/1/indexes/onesearch_localhost_wp_posts';

test.describe( 'content sync', () => {
	test( 'an updated brand post shows in the governing site’s search once it syncs', async ( {
		brandSite,
		oneSearch,
		page,
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
			[ OPTION.searchSettings ]: {
				[ GOVERNING_SITE_URL ]: {
					algolia_enabled: true,
					searchable_sites: [ GOVERNING_SITE_URL, BRAND_SITE_KEY ],
				},
			},
		} );

		const updatedTitle = `Updated brand post ${ token }`;
		await brandSite.requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ post.id }`,
			data: { title: updatedTitle },
		} );

		let synced: Record< string, unknown > | undefined;
		await expect( async () => {
			synced = ( await brandSite.indexedRecords() ).find(
				( { path, record } ) =>
					path === `${ INDEX_PATH }/batch` &&
					record[ 'post_id' ] === post.id &&
					record[ 'post_title' ] === updatedTitle
			)?.record;
			expect( synced ).toMatchObject( { site_url: BRAND_SITE_KEY } );
		} ).toPass( { timeout: SYNC_TIMEOUT } );

		// The stub answers with the record the brand site sent, as the shared index would.
		await oneSearch.setAlgoliaSearchHits( [
			synced as Record< string, unknown >,
		] );
		await page.goto( `/?s=${ token }` );

		await expect( searchResult( page, updatedTitle ) ).toHaveAttribute(
			'href',
			post.link
		);
		const governingSearch = ( await oneSearch.algoliaRequests() )
			.filter( ( { path } ) => path.endsWith( '/query' ) )
			.at( -1 );
		expect( governingSearch?.path ).toBe( `${ INDEX_PATH }/query` );
		expect( governingSearch?.body.filters ).toContain(
			`site_url:"${ BRAND_SITE_KEY }"`
		);
	} );
} );
