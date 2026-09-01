/**
 * Internal dependencies
 */
import { ADMIN_PAGE, SITE_TYPE, OPTION, expect, test } from '../scaffold';

const PLUGIN_ROW = 'tr[data-plugin="onesearch/onesearch.php"]';

test.describe( 'plugin activation', () => {
	test( 'activates and deactivates the plugin', async ( {
		admin,
		oneSearch,
		page,
		requestUtils,
	} ) => {
		// A stored site type keeps the onboarding modal off the row; the modal has its own specs.
		await oneSearch.setState( {
			options: { [ OPTION.siteType ]: SITE_TYPE.governing },
		} );
		await requestUtils.deactivatePlugin( 'onesearch' );

		/**
		 * Everything below runs against a deactivated install, so a failed
		 * assertion would otherwise leave it that way — for this spec's own
		 * teardown, for the next spec, and for a retry. Reactivate in `finally`
		 * so a failure costs one test rather than the rest of the run.
		 */
		try {
			await admin.visitAdminPage( ADMIN_PAGE.plugins );

			const pluginRow = page.locator( PLUGIN_ROW );
			await expect( pluginRow ).toBeVisible();

			await Promise.all( [
				page.waitForURL( /plugins.php/ ),
				pluginRow.getByRole( 'link', { name: 'Activate' } ).click(),
			] );

			await expect(
				pluginRow.getByRole( 'link', { name: 'Deactivate' } )
			).toBeVisible();

			await Promise.all( [
				page.waitForURL( /plugins.php/ ),
				pluginRow.getByRole( 'link', { name: 'Deactivate' } ).click(),
			] );

			await expect(
				pluginRow.getByRole( 'link', { name: 'Activate' } )
			).toBeVisible();
		} finally {
			// Leave the install as the rest of the suite expects to find it.
			await requestUtils.activatePlugin( 'onesearch' );
		}
	} );

	test( 'registers the OneSearch admin menu on activation', async ( {
		admin,
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite();
		await admin.visitAdminPage( ADMIN_PAGE.plugins );

		await expect(
			page
				.getByRole( 'navigation', { name: 'Main menu' } )
				.getByRole( 'link', { name: 'OneSearch' } )
				.first()
		).toBeVisible();
	} );
} );
