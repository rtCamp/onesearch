/**
 * Internal dependencies
 */
import { ADMIN_PAGE, expect, test } from '../scaffold';

test.describe( 'plugin activation', () => {
	test( 'activates and deactivates without errors', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const uncaught: string[] = [];
		page.on( 'pageerror', ( error ) => uncaught.push( error.message ) );

		const activateLink = page.getByRole( 'link', {
			name: 'Activate OneSearch',
			exact: true,
		} );
		const deactivateLink = page.getByRole( 'link', {
			name: 'Deactivate OneSearch',
			exact: true,
		} );

		// Leave the plugin active for subsequent tests, even on failure.
		try {
			await requestUtils.deactivatePlugin( 'onesearch' );
			await admin.visitAdminPage( ADMIN_PAGE.plugins );

			await activateLink.click();
			await expect( deactivateLink ).toBeVisible();
			await expect( page.locator( '.notice-error:visible' ) ).toHaveCount(
				0
			);
			expect( uncaught ).toEqual( [] );

			await deactivateLink.click();
			await expect( activateLink ).toBeVisible();
			await expect( page.locator( '.notice-error:visible' ) ).toHaveCount(
				0
			);
			expect( uncaught ).toEqual( [] );
		} finally {
			await requestUtils.activatePlugin( 'onesearch' );
		}
	} );
} );
