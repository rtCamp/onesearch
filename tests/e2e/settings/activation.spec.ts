/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'plugin activation', () => {
	test( 'should activate and deactivate the plugin', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( '/plugins.php' );

		const pluginRow = page.locator(
			'tr[data-plugin="onesearch/onesearch.php"]'
		);
		await expect( pluginRow ).toBeVisible();

		const activateLink = pluginRow.locator( 'a', { hasText: 'Activate' } );

		await Promise.all( [
			page.waitForURL( /plugins.php/ ),
			activateLink.click(),
		] );

		await expect(
			pluginRow.locator( 'a', { hasText: 'Deactivate' } )
		).toBeVisible( { timeout: 10000 } );

		const deactivateLink = pluginRow.locator( 'a', {
			hasText: 'Deactivate',
		} );
		await Promise.all( [
			page.waitForURL( /plugins.php/ ),
			deactivateLink.click(),
		] );

		await expect(
			pluginRow.locator( 'a', { hasText: 'Activate' } )
		).toBeVisible( { timeout: 10000 } );
	} );
} );
