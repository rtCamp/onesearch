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
 * Algolia is the only boundary the suite mocks, and it is mocked inside PHP at
 * the SDK's own `setHttpClient()` seam. The plugin's REST routes therefore run
 * for real — permission checks, validation, option writes and the mapping from
 * an SDK failure to an HTTP status all execute.
 *
 * `live` removes the mock entirely, and is only used by the opt-in smoke suite.
 */
export type AlgoliaMode = 'ok' | 'invalid_key' | 'server_error' | 'live';

const HELPER_ENDPOINT = 'onesearch-e2e/v1/state';

/**
 * Test helpers for driving one site's OneSearch state.
 *
 * The suite runs two WordPress installs — the governing site on 8889 and a real
 * brand site on 8891 — so this is instantiated once per site. State is seeded
 * through the E2E helper mu-plugin rather than through the UI, so each spec
 * starts from a known install without paying for the setup flow.
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
	 * Seed options.
	 *
	 * Option values are keyed by option name; `null` deletes the option.
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
	 * Put the site into the governing role, optionally with brand sites and credentials.
	 *
	 * Brand sites are stored exactly as a real save stores them, API key
	 * included, so requests to them are made for real. A brand site that should
	 * answer has to be seeded with the matching key through `setUpBrandSite()`.
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
	 * The API key is stored encrypted, the way the plugin stores it, so the
	 * governing site can be seeded with the same plaintext key and the token
	 * comparison on this side will match.
	 *
	 * `governingSiteUrl` is what a completed health check would have recorded.
	 * Seeding it lets a spec start from an already-connected brand site;
	 * omitting it leaves the connection for the health check to bootstrap.
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
 * Connect the governing site and the real brand site to each other.
 *
 * Both sides have to agree on the API key, so this seeds the pair together.
 * The brand site is left already connected unless `bootstrap` is set, which
 * leaves `parent_site_url` unwritten for a health check to record.
 *
 * @param governing        The governing site helper.
 * @param brand            The brand site helper.
 * @param seed             What to seed.
 * @param seed.brandSites  Brand sites the governing site should hold. Defaults
 *                         to the real brand site.
 * @param seed.brandApiKey The key the brand site should accept. Defaults to
 *                         the real brand site's key, so the two sides match.
 * @param seed.algolia     Whether the governing site has Algolia credentials.
 * @param seed.bootstrap   Leave the brand site unconnected, so a health check
 *                         has to record the governing site itself.
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
 * WordPress mirrors notice text into an `aria-live` region for screen readers,
 * so matching on text alone resolves to two elements.
 *
 * @param page The page under test.
 */
export function notices( page: Page ): Locator {
	return page.locator(
		'.components-snackbar__content, .components-notice__content'
	);
}
