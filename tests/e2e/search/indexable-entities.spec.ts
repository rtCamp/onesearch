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
	} );

	test( 'lists the entities of the governing site and every brand site', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page.getByRole( 'group', {
			name: 'Indexable entities for Governing Site',
		} );
		await expect( governing ).toContainText( GOVERNING_SITE_URL );

		const brand = page.getByRole( 'group', {
			name: `Indexable entities for ${ BRAND_SITE.name }`,
		} );
		await expect( brand ).toBeVisible();

		// Asserting the full set, Media included, proves the list came over the wire rather than from an assumption.
		await brand
			.getByRole( 'button', {
				name: `Entities to index for ${ BRAND_SITE.name }`,
			} )
			.click();
		const menu = page.getByRole( 'listbox' );

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

		const governing = page.getByRole( 'group', {
			name: 'Indexable entities for Governing Site',
		} );
		const chips = governing.getByRole( 'button', {
			name: 'Entities to index for Governing Site',
		} );

		await chips.click();
		await page
			.getByRole( 'listbox' )
			.getByRole( 'checkbox', { name: 'Posts', exact: true } )
			.check();
		await chips.click();

		await expect( chips ).toContainText( 'Posts' );

		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		// Saving triggers a re-index of what was just saved.
		await expect( notices( page ) ).toContainText(
			'Re-indexing scheduled successfully.'
		);

		expect(
			( await oneSearch.getState() ).options[ OPTION.indexableEntities ]
		).toEqual( {
			entities: { [ GOVERNING_SITE_URL ]: [ 'post' ] },
		} );

		await admin.visitAdminPage( ADMIN_PAGE.indices );
		await expect(
			page
				.getByRole( 'group', {
					name: 'Indexable entities for Governing Site',
				} )
				.getByRole( 'button', {
					name: 'Entities to index for Governing Site',
				} )
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
			'Re-indexing scheduled successfully.'
		);
	} );

	test( 'reports a re-index that Algolia rejects', async ( {
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
		// Algolia answers 500, so the endpoint's own failure path runs.
		await oneSearch.setAlgoliaMode( 'server_error' );
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		await page
			.getByRole( 'button', { name: 'Re-index', exact: true } )
			.click();
		await page
			.getByRole( 'dialog', { name: 'Re-index saved entities' } )
			.getByRole( 'button', { name: 'Re-index', exact: true } )
			.click();

		await expect( notices( page ) ).toContainText(
			'Re-indexing was unsuccessful. Please try again later.'
		);
	} );
} );
