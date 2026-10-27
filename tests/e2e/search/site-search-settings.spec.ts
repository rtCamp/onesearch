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
} from '../scaffold';

test.describe( 'site search settings', () => {
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
				.poll( async () =>
					( await brandSite.algoliaRequests() )
						.filter( ( { path } ) => path.endsWith( '/query' ) )
						.map( ( { body } ) =>
							body.filters?.split( /\s+OR\s+/ ).sort()
						)
				)
				.toContainEqual(
					[
						`site_url:"${ BRAND_SITE_KEY }"`,
						`site_url:"${ GOVERNING_SITE_URL }"`,
					].sort()
				);

			await governingResult.click();
			await expect( page ).toHaveURL( governingPost.link );
			await expect(
				page.getByRole( 'heading', {
					level: 1,
					name: governingPost.title,
				} )
			).toBeVisible();
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
