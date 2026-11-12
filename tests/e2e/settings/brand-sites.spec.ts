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
	type OneSearchUtils,
} from '../_support';

const BRAND_SETTINGS = `${ BRAND_SITE_URL }/wp-admin/${ ADMIN_PAGE.settings }`;

// Records are deleted by a background job, which can wait up to a minute for WP-Cron.
const RECORD_DELETION_TIMEOUT = 70_000;

/**
 * Connect the pair with Algolia search on for both sites, and have the brand site's search return the governing site's post.
 *
 * @param governing The governing site.
 * @param brand     The brand site.
 */
async function connectWithSearch(
	governing: OneSearchUtils,
	brand: OneSearchUtils
) {
	const token = `onesearch${ Date.now() }`;
	const governingPost = await governing.createPost(
		`Governing result ${ token }`
	);
	const brandPost = await brand.createPost( `Brand result ${ token }` );
	await connectSites( governing, brand, { algolia: true } );
	await governing.updateSettings( {
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
	await brand.setAlgoliaSearchHits( [
		searchHit( governingPost, GOVERNING_SITE_URL ),
	] );

	return {
		brandSearch: `${ BRAND_SITE_URL }/?s=${ token }`,
		governingPost,
		brandPost,
	};
}

async function deletedRecordFilters( site: OneSearchUtils ) {
	return ( await site.algoliaRequests() )
		.filter( ( { path } ) => path.endsWith( '/deleteByQuery' ) )
		.map( ( { body } ) => body.filters );
}

test.describe( 'brand sites', () => {
	test( 'adding a brand site connects both sites without reloading the page', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setSiteType( SITE_TYPE.governing );
		// Indices and Search stays behind its setup dialog without credentials.
		await oneSearch.saveAlgoliaCredentials();
		await brandSite.setSiteType( SITE_TYPE.brand );
		const apiKey = await brandSite.regenerateApiKey();

		await admin.visitAdminPage( ADMIN_PAGE.indices );
		await expect(
			page.getByRole( 'heading', { name: 'Setup Required' } )
		).toBeVisible();

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		let loads = 0;
		page.on( 'load', () => loads++ );
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
		await expect(
			page.getByRole( 'row' ).filter( { hasText: BRAND_SITE.name } )
		).toContainText( BRAND_SITE.url );
		expect( loads ).toBe( 0 );

		await admin.visitAdminPage( ADMIN_PAGE.indices );
		await expect(
			page.getByRole( 'heading', { name: 'Setup Required' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'group', {
				name: `Indexable entities for ${ BRAND_SITE.name }`,
			} )
		).toBeVisible();

		// The health check on save is what tells the brand site who governs it.
		await brandSite.logIn( page );
		await page.goto( BRAND_SETTINGS );
		await expect(
			page.getByRole( 'textbox', { name: 'Governing Site URL' } )
		).toHaveValue( GOVERNING_SITE_ORIGIN );
		await expect(
			page.getByRole( 'button', { name: 'Disconnect Governing Site' } )
		).toBeEnabled();
	} );

	test( 'removing a brand site disconnects both sites and drops its search configuration', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		const { brandSearch, governingPost, brandPost } =
			await connectWithSearch( oneSearch, brandSite );
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
		await expect( notices( page ) ).toContainText(
			'Brand site removed successfully.'
		);
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
			.poll( () => deletedRecordFilters( oneSearch ), {
				timeout: RECORD_DELETION_TIMEOUT,
			} )
			.toContain( `site_url:"${ BRAND_SITE_KEY }"` );

		// With its only brand site gone, the screen is back behind its setup dialog.
		await admin.visitAdminPage( ADMIN_PAGE.indices );
		await expect(
			page.getByRole( 'heading', { name: 'Setup Required' } )
		).toBeVisible();
		// The dialog hides the screen from assistive tech, so look past it, with the governing site's row as the control.
		await expect(
			page.getByRole( 'group', {
				name: 'Indexable entities for Governing Site',
				includeHidden: true,
			} )
		).toHaveCount( 1 );
		for ( const name of [
			`Indexable entities for ${ BRAND_SITE.name }`,
			`Search settings for ${ BRAND_SITE.name }`,
		] ) {
			await expect(
				page.getByRole( 'group', { name, includeHidden: true } )
			).toHaveCount( 0 );
		}

		await page.goto( brandSearch );
		await expect( searchResult( page, brandPost.title ) ).toBeVisible();
		await expect( governingResult ).toHaveCount( 0 );
	} );

	test( 'disconnecting on the brand site removes it from the governing site and its search', async ( {
		admin,
		brandSite,
		oneSearch,
		page,
	} ) => {
		const { brandSearch, governingPost, brandPost } =
			await connectWithSearch( oneSearch, brandSite );
		const governingResult = searchResult( page, governingPost.title );

		await page.goto( brandSearch );
		await expect( governingResult ).toBeVisible();

		await brandSite.logIn( page );
		await page.goto( BRAND_SETTINGS );
		await page
			.getByRole( 'button', { name: 'Disconnect Governing Site' } )
			.click();
		await page
			.getByRole( 'dialog', { name: 'Disconnect Governing Site' } )
			.getByRole( 'button', { name: 'Disconnect', exact: true } )
			.click();

		await expect( notices( page ) ).toContainText(
			'Governing site disconnected successfully.'
		);
		await expect(
			page.getByRole( 'textbox', { name: 'Governing Site URL' } )
		).toHaveValue( '' );

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await expect(
			page.getByRole( 'row' ).filter( { hasText: BRAND_SITE.name } )
		).toHaveCount( 0 );
		await expect
			.poll( () => deletedRecordFilters( oneSearch ), {
				timeout: RECORD_DELETION_TIMEOUT,
			} )
			.toContain( `site_url:"${ BRAND_SITE_KEY }"` );

		await page.goto( brandSearch );
		await expect( searchResult( page, brandPost.title ) ).toBeVisible();
		await expect( governingResult ).toHaveCount( 0 );
	} );
} );
