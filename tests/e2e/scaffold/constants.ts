/**
 * Names of the OneSearch options the E2E helper mu-plugin can seed and clear.
 *
 * Keep in sync with `MANAGED_OPTIONS` in
 * `tests/_data/mu-plugins/onesearch-e2e-helper.php`.
 */
export const OPTION = {
	algoliaCredentials: 'onesearch_algolia_credentials',
	consumerApiKey: 'onesearch_consumer_api_key',
	indexableEntities: 'onesearch_indexable_entities',
	parentSiteUrl: 'onesearch_parent_site_url',
	proxyAttachmentId: 'onesearch_proxy_attachment_id',
	sharedSites: 'onesearch_shared_sites',
	searchSettings: 'onesearch_sites_search_settings',
	siteType: 'onesearch_site_type',
} as const;

export const SITE_TYPE = {
	brand: 'brand-site',
	governing: 'governing-site',
} as const;

export const ADMIN_PAGE = {
	settings: 'admin.php?page=onesearch-settings',
	indices: 'admin.php?page=onesearch',
	plugins: 'plugins.php',
} as const;

/**
 * The wp-env test site, which acts as the governing site.
 *
 * Trailing-slashed, because that is how the plugin normalises a site URL and
 * therefore how it keys the entity and search-settings maps.
 */
export const GOVERNING_SITE_URL = 'http://localhost:8889/';

/**
 * The governing site without its trailing slash: how `parent_site_url` is
 * stored, and what the brand site sees in the `Origin` header.
 */
export const GOVERNING_SITE_ORIGIN = 'http://localhost:8889';

/**
 * The second wp-env install (`.wp-env.test-child.json`), acting as a real brand
 * site. Both hops to it are real: the server-side fan-out and the health check.
 */
export const BRAND_SITE_URL = 'http://localhost:8891';

/**
 * The brand site as the plugin keys it, trailing slash included.
 */
export const BRAND_SITE_KEY = `${ BRAND_SITE_URL }/`;

/**
 * The real brand site, as the governing site stores it.
 *
 * `api_key` must be seeded on both sides — see `setUpBrandSite()` — since the
 * brand site compares the incoming token against its own stored key.
 */
export const BRAND_SITE = {
	name: 'Brand Alpha',
	url: BRAND_SITE_URL,
	api_key: 'e2e-brand-api-key-0123456789',
} as const;

/**
 * A brand site on a host that does not resolve.
 *
 * Used for the two cases a reachable site cannot cover: a second row on the
 * settings screen, which never probes the network, and the governing site's
 * fan-out failing against a site it cannot reach.
 */
export const UNREACHABLE_BRAND = {
	name: 'Brand Beta',
	url: 'https://brand-beta.test',
	api_key: 'beta-api-key-0123456789',
} as const;

/**
 * The public post types a fresh WordPress install reports from
 * `/all-post-types`. Media is included: the endpoint filters on
 * `public => true` and does not exclude attachments.
 */
export const BRAND_POST_TYPES = [
	{ slug: 'post', label: 'Posts' },
	{ slug: 'page', label: 'Pages' },
	{ slug: 'attachment', label: 'Media' },
] as const;

/**
 * Algolia credentials are only ever seeded, never validated: the real
 * validation path calls Algolia over the network from PHP.
 */
export const ALGOLIA_CREDENTIALS = {
	app_id: 'E2EAPPID',
	write_key: 'e2e-write-key',
} as const;
