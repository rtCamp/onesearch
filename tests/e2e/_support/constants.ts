/**
 * The OneSearch options exposed through core's `/wp/v2/settings`, and the
 * Algolia stub's, which `algolia-helper.php` exposes the same way.
 */
export const OPTION = {
	indexableEntities: 'onesearch_indexable_entities',
	parentSiteUrl: 'onesearch_parent_site_url',
	searchSettings: 'onesearch_sites_search_settings',
	siteType: 'onesearch_site_type',
	algoliaRequests: 'onesearch_e2e_algolia_requests',
	algoliaSearchResponse: 'onesearch_e2e_algolia_search_response',
} as const;

export const SITE_TYPE = {
	brand: 'brand-site',
	governing: 'governing-site',
} as const;

export type SiteType = ( typeof SITE_TYPE )[ keyof typeof SITE_TYPE ];

export const ADMIN_PAGE = {
	settings: 'admin.php?page=onesearch-settings',
	indices: 'admin.php?page=onesearch',
	plugins: 'plugins.php',
} as const;

/**
 * The governing site, trailing-slashed because that is how the plugin keys the
 * entity and search-settings maps.
 */
export const GOVERNING_SITE_URL = 'http://localhost:8889/';

/**
 * The governing site as a brand site stores it in `parent_site_url`.
 */
export const GOVERNING_SITE_ORIGIN = 'http://localhost:8889';

export const BRAND_SITE_URL = 'http://localhost:8891';

/**
 * The brand site as the plugin keys it in the entity and search-settings maps.
 */
export const BRAND_SITE_KEY = `${ BRAND_SITE_URL }/`;

export const BRAND_SITE = {
	name: 'Brand Alpha',
	url: BRAND_SITE_URL,
} as const;

/**
 * Accepted by the Algolia stub in `algolia-helper.php`.
 */
export const ALGOLIA_CREDENTIALS = {
	app_id: 'E2EAPPID',
	write_key: 'e2e-write-key',
} as const;
