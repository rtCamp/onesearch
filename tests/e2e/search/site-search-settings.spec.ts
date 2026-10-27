/**
 * External dependencies
 */
import type { Locator } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	GOVERNING_SITE_URL,
	OPTION,
	expect,
	connectSites,
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
} );
