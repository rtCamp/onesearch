/**
 * Internal dependencies
 */
import { ADMIN_PAGE, expect, test } from '../scaffold';

const EDITOR = {
	username: 'onesearch-editor',
	email: 'onesearch-editor@example.com',
	password: 'onesearch-editor-password',
	roles: [ 'editor' ],
};

test.describe( 'settings screen access', () => {
	// Sign in as the editor rather than reusing the stored admin session.
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await requestUtils.createUser( EDITOR );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
	} );

	test( 'is closed to users without manage_options', async ( {
		oneSearch,
		page,
	} ) => {
		await oneSearch.setUpGoverningSite();

		await page.goto( '/wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( EDITOR.username );
		await page
			.getByLabel( 'Password', { exact: true } )
			.fill( EDITOR.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.waitForURL( /wp-admin/ );

		// The menu is registered for manage_options only.
		await expect(
			page
				.locator( '#adminmenu' )
				.getByRole( 'link', { name: 'OneSearch' } )
		).toHaveCount( 0 );

		await page.goto( `/wp-admin/${ ADMIN_PAGE.settings }` );
		await expect(
			page.getByText( 'Sorry, you are not allowed to access this page.' )
		).toBeVisible();
	} );
} );
