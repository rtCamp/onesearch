/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';
import {
	API_KEY,
	API_NAMESPACE,
	CURRENT_SITE_URL,
	NONCE,
	REST_NAMESPACE,
	isValidUrl,
	withTrailingSlash,
} from '@/js/utils';

describe( 'utils', () => {
	it( 'validates well-formed urls', () => {
		expect( isValidUrl( 'https://example.com/path' ) ).toBe( true );
		expect( isValidUrl( 'not-a-url' ) ).toBe( false );
	} );

	it( 'adds a trailing slash only when needed', () => {
		expect( withTrailingSlash( '' ) ).toBe( '' );
		expect( withTrailingSlash( 'https://example.com' ) ).toBe(
			'https://example.com/'
		);
		expect( withTrailingSlash( 'https://example.com/' ) ).toBe(
			'https://example.com/'
		);
	} );

	it( 'reads wordpress settings constants from the global config', () => {
		expect( API_NAMESPACE ).toBe(
			'https://example.com/wp-json/onesearch/v1'
		);
		expect( API_KEY ).toBe( 'api-key' );
		expect( NONCE ).toBe( 'nonce' );
		expect( REST_NAMESPACE ).toBe( 'onesearch/v1' );
		expect( CURRENT_SITE_URL ).toBe( 'https://governing.example.com/' );
	} );
} );
