/**
 * Internal dependencies
 */
import { ADMIN_PAGE, expect, test } from '../scaffold';

const EDITOR = {
	username: 'onesearch-rest-editor',
	email: 'onesearch-rest-editor@example.com',
	password: 'onesearch-rest-editor-password',
	roles: [ 'editor' ],
};

// PHPUnit's RouteAuthorizationTest covers the endpoints; this covers the screen.
test.describe( 'indices screen access', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await requestUtils.createUser( EDITOR );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
	} );

	test( 'the indices screen is closed to an editor', async ( {
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

		await page.goto( `/wp-admin/${ ADMIN_PAGE.indices }` );

		await expect(
			page.getByText( 'Sorry, you are not allowed to access this page.' )
		).toBeVisible();
	} );
} );
