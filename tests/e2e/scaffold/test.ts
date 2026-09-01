/**
 * WordPress dependencies
 */
import {
	expect,
	RequestUtils,
	test as base,
} from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { BRAND_SITE_URL } from './constants';
import { OneSearchUtils } from './onesearch-utils';

/**
 * Build a `RequestUtils` for the brand site.
 *
 * `setupRest()` discovers the REST root from the module-level `WP_BASE_URL`, not
 * the instance's `baseURL`, so a second instance pairs the brand site's nonce
 * with the governing site's root. `rest()` answers a bad nonce by calling
 * `setupRest()` again, rediscovers the same wrong root, and never terminates.
 *
 * Setting the REST state here keeps `setupRest()` out of it. Nothing is
 * persisted, for the same reason.
 */
async function setUpBrandRequestUtils(): Promise< RequestUtils > {
	const requestUtils = await RequestUtils.setup( {
		baseURL: BRAND_SITE_URL,
	} );

	const nonce = await requestUtils.login();
	const { cookies } = await requestUtils.request.storageState();

	requestUtils.storageState = {
		cookies,
		nonce,
		rootURL: `${ BRAND_SITE_URL }/wp-json/`,
	};

	return requestUtils;
}

/**
 * Both installs are shared across the whole suite, so every spec has to leave
 * the plugin active and its options cleared. Both happen here rather than in
 * each file, which also makes specs independent of the order they run in.
 */
const test = base.extend<
	{ oneSearch: OneSearchUtils; brandSite: OneSearchUtils },
	{ brandRequestUtils: RequestUtils }
>( {
	oneSearch: async ( { requestUtils }, use ) => {
		await requestUtils.activatePlugin( 'onesearch' );

		/**
		 * The fan-out uses `wp_safe_remote_get()`, which refuses a `localhost`
		 * URL unless this helper relaxes `reject_unsafe_urls`. Without it the
		 * brand site just looks like it has no entities — a failure that reads
		 * as a missing element, not a missing plugin.
		 *
		 * The slug comes from the plugin's Name header, not its filename.
		 */
		await requestUtils.activatePlugin( 'onesearch-localhost-helper' );

		const oneSearch = new OneSearchUtils( { requestUtils } );
		await oneSearch.resetState();

		await use( oneSearch );

		await oneSearch.resetState();
	},

	brandRequestUtils: [
		async ( {}, use ) => {
			await use( await setUpBrandRequestUtils() );
		},
		{ scope: 'worker' },
	],

	brandSite: async ( { brandRequestUtils }, use ) => {
		await brandRequestUtils.activatePlugin( 'onesearch' );

		const brandSite = new OneSearchUtils( {
			requestUtils: brandRequestUtils,
		} );
		await brandSite.resetState();

		await use( brandSite );

		await brandSite.resetState();
	},
} );

export { expect, test };
