/**
 * External dependencies
 */
import '@testing-library/jest-dom';

const fetchMock = jest.fn<
	ReturnType< typeof fetch >,
	Parameters< typeof fetch >
>();

Object.defineProperty( global, 'fetch', {
	value: fetchMock,
	writable: true,
} );

Object.defineProperty( window, 'OneSearchSettings', {
	value: {
		restUrl: 'https://example.com/wp-json/',
		restNamespace: 'onesearch/v1',
		nonce: 'nonce',
		api_key: 'api-key',
		currentSiteUrl: 'https://governing.example.com/',
		siteType: 'governing-site',
		setupUrl: '/wp-admin/admin.php?page=onesearch-settings',
	},
	writable: true,
} );

Object.defineProperty( window, 'OneSearchOnboarding', {
	value: {
		nonce: 'onboarding-nonce',
		site_type: '',
		setup_url: '',
	},
	writable: true,
} );

Object.defineProperty( navigator, 'clipboard', {
	value: {
		writeText: jest.fn().mockResolvedValue( undefined ),
	},
	configurable: true,
} );

/**
 * Jest test setup for OneSearch.
 *
 * @package
 */

beforeEach( () => {
	jest.clearAllMocks();
	fetchMock.mockReset();
} );
