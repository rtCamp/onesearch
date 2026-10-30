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
	OPTION,
	SITE_TYPE,
	type SiteType,
} from './constants';

const NAMESPACE = '/onesearch/v1';

/**
 * A request the Algolia stub answered, decoded for assertions.
 */
export interface AlgoliaRequest {
	path: string;
	body: {
		filters?: string;
		requests?: { action: string; body: Record< string, unknown > }[];
	};
}

/**
 * A post a test published.
 */
export interface TestPost {
	id: number;
	link: string;
	title: string;
	content: string;
}

/**
 * Drives one site's OneSearch state through core settings and the plugin's own routes.
 */
export class OneSearchUtils {
	readonly requestUtils: RequestUtils;

	private readonly postIds: number[] = [];

	constructor( { requestUtils }: { requestUtils: RequestUtils } ) {
		this.requestUtils = requestUtils;
	}

	/**
	 * Return the site to a fresh install. Algolia credentials stay: no route clears them.
	 */
	async resetState(): Promise< void > {
		for ( const id of this.postIds.splice( 0 ) ) {
			await this.requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/posts/${ id }`,
				params: { force: true },
			} );
		}

		await this.requestUtils.rest( {
			method: 'POST',
			path: `${ NAMESPACE }/shared-sites`,
			data: { sites_data: [] },
		} );

		// Disconnecting is the only way to drop a brand site's cached copy of its governing site's configuration.
		try {
			await this.requestUtils.rest( {
				method: 'DELETE',
				path: `${ NAMESPACE }/governing-site`,
			} );
		} catch ( error ) {
			// Only brand sites register the route.
			if ( ( error as { code?: string } )?.code !== 'rest_no_route' ) {
				throw error;
			}
		}

		for ( const option of Object.values( OPTION ) ) {
			try {
				await this.updateSettings( { [ option ]: null } );
			} catch ( error ) {
				// Core refuses to null an option that is not stored, which is the state wanted here.
				if (
					( error as { code?: string } )?.code !==
					'rest_invalid_stored_value'
				) {
					throw error;
				}
			}
		}
	}

	/**
	 * Write settings through `/wp/v2/settings`; `null` deletes an option.
	 *
	 * `requestUtils.updateSiteSettings()` is typed for core settings only.
	 *
	 * @param settings Values keyed by option name.
	 */
	async updateSettings(
		settings: Record< string, unknown >
	): Promise< void > {
		await this.requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: settings,
		} );
	}

	async setSiteType( siteType: SiteType ): Promise< void > {
		await this.updateSettings( { [ OPTION.siteType ]: siteType } );
	}

	async getSettings(): Promise< Record< string, unknown > > {
		return this.requestUtils.getSiteSettings();
	}

	/**
	 * Publish a post, which `resetState()` deletes.
	 *
	 * @param title   The post title.
	 * @param content The post content.
	 */
	async createPost( title: string, content = '' ): Promise< TestPost > {
		const { id, link } = await this.requestUtils.rest< TestPost >( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title, content, status: 'publish' },
		} );
		this.postIds.push( id );

		return { id, link, title, content };
	}

	/**
	 * The requests this site sent to Algolia since the last reset.
	 */
	async algoliaRequests(): Promise< AlgoliaRequest[] > {
		const recorded = ( await this.getSettings() )[ OPTION.algoliaRequests ];

		const requests =
			typeof recorded === 'string' && recorded
				? ( JSON.parse( recorded ) as { path: string; body: string }[] )
				: [];

		return requests.map( ( { path, body } ) => ( {
			path,
			body: body ? JSON.parse( body ) : {},
		} ) );
	}

	/**
	 * Answer this site's Algolia searches with the given hits.
	 *
	 * @param hits The records returned by the search.
	 */
	async setAlgoliaSearchHits(
		hits: Record< string, unknown >[]
	): Promise< void > {
		await this.updateSettings( {
			[ OPTION.algoliaSearchResponse ]: JSON.stringify( {
				hits,
				nbHits: hits.length,
				nbPages: 1,
				page: 0,
			} ),
		} );
	}

	/**
	 * Save Algolia credentials through the plugin's route, which validates them against the stub.
	 */
	async saveAlgoliaCredentials(): Promise< void > {
		await this.requestUtils.rest( {
			method: 'POST',
			path: `${ NAMESPACE }/algolia-credentials`,
			data: ALGOLIA_CREDENTIALS,
		} );
	}

	/**
	 * Regenerate this site's API key, which is how a test learns it.
	 */
	async regenerateApiKey(): Promise< string > {
		const { secret_key: apiKey } = await this.requestUtils.rest< {
			secret_key: string;
		} >( {
			method: 'PUT',
			path: `${ NAMESPACE }/secret-key`,
		} );

		return apiKey;
	}
}

/**
 * An Algolia search hit for a post, shaped like the records the plugin indexes.
 *
 * @param post    The post.
 * @param siteUrl The site the post belongs to, as the plugin keys it.
 */
export function searchHit( post: TestPost, siteUrl: string ) {
	// WordPress's sanitize_key(), as used by Post_Record for site-scoped IDs.
	const siteKey = siteUrl.toLowerCase().replace( /[^a-z0-9_-]/g, '' );
	const sitePostId = `${ siteKey }_${ post.id }`;
	return {
		objectID: `${ sitePostId }_0`,
		site_post_id: sitePostId,
		site_key: siteKey,
		permalink: post.link,
		post_id: post.id,
		post_title: post.title,
		post_type: 'post',
		site_url: siteUrl,
		content: post.content,
		post_excerpt: '',
		chunk_index: 0,
		total_chunks: 1,
	};
}

/**
 * Connect the brand site to the governing site through the plugin's own handshake.
 *
 * @param governing       The governing site.
 * @param brand           The brand site.
 * @param options         Extra state for the governing site.
 * @param options.algolia Whether the governing site has Algolia credentials.
 */
export async function connectSites(
	governing: OneSearchUtils,
	brand: OneSearchUtils,
	options: { algolia?: boolean } = {}
): Promise< void > {
	await brand.setSiteType( SITE_TYPE.brand );
	const apiKey = await brand.regenerateApiKey();

	await governing.setSiteType( SITE_TYPE.governing );

	if ( options.algolia ) {
		await governing.saveAlgoliaCredentials();
	}

	// Saving a new brand site health-checks it, and that check connects it.
	await governing.requestUtils.rest( {
		method: 'POST',
		path: `${ NAMESPACE }/shared-sites`,
		data: { sites_data: [ { ...BRAND_SITE, api_key: apiKey } ] },
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

/**
 * The link of a search result on the theme's search page, which also lists
 * recent posts under lower-level headings.
 *
 * @param page  The page under test.
 * @param title The result's title.
 */
export function searchResult( page: Page, title: string ): Locator {
	return page
		.getByRole( 'heading', { level: 2, name: title } )
		.getByRole( 'link' );
}
