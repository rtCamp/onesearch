/**
 * Validates if a given string is a valid http or https URL.
 *
 * @param {string} url - The URL string to validate.
 *
 * @return {boolean} True if the URL is valid, false otherwise.
 */
export const isValidUrl = ( url: string ): boolean => {
	try {
		return [ 'http:', 'https:' ].includes( new URL( url ).protocol );
	} catch {
		return false;
	}
};

/**
 * Ensures that a URL string ends with a trailing slash.
 *
 * @param {string} url - The URL string to normalize.
 * @return {string} The normalized URL with a trailing slash.
 */
export const withTrailingSlash = ( url: string ): string => {
	if ( ! url ) {
		return '';
	}
	return url.endsWith( '/' ) ? url : `${ url }/`;
};

/**
 * The API key used for authenticating requests to the OneSearch REST API.
 */
export const API_KEY = window.OneSearchSettings.api_key;

/**
 * REST namespace used by the OneSearch plugin.
 *
 * @constant
 * @type {string}
 */
export const REST_NAMESPACE = window.OneSearchSettings.restNamespace;

/**
 * Current site’s base URL.
 *
 * @constant
 * @type {string}
 */
export const CURRENT_SITE_URL = window.OneSearchSettings.currentSiteUrl;
