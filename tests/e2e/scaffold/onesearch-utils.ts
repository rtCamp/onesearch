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

	/**
	 * The site's own API key, decrypted. Empty until one has been generated.
	 */
	api_key: string;
}

const HELPER_ENDPOINT = 'onesearch-e2e/v1/state';

/**
 * Test helpers for driving one site's OneSearch state.
 *
 * The suite runs two WordPress installs — the governing site on 8889 and a real
 * brand site on 8891 — so this is instantiated once per site. State is seeded
 * through the E2E helper mu-plugin rather than through the UI, so each spec
 * starts from a known install without paying for the setup flow.
 *
 * `page` is only needed for the browser-side stubs, which apply to the
 * governing site.
 */
export class OneSearchUtils {
	private readonly page: Page | undefined;
	private readonly requestUtils: RequestUtils;

	constructor( {
		page,
		requestUtils,
	}: {
		page?: Page;
		requestUtils: RequestUtils;
	} ) {
		this.page = page;
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
	 * @param state         What to seed.
	 * @param state.options Option values, keyed by option name.
	 */
	async setState( state: { options?: SeedOptions } ): Promise< void > {
		await this.requestUtils.rest< HelperState >( {
			path: HELPER_ENDPOINT,
			method: 'POST',
			data: { options: state.options ?? {} },
		} );
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

	/**
	 * Answer the Algolia credential save.
	 *
	 * The real endpoint validates the key against Algolia over the network, so
	 * the browser-side call is stubbed and only the UI contract is asserted.
	 *
	 * @param success Whether the save should succeed.
	 */
	async stubAlgoliaSave( success = true ): Promise< void > {
		// `apiFetch` appends `?_locale=user`, so match on the path.
		await this.requirePage().route(
			( url ) =>
				url.pathname.endsWith( '/onesearch/v1/algolia-credentials' ),
			async ( route ) => {
				if ( route.request().method() !== 'POST' ) {
					await route.fallback();
					return;
				}

				await route.fulfill( {
					status: success ? 200 : 400,
					contentType: 'application/json',
					body: JSON.stringify(
						success
							? {
									success: true,
									message:
										'Algolia credentials updated successfully.',
							  }
							: {
									code: 'onesearch_algolia_credentials_invalid',
									message:
										'The provided Algolia credentials are invalid or lack necessary permissions.',
									data: { status: 400 },
							  }
					),
				} );
			}
		);
	}

	/**
	 * Answer a re-index request, which would otherwise reach Algolia.
	 *
	 * @param success Whether the re-index should report success.
	 * @param message The message the UI should surface.
	 */
	async stubReindex(
		success = true,
		message = 'Re-indexing complete.'
	): Promise< void > {
		await this.requirePage().route(
			( url ) => url.pathname.endsWith( '/onesearch/v1/re-index' ),
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { success, message } ),
				} );
			}
		);
	}

	/**
	 * The page, for the stubs that need one.
	 */
	private requirePage(): Page {
		if ( ! this.page ) {
			throw new Error(
				'This helper stubs browser requests and needs a `page`. The brand site helper is seeded through REST instead.'
			);
		}

		return this.page;
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
