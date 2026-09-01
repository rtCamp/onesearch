/**
 * External dependencies
 */
import type { Locator } from '@playwright/test';

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

/**
 * The Algolia switch inside a site's card.
 *
 * A card holds a searchable-sites checkbox too, so the switch is addressed by
 * name rather than by being the only one present.
 *
 * @param scope    The site's group.
 * @param siteName The site's name.
 */
function algoliaToggle( scope: Locator, siteName: string ): Locator {
	return scope.getByRole( 'checkbox', {
		name: `Enable Algolia search for ${ siteName }`,
	} );
}

test.describe( 'site search configuration', () => {
	test.beforeEach( async ( { brandSite, oneSearch } ) => {
		await connectSites( oneSearch, brandSite, { algolia: true } );
	} );

	test( 'will not enable Algolia for a site with nothing to index', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ADMIN_PAGE.indices );

		const governing = page.getByRole( 'group', {
			name: 'Search settings for Governing Site',
		} );

		await expect(
			governing.getByText(
				'Please select entities for indexing to enable Algolia search'
			)
		).toBeVisible();
		await expect(
			algoliaToggle( governing, 'Governing Site' )
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

		const governing = page.getByRole( 'group', {
			name: 'Search settings for Governing Site',
		} );

		await expect(
			governing.getByText( 'Using default WordPress search' )
		).toBeVisible();

		await algoliaToggle( governing, 'Governing Site' ).check();

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

		const governing = page.getByRole( 'group', {
			name: 'Search settings for Governing Site',
		} );

		await expect(
			algoliaToggle( governing, 'Governing Site' )
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

		const toggles = page.getByRole( 'checkbox', {
			name: /^Enable Algolia search for /,
		} );
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

		const governing = page.getByRole( 'group', {
			name: 'Search settings for Governing Site',
		} );
		const self = governing.locator(
			'.onesearch-searchable-item.onesearch-current-site input'
		);

		await expect( self ).toBeChecked();
		await expect( self ).toBeDisabled();
	} );
} );
