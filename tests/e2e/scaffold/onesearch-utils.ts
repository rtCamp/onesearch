/**
 * WordPress dependencies
 */
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * External dependencies
 */
import type { Locator, Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	ALGOLIA_CREDENTIALS,
	BRAND_SITE,
	GOVERNING_SITE_ORIGIN,
	OPTION,
	SITE_TYPE,
} from './constants';

export interface BrandSiteSeed {
	name: string;
	url: string;
	api_key: string;
	id?: string;
}

type ManagedOption = ( typeof OPTION )[ keyof typeof OPTION ];

type SeedOptions = Partial< Record< ManagedOption, unknown > >;

interface HelperState {
	options: Record< string, unknown >;
	algolia_mode: AlgoliaMode;
}

/**
 * How the mock Algolia transport should answer.
 *
 * Mocked inside PHP at the SDK's own `setHttpClient()` seam, so the plugin's own
 * REST routes still run for real. `live` drops the mock, for the smoke suite.
 */
export type AlgoliaMode = 'ok' | 'invalid_key' | 'server_error' | 'live';

const HELPER_ENDPOINT = 'onesearch-e2e/v1/state';

/**
 * Test helpers for driving one site's OneSearch state.
 *
 * Instantiated once per install — governing on 8889, brand on 8891. State is
 * seeded through the E2E helper mu-plugin rather than the UI, so each spec
 * starts from a known install.
 */
export class OneSearchUtils {
	private readonly requestUtils: RequestUtils;

	constructor( { requestUtils }: { requestUtils: RequestUtils } ) {
		this.requestUtils = requestUtils;
	}

	/**
	 * Delete every OneSearch option, returning the site to a freshly installed state.
	 */
	async resetState(): Promise< void > {
		await this.requestUtils.rest< HelperState >( {
			path: HELPER_ENDPOINT,
			method: 'DELETE',
		} );
	}

	/**
	 * Seed options. A `null` value deletes the option.
	 *
	 * @param state             What to seed.
	 * @param state.options     Option values, keyed by option name.
	 * @param state.algoliaMode How the mock Algolia transport should answer.
	 */
	async setState( state: {
		options?: SeedOptions;
		algoliaMode?: AlgoliaMode;
	} ): Promise< void > {
		const data: { options: SeedOptions; algolia_mode?: AlgoliaMode } = {
			options: state.options ?? {},
		};

		if ( state.algoliaMode ) {
			data.algolia_mode = state.algoliaMode;
		}

		await this.requestUtils.rest< HelperState >( {
			path: HELPER_ENDPOINT,
			method: 'POST',
			data,
		} );
	}

	/**
	 * Choose how Algolia answers for the rest of the test.
	 *
	 * @param mode The outcome the mock transport should produce.
	 */
	async setAlgoliaMode( mode: AlgoliaMode ): Promise< void > {
		await this.setState( { algoliaMode: mode } );
	}

	/**
	 * Read the stored options back, for asserting on persistence directly.
	 */
	async getState(): Promise< HelperState > {
		return this.requestUtils.rest< HelperState >( {
			path: HELPER_ENDPOINT,
		} );
	}

	/**
	 * Put the site into the governing role.
	 *
	 * Brand sites are stored the way a real save stores them, API key included,
	 * so requests to them are real. Seed the matching key with `setUpBrandSite()`.
	 *
	 * @param seed            What to seed alongside the site type.
	 * @param seed.brandSites Brand sites to register.
	 * @param seed.algolia    Whether to store Algolia credentials.
	 */
	async setUpGoverningSite(
		seed: { brandSites?: BrandSiteSeed[]; algolia?: boolean } = {}
	): Promise< void > {
		const options: SeedOptions = {
			[ OPTION.siteType ]: SITE_TYPE.governing,
			[ OPTION.sharedSites ]: seed.brandSites ?? [],
		};

		if ( seed.algolia ) {
			options[ OPTION.algoliaCredentials ] = ALGOLIA_CREDENTIALS;
		}

		await this.setState( { options } );
	}

	/**
	 * Put the site into the brand role.
	 *
	 * The key is stored encrypted, so the governing site can hold the same
	 * plaintext key and the comparison here will match. Omitting
	 * `governingSiteUrl` leaves the connection for a health check to bootstrap.
	 *
	 * @param seed                  What to seed alongside the site type.
	 * @param seed.apiKey           The API key the governing site will present.
	 * @param seed.governingSiteUrl The governing site to appear connected to.
	 */
	async setUpBrandSite(
		seed: { apiKey?: string; governingSiteUrl?: string } = {}
	): Promise< void > {
		const options: SeedOptions = {
			[ OPTION.siteType ]: SITE_TYPE.brand,
		};

		if ( seed.apiKey ) {
			options[ OPTION.consumerApiKey ] = seed.apiKey;
		}

		if ( seed.governingSiteUrl ) {
			options[ OPTION.parentSiteUrl ] = seed.governingSiteUrl.replace(
				/\/$/,
				''
			);
		}

		await this.setState( { options } );
	}
}

/**
 * Connect the governing site and the brand site to each other.
 *
 * Both sides must agree on the API key, so this seeds the pair together.
 *
 * @param governing        The governing site helper.
 * @param brand            The brand site helper.
 * @param seed             What to seed.
 * @param seed.brandSites  Brand sites the governing site holds. Defaults to the real one.
 * @param seed.brandApiKey The key the brand site accepts. Defaults to matching.
 * @param seed.algolia     Whether the governing site has Algolia credentials.
 * @param seed.bootstrap   Leave unconnected, so a health check records the governing site.
 */
export async function connectSites(
	governing: OneSearchUtils,
	brand: OneSearchUtils,
	seed: {
		brandSites?: BrandSiteSeed[];
		brandApiKey?: string;
		algolia?: boolean;
		bootstrap?: boolean;
	} = {}
): Promise< void > {
	await brand.setUpBrandSite(
		seed.bootstrap
			? { apiKey: seed.brandApiKey ?? BRAND_SITE.api_key }
			: {
					apiKey: seed.brandApiKey ?? BRAND_SITE.api_key,
					governingSiteUrl: GOVERNING_SITE_ORIGIN,
			  }
	);

	await governing.setUpGoverningSite( {
		brandSites: seed.brandSites ?? [ BRAND_SITE ],
		algolia: seed.algolia === true,
	} );
}

/**
 * The visible notices and snackbars on the page.
 *
 * WordPress mirrors notice text into an `aria-live` region, so matching on text
 * alone resolves to two elements.
 *
 * @param page The page under test.
 */
export function notices( page: Page ): Locator {
	return page.locator(
		'.components-snackbar__content, .components-notice__content'
	);
}
