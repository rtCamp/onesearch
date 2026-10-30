/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	BRAND_SITE,
	BRAND_SITE_KEY,
	BRAND_SITE_URL,
	GOVERNING_SITE_ORIGIN,
	GOVERNING_SITE_URL,
	OPTION,
	SITE_TYPE,
	connectSites,
	expect,
	notices,
	searchHit,
	searchResult,
	test,
} from '../_support';

test.describe( 'brand sites', () => {
	test( 'adding a brand site connects both sites and survives a reload', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setSiteType( SITE_TYPE.governing );
		await brandSite.setSiteType( SITE_TYPE.brand );
		const apiKey = await brandSite.regenerateApiKey();

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await page.getByRole( 'button', { name: 'Add Brand Site' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Add Brand Site' } );
		await dialog
			.getByRole( 'textbox', { name: 'Site Name*' } )
			.fill( BRAND_SITE.name );
		await dialog
			.getByRole( 'textbox', { name: 'Site URL*' } )
			.fill( BRAND_SITE.url );
		await dialog
			.getByRole( 'textbox', { name: 'API Key*' } )
			.fill( apiKey );
		await dialog.getByRole( 'button', { name: 'Add Site' } ).click();

		await expect( notices( page ) ).toContainText(
			'Brand Site saved successfully.'
		);

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await expect(
			page.getByRole( 'row' ).filter( { hasText: BRAND_SITE.name } )
		).toContainText( BRAND_SITE.url );

		// The health check on save is what tells the brand site who governs it.
		await expect
			.poll(
				async () =>
					( await brandSite.getSettings() )[ OPTION.parentSiteUrl ]
			)
			.toBe( GOVERNING_SITE_ORIGIN );
	} );

	test( 'removing a brand site disconnects both sites and drops its search configuration', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		const token = `onesearch${ Date.now() }`;
		const governingPost = await oneSearch.createPost(
			`Governing result ${ token }`
		);
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
			[ OPTION.searchSettings ]: {
				[ GOVERNING_SITE_URL ]: {
					algolia_enabled: true,
					searchable_sites: [ GOVERNING_SITE_URL, BRAND_SITE_KEY ],
				},
				[ BRAND_SITE_KEY ]: {
					algolia_enabled: true,
					searchable_sites: [ BRAND_SITE_KEY, GOVERNING_SITE_URL ],
				},
			},
		} );
		await brandSite.setAlgoliaSearchHits( [
			searchHit( governingPost, GOVERNING_SITE_URL ),
		] );

		const brandSearch = `${ BRAND_SITE_URL }/?s=${ token }`;
		const governingResult = searchResult( page, governingPost.title );

		// Searching caches the governing site's configuration on the brand site.
		await page.goto( brandSearch );
		await expect( governingResult ).toBeVisible();

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		const row = page
			.getByRole( 'row' )
			.filter( { hasText: BRAND_SITE.name } );
		await row.getByRole( 'button', { name: 'Delete' } ).click();
		await page
			.getByRole( 'dialog', { name: 'Delete Brand Site' } )
			.getByRole( 'button', { name: 'Delete' } )
			.click();
		await expect( row ).toHaveCount( 0 );

		await expect
			.poll(
				async () =>
					( await brandSite.getSettings() )[ OPTION.parentSiteUrl ]
			)
			.toBeFalsy();

		const governingSettings = await oneSearch.getSettings();
		expect( governingSettings[ OPTION.searchSettings ] ).toEqual( {
			[ GOVERNING_SITE_URL ]: {
				algolia_enabled: true,
				searchable_sites: [ GOVERNING_SITE_URL ],
			},
		} );
		expect( governingSettings[ OPTION.indexableEntities ] ).toEqual( {
			entities: { [ GOVERNING_SITE_URL ]: [ 'post' ] },
		} );
		await expect
			.poll( async () =>
				( await oneSearch.algoliaRequests() )
					.filter( ( { path } ) => path.endsWith( '/deleteByQuery' ) )
					.map( ( { body } ) => body.filters )
			)
			.toEqual( [ `site_url:"${ BRAND_SITE_KEY }"` ] );

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await expect( row ).toHaveCount( 0 );

		await page.goto( brandSearch );
		await expect( searchResult( page, brandPost.title ) ).toBeVisible();
		await expect( governingResult ).toHaveCount( 0 );
	} );
} );
