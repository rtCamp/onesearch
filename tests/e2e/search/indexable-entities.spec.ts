/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	BRAND_POST_TYPES,
	BRAND_SITE,
	GOVERNING_SITE_URL,
	OPTION,
	expect,
	connectSites,
	notices,
	test,
} from '../scaffold';

test.describe( 'indexable entities', () => {
	test.beforeEach( async ( { brandSite, oneSearch } ) => {
		await connectSites( oneSearch, brandSite, { algolia: true } );
		// Re-indexing reaches Algolia, which CI cannot talk to.
		await oneSearch.stubReindex();
	} );

	test( 'lists the entities of the governing site and every brand site', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page
			.locator( '.onesearch-entity-site' )
			.filter( { hasText: 'Governing Site' } );
		await expect(
			governing.locator( '.onesearch-entity-site-url' )
		).toHaveText( GOVERNING_SITE_URL );

		const brand = page
			.locator( '.onesearch-entity-site' )
			.filter( { hasText: BRAND_SITE.name } );
		await expect( brand ).toBeVisible();

		// Every public post type the brand site actually reports, Media included.
		// Asserting the whole set is what proves the list came over the wire
		// rather than from an assumption about what a brand site holds.
		await brand.locator( '.msc-control' ).click();
		const menu = page.locator( '.msc-menu' );

		for ( const postType of BRAND_POST_TYPES ) {
			await expect(
				menu.getByRole( 'checkbox', { name: postType.label } )
			).toBeVisible();
		}

		await expect( menu.getByRole( 'checkbox' ) ).toHaveCount(
			BRAND_POST_TYPES.length
		);
	} );

	test( 'saves a selection, re-indexes, and keeps it after a reload', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page
			.locator( '.onesearch-entity-site' )
			.filter( { hasText: 'Governing Site' } );
		const chips = governing.locator( '.msc-control' );

		await chips.click();
		await page
			.locator( '.msc-menu' )
			.getByRole( 'checkbox', { name: 'Posts', exact: true } )
			.check();
		await chips.click();

		await expect( governing.locator( '.msc-chip-label' ) ).toHaveText(
			'Posts'
		);

		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		// Saving triggers a re-index of what was just saved.
		await expect( notices( page ) ).toContainText(
			'Re-indexing complete.'
		);

		expect(
			( await oneSearch.getState() ).options[ OPTION.indexableEntities ]
		).toEqual( {
			entities: { [ GOVERNING_SITE_URL ]: [ 'post' ] },
		} );

		await admin.visitAdminPage( ADMIN_PAGE.indices );
		await expect(
			page
				.locator( '.onesearch-entity-site' )
				.filter( { hasText: 'Governing Site' } )
				.locator( '.msc-chip-label' )
		).toHaveText( 'Posts' );
	} );

	test( 'cannot re-index before anything has been saved', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		await expect(
			page.getByRole( 'button', { name: 'Re-index', exact: true } )
		).toBeDisabled();
		await expect(
			page.getByRole( 'button', { name: 'Save Changes' } )
		).toBeDisabled();
	} );

	test( 're-indexes saved entities behind a confirmation', async ( {
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

		await page
			.getByRole( 'button', { name: 'Re-index', exact: true } )
			.click();

		const dialog = page.getByRole( 'dialog', {
			name: 'Re-index saved entities',
		} );
		await expect( dialog ).toBeVisible();

		await dialog
			.getByRole( 'button', { name: 'Re-index', exact: true } )
			.click();

		await expect( notices( page ) ).toContainText(
			'Re-indexing complete.'
		);
	} );

	test( 'reports a failed re-index', async ( { admin, oneSearch, page } ) => {
		await oneSearch.setState( {
			options: {
				[ OPTION.indexableEntities ]: {
					entities: { [ GOVERNING_SITE_URL ]: [ 'post' ] },
				},
			},
		} );
		await oneSearch.stubReindex( false, 'Re-index failed.' );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		await page
			.getByRole( 'button', { name: 'Re-index', exact: true } )
			.click();
		await page
			.getByRole( 'dialog', { name: 'Re-index saved entities' } )
			.getByRole( 'button', { name: 'Re-index', exact: true } )
			.click();

		await expect( notices( page ) ).toContainText( 'Re-index failed.' );
	} );
} );
