/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	ADMIN_PAGE,
	GOVERNING_SITE_URL,
	OPTION,
	SITE_TYPE,
	expect,
	test,
} from '../_support';

function pluginLink( page: Page, action: 'Activate' | 'Deactivate' ) {
	return page.getByRole( 'link', {
		name: `${ action } OneSearch`,
		exact: true,
	} );
}

test.describe( 'plugin activation', () => {
	test( 'deactivates and reactivates without errors and keeps a configured site’s settings', async ( {
		admin,
		oneSearch,
		page,
		requestUtils,
	} ) => {
		const uncaught: string[] = [];
		page.on( 'pageerror', ( error ) => uncaught.push( error.message ) );

		const options = [
			OPTION.siteType,
			OPTION.indexableEntities,
			OPTION.searchSettings,
		];
		await oneSearch.setSiteType( SITE_TYPE.governing );
		await oneSearch.updateSettings( {
			[ OPTION.indexableEntities ]: {
				entities: { [ GOVERNING_SITE_URL ]: [ 'post' ] },
			},
			[ OPTION.searchSettings ]: {
				[ GOVERNING_SITE_URL ]: {
					algolia_enabled: true,
					searchable_sites: [ GOVERNING_SITE_URL ],
				},
			},
		} );
		const configured = await oneSearch.getSettings();
		expect( configured[ OPTION.siteType ] ).toBe( SITE_TYPE.governing );
		expect( configured[ OPTION.searchSettings ] ).toBeTruthy();

		// Leave the plugin active for subsequent tests, even on failure.
		try {
			await admin.visitAdminPage( ADMIN_PAGE.plugins );
			await pluginLink( page, 'Deactivate' ).click();
			await expect( pluginLink( page, 'Activate' ) ).toBeVisible();
			await expect( page.locator( '.notice-error:visible' ) ).toHaveCount(
				0
			);

			await pluginLink( page, 'Activate' ).click();
			await expect( pluginLink( page, 'Deactivate' ) ).toBeVisible();
			await expect( page.locator( '.notice-error:visible' ) ).toHaveCount(
				0
			);
			expect( uncaught ).toEqual( [] );
		} finally {
			await requestUtils.activatePlugin( 'onesearch' );
		}

		const reactivated = await oneSearch.getSettings();
		for ( const option of options ) {
			expect( reactivated[ option ], option ).toEqual(
				configured[ option ]
			);
		}

		await admin.visitAdminPage( ADMIN_PAGE.settings );
		await expect(
			page.getByRole( 'heading', { name: 'Brand Sites' } )
		).toBeVisible();
	} );
} );
