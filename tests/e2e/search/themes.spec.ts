/**
 * Internal dependencies
 */
import {
	BRAND_SITE_KEY,
	GOVERNING_SITE_URL,
	OPTION,
	connectSites,
	expect,
	searchHit,
	searchResult,
	test,
} from '../_support';

test.describe( 'search results in themes', () => {
	test( 'the Search block shows Algolia results in a block theme and a classic theme', async ( {
		brandSite,
		oneSearch,
		page,
		requestUtils,
	} ) => {
		const token = `onesearch${ Date.now() }`;
		const brandPost = await brandSite.createPost(
			`Brand result ${ token }`
		);
		const searchPost = await oneSearch.createPost(
			`Search ${ token }`,
			'<!-- wp:search {"label":"Search","buttonText":"Search"} /-->'
		);
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
		await oneSearch.setAlgoliaSearchHits( [
			searchHit( brandPost, BRAND_SITE_KEY ),
		] );

		try {
			for ( const theme of [ 'twentytwentyfive', 'twentytwentyone' ] ) {
				await test.step( theme, async () => {
					await requestUtils.activateTheme( theme );
					await page.goto( searchPost.link );

					const main = page.getByRole( 'main' );
					await main
						.getByRole( 'searchbox', { name: 'Search' } )
						.fill( token );
					await main
						.getByRole( 'button', { name: 'Search' } )
						.click();

					// Only Algolia can return the brand site's post to a search on the governing site.
					await expect(
						searchResult( page, brandPost.title )
					).toHaveAttribute( 'href', brandPost.link );
				} );
			}
		} finally {
			await requestUtils.activateTheme( 'twentytwentyfive' );
		}
	} );
} );
