/**
 * Internal dependencies
 */
import { ADMIN_PAGE, BRAND_SITE, expect, test } from '../scaffold';

const EDITOR = {
	username: 'onesearch-rest-editor',
	email: 'onesearch-rest-editor@example.com',
	password: 'onesearch-rest-editor-password',
	roles: [ 'editor' ],
};

/**
 * PHPUnit covers the endpoints exhaustively. Only a browser can show that the
 * running site refuses a signed-in user without `manage_options` — cookie auth,
 * nonce and capability check together, not a synthetic in-process request.
 */
test.describe( 'REST authorization from the browser', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await requestUtils.createUser( EDITOR );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
	} );

	test( 'an editor is refused by the endpoints and changes nothing', async ( {
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

		/**
		 * Cookie auth only applies to a REST request carrying a nonce; without
		 * one the caller is anonymous and answers 401, proving nothing about
		 * capabilities. So use the nonce this session is entitled to.
		 */
		const status = await page.evaluate( async ( brandSite ) => {
			const nonce = await (
				await fetch(
					`${ window.location.origin }/wp-admin/admin-ajax.php?action=rest-nonce`,
					{ credentials: 'same-origin' }
				)
			).text();

			const response = await fetch(
				`${ window.location.origin }/wp-json/onesearch/v1/shared-sites`,
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify( { sites_data: [ brandSite ] } ),
				}
			);

			return response.status;
		}, BRAND_SITE );

		// 403, not 401: the site knows who this is and refuses anyway.
		expect( status ).toBe( 403 );

		// The refusal has to be real, not just a status code.
		const stored = ( await oneSearch.getState() ).options[
			'onesearch_shared_sites'
		];
		expect( stored ).toStrictEqual( [] );
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
