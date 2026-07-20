/**
 * Global type declarations for OneSearch.
 *
 * These types describe the window globals injected by WordPress PHP code.
 */

export type SiteType = 'governing-site' | 'brand-site' | '';

export interface OneSearchSharedSite {
	name: string;
	url: string;
	api_key?: string;
}

export interface OneSearchSettings {
	restUrl: string;
	nonce: string;
	api_key: string;
	setupUrl: string;
	siteType: SiteType;
	sharedSites?: OneSearchSharedSite[];
	restNamespace: string;
	currentSiteUrl: string;
	indexableEntities?: Record< string, string[] >;
	hasAlgoliaCredentials?: boolean;
}

export interface OneSearchOnboarding {
	nonce: string;
	site_type: SiteType | '';
	setup_url: string;
}

export type StatusUIType = 'badge' | 'text' | 'icon';

declare global {
	interface Window {
		OneSearchSettings: OneSearchSettings;
		OneSearchOnboarding: OneSearchOnboarding;
	}
}
