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
 * `RequestUtils` is written for a single install: `setupRest()` discovers the
 * REST root from the module-level `WP_BASE_URL` rather than from the instance's
 * `baseURL`, so a second instance would pair the brand site's nonce with the
 * governing site's REST root. That combination fails the nonce check, and
 * `rest()` responds to a bad nonce by calling `setupRest()` again — which
 * rediscovers the same wrong root, so the call never terminates.
 *
 * Logging in and setting the REST state here keeps `setupRest()` out of it.
 * Nothing is persisted to disk for the same reason: a stored state would carry
 * the wrong root into the next run.
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
	oneSearch: async ( { page, requestUtils }, use ) => {
		await requestUtils.activatePlugin( 'onesearch' );

		const oneSearch = new OneSearchUtils( { page, requestUtils } );
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

		// No `page`: the brand site is only ever driven through REST.
		const brandSite = new OneSearchUtils( {
			requestUtils: brandRequestUtils,
		} );
		await brandSite.resetState();

		await use( brandSite );

		await brandSite.resetState();
	},
} );

export { expect, test };
