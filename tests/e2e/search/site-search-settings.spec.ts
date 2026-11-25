/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	BRAND_SITE,
	BRAND_SITE_KEY,
	BRAND_SITE_URL,
	GOVERNING_SITE_URL,
	OPTION,
	connectSites,
	expect,
	notices,
	searchHit,
	searchResult,
	test,
	type OneSearchUtils,
} from '../_support';

// The sites each of this site's searches asked Algolia for, oldest first.
async function searchedSites( site: OneSearchUtils ) {
	return ( await site.algoliaRequests() )
		.filter( ( { path } ) => path.endsWith( '/query' ) )
		.map( ( { body } ) => body.filters?.split( /\s+OR\s+/ ).sort() );
}

test.describe( 'site search settings', () => {
	test( 'a governing site searches only the sites selected in Search from', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		const token = `onesearch${ Date.now() }`;
		const brandPost = await brandSite.createPost(
			`Brand result ${ token }`
		);
		await connectSites( oneSearch, brandSite, { algolia: true } );
		await oneSearch.updateSettings( {
			[ OPTION.indexableEntities ]: {
				entities: {
					[ GOVERNING_SITE_URL ]: [ 'post' ],
					[ BRAND_SITE_KEY ]: [ 'post' ],
				},
			},
		} );
		await oneSearch.setAlgoliaSearchHits( [
			searchHit( brandPost, BRAND_SITE_KEY ),
		] );

		const governingSettings = page.getByRole( 'group', {
			name: 'Search settings for Governing Site',
		} );
		const algoliaToggle = governingSettings.getByRole( 'checkbox', {
			name: 'Enable Algolia search for Governing Site',
		} );
		const brandSource = governingSettings.getByRole( 'checkbox', {
			name: BRAND_SITE.name,
		} );
		const governingSearch = `/?s=${ token }`;

		await test.step( 'the governing site searches the selected brand site', async () => {
			await admin.visitAdminPage( ADMIN_PAGE.indices );
			await algoliaToggle.check();
			await brandSource.check();
			await page.getByRole( 'button', { name: 'Save Settings' } ).click();
			await expect( notices( page ) ).toContainText(
				'Search settings saved successfully.'
			);

			await admin.visitAdminPage( ADMIN_PAGE.indices );
			await expect( algoliaToggle ).toBeChecked();
			await expect( brandSource ).toBeChecked();

			await page.goto( governingSearch );
			await expect(
				searchResult( page, brandPost.title )
			).toHaveAttribute( 'href', brandPost.link );
			await expect
				.poll( () => searchedSites( oneSearch ) )
				.toContainEqual(
					[
						`site_url:"${ BRAND_SITE_KEY }"`,
						`site_url:"${ GOVERNING_SITE_URL }"`,
					].sort()
				);
		} );

		await test.step( 'removing the brand site excludes it from the next governing-site search', async () => {
			await admin.visitAdminPage( ADMIN_PAGE.indices );
			await brandSource.uncheck();
			await page.getByRole( 'button', { name: 'Save Settings' } ).click();
			await expect( notices( page ) ).toContainText(
				'Search settings saved successfully.'
			);

			await expect( async () => {
				await page.goto( governingSearch );
				expect( ( await searchedSites( oneSearch ) ).at( -1 ) ).toEqual(
					[ `site_url:"${ GOVERNING_SITE_URL }"` ]
				);
			} ).toPass( { timeout: 10_000 } );
		} );
	} );

	test( 'a brand site searches the sites picked for it, and falls back to WordPress search once Algolia is off', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		const token = `onesearch${ Date.now() }`;
		const remoteContent = `Remote content ${ token }.`;
		const governingPost = await oneSearch.createPost(
			`Governing result ${ token }`,
			remoteContent
		);
		const brandPost = await brandSite.createPost(
			`Brand result ${ token }`
		);
		await connectSites( oneSearch, brandSite, { algolia: true } );
		// Algolia can only be enabled for a site with something to index.
		await oneSearch.updateSettings( {
			[ OPTION.indexableEntities ]: {
				entities: {
					[ GOVERNING_SITE_URL ]: [ 'post' ],
					[ BRAND_SITE_KEY ]: [ 'post' ],
				},
			},
		} );
		await brandSite.setAlgoliaSearchHits( [
			searchHit( governingPost, GOVERNING_SITE_URL ),
		] );

		const brandSettings = page.getByRole( 'group', {
			name: `Search settings for ${ BRAND_SITE.name }`,
		} );
		const toggle = brandSettings.getByRole( 'checkbox', {
			name: `Enable Algolia search for ${ BRAND_SITE.name }`,
		} );
		const governingResult = searchResult( page, governingPost.title );
		const brandResult = searchResult( page, brandPost.title );
		const brandSearch = `${ BRAND_SITE_URL }/?s=${ token }`;

		await test.step( 'search both sites from the brand site', async () => {
			await admin.visitAdminPage( ADMIN_PAGE.indices );
			await toggle.check();
			await brandSettings
				.getByRole( 'checkbox', { name: 'Governing Site' } )
				.check();
			await page.getByRole( 'button', { name: 'Save Settings' } ).click();

			await expect( notices( page ) ).toContainText(
				'Search settings saved successfully.'
			);

			await admin.visitAdminPage( ADMIN_PAGE.indices );
			await expect( toggle ).toBeChecked();
		} );

		await test.step( 'the brand site shows and links to the governing site’s result', async () => {
			await page.goto( brandSearch );
			await expect( governingResult ).toHaveAttribute(
				'href',
				governingPost.link
			);
			await expect( brandResult ).toHaveCount( 0 );
			await expect(
				page.getByText( remoteContent, {
					exact: false,
				} )
			).toBeVisible();

			// The brand site asked Algolia for the sites the governing site picked.
			await expect
				.poll( () => searchedSites( brandSite ) )
				.toContainEqual(
					[
						`site_url:"${ BRAND_SITE_KEY }"`,
						`site_url:"${ GOVERNING_SITE_URL }"`,
					].sort()
				);
		} );

		await test.step( 'a site taken out of Search from is left out of the brand site’s next search', async () => {
			await admin.visitAdminPage( ADMIN_PAGE.indices );
			await brandSettings
				.getByRole( 'checkbox', { name: 'Governing Site' } )
				.uncheck();
			await page.getByRole( 'button', { name: 'Save Settings' } ).click();
			await expect( notices( page ) ).toContainText(
				'Search settings saved successfully.'
			);

			await expect( async () => {
				await page.goto( brandSearch );
				expect( ( await searchedSites( brandSite ) ).at( -1 ) ).toEqual(
					[ `site_url:"${ BRAND_SITE_KEY }"` ]
				);
			} ).toPass( { timeout: 10_000 } );
		} );

		await test.step( 'turning Algolia off reaches the brand site’s cached configuration', async () => {
			await admin.visitAdminPage( ADMIN_PAGE.indices );
			await toggle.uncheck();
			await page.getByRole( 'button', { name: 'Save Settings' } ).click();
			await expect( notices( page ) ).toContainText(
				'Search settings saved successfully.'
			);

			// The governing site tells the brand site to drop its cache without waiting for it.
			await expect( async () => {
				await page.goto( brandSearch );
				await expect( brandResult ).toBeVisible( { timeout: 1_000 } );
			} ).toPass( { timeout: 10_000 } );
			await expect( governingResult ).toHaveCount( 0 );
		} );
	} );
} );
