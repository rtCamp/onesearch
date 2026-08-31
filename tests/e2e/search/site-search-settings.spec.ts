/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	BRAND_SITE_KEY,
	GOVERNING_SITE_URL,
	OPTION,
	expect,
	connectSites,
	notices,
	test,
} from '../scaffold';

test.describe( 'site search configuration', () => {
	test.beforeEach( async ( { brandSite, oneSearch } ) => {
		await connectSites( oneSearch, brandSite, { algolia: true } );
	} );

	test( 'will not enable Algolia for a site with nothing to index', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page
			.locator( '.onesearch-site-card' )
			.filter( { hasText: 'Governing Site' } );

		await expect(
			governing.getByText(
				'Please select entities for indexing to enable Algolia search'
			)
		).toBeVisible();
		await expect(
			governing.locator( '.onesearch-site-toggle input' )
		).toBeDisabled();
		await expect(
			page.getByRole( 'button', { name: 'Enable All' } )
		).toBeDisabled();
	} );

	test( 'enables Algolia for a site that has entities and saves it', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setState( {
			options: {
				[ OPTION.indexableEntities ]: {
					entities: { [ GOVERNING_SITE_URL ]: [ 'post' ] },
				},
			},
		} );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page
			.locator( '.onesearch-site-card' )
			.filter( { hasText: 'Governing Site' } );

		await expect(
			governing.getByText( 'Using default WordPress search' )
		).toBeVisible();

		await governing.locator( '.onesearch-site-toggle input' ).check();

		await expect(
			governing.getByText( 'Algolia search enabled' )
		).toBeVisible();
		await expect(
			governing.getByRole( 'heading', { name: 'Search from:' } )
		).toBeVisible();
		await expect(
			governing.getByText( '(Current Site - Always Included)' )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Save Settings' } ).click();

		await expect( notices( page ) ).toContainText(
			'Search settings saved successfully.'
		);

		expect(
			( await oneSearch.getState() ).options[ OPTION.searchSettings ]
		).toEqual( {
			[ GOVERNING_SITE_URL ]: {
				algolia_enabled: true,
				searchable_sites: [ GOVERNING_SITE_URL ],
			},
		} );
	} );

	test( 'restores a saved configuration on reload', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setState( {
			options: {
				[ OPTION.indexableEntities ]: {
					entities: { [ GOVERNING_SITE_URL ]: [ 'post' ] },
				},
				[ OPTION.searchSettings ]: {
					[ GOVERNING_SITE_URL ]: {
						algolia_enabled: true,
						searchable_sites: [ GOVERNING_SITE_URL ],
					},
				},
			},
		} );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page
			.locator( '.onesearch-site-card' )
			.filter( { hasText: 'Governing Site' } );

		await expect(
			governing.locator( '.onesearch-site-toggle input' )
		).toBeChecked();
		await expect(
			page.getByRole( 'button', { name: 'Save Settings' } )
		).toBeDisabled();
	} );

	test( 'enables and disables every eligible site at once', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setState( {
			options: {
				[ OPTION.indexableEntities ]: {
					entities: {
						[ GOVERNING_SITE_URL ]: [ 'post' ],
						[ BRAND_SITE_KEY ]: [ 'post' ],
					},
				},
			},
		} );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const toggles = page.locator( '.onesearch-site-toggle input' );
		await expect( toggles ).toHaveCount( 2 );

		await page.getByRole( 'button', { name: 'Enable All' } ).click();
		await expect( page.getByText( 'Algolia search enabled' ) ).toHaveCount(
			2
		);

		await page.getByRole( 'button', { name: 'Disable All' } ).click();
		await expect(
			page.getByText( 'Using default WordPress search' )
		).toHaveCount( 2 );
	} );

	test( 'keeps the current site in its own search scope', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setState( {
			options: {
				[ OPTION.indexableEntities ]: {
					entities: { [ GOVERNING_SITE_URL ]: [ 'post' ] },
				},
				[ OPTION.searchSettings ]: {
					[ GOVERNING_SITE_URL ]: {
						algolia_enabled: true,
						searchable_sites: [ GOVERNING_SITE_URL ],
					},
				},
			},
		} );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page
			.locator( '.onesearch-site-card' )
			.filter( { hasText: 'Governing Site' } );
		const self = governing.locator(
			'.onesearch-searchable-item.onesearch-current-site input'
		);

		await expect( self ).toBeChecked();
		await expect( self ).toBeDisabled();
	} );
} );
