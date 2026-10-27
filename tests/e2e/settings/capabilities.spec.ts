/**
 * Internal dependencies
 */
import { ADMIN_PAGE, SITE_TYPE, expect, test } from '../scaffold';

const EDITOR = {
	username: 'onesearch-editor',
	email: 'onesearch-editor@example.com',
	password: 'onesearch-editor-password',
	roles: [ 'editor' ],
};

test.describe( 'screen access', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await requestUtils.createUser( EDITOR );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
	} );

	test( 'an editor cannot reach either OneSearch screen', async ( {
		oneSearch,
		page,
	} ) => {
		await oneSearch.setSiteType( SITE_TYPE.governing );

		await page.goto( '/wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( EDITOR.username );
		await page
			.getByLabel( 'Password', { exact: true } )
			.fill( EDITOR.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.waitForURL( /wp-admin/ );

		await expect(
			page
				.getByRole( 'navigation', { name: 'Main menu' } )
				.getByRole( 'link', { name: 'OneSearch' } )
		).toHaveCount( 0 );

		for ( const screen of [ ADMIN_PAGE.settings, ADMIN_PAGE.indices ] ) {
			await page.goto( `/wp-admin/${ screen }` );
			await expect(
				page.getByText(
					'Sorry, you are not allowed to access this page.'
				)
			).toBeVisible();
		}
	} );
} );
