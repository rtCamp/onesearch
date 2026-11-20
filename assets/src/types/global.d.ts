/**
 * Global type declarations for OneSearch.
 *
 * These types describe the window globals injected by WordPress PHP code.
 */

export type SiteType = 'governing-site' | 'brand-site' | '';

export interface OneSearchSharedSite {
	id: string;
	name: string;
	url: string;
	logo: string;
	logo_id: number;
}

export interface OneSearchSettings {
	api_key: string;
	setupUrl: string;
	siteType: SiteType | null;
	sharedSites?: OneSearchSharedSite[];
	restNamespace: string;
	currentSiteUrl: string;
	algoliaAppId?: string;
	hasAlgoliaCredentials?: '1' | '';
	deleteDataOnDeactivation?: '1' | '';
}

declare global {
	interface Window {
		OneSearchSettings: OneSearchSettings;
	}
}
