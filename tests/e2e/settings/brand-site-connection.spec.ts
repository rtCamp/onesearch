/**
 * Internal dependencies
 */
import { ADMIN_PAGE, expect, test } from '../scaffold';

test.describe( 'brand site connection', () => {
	test( 'cannot disconnect when no governing site is connected', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpBrandSite();
		await admin.visitAdminPage( ADMIN_PAGE.settings );

		await expect(
			page.getByRole( 'textbox', { name: 'Governing Site URL' } )
		).toHaveValue( '' );
		await expect(
			page.getByRole( 'button', { name: 'Disconnect Governing Site' } )
		).toBeDisabled();
	} );
} );
