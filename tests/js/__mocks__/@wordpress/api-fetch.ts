/**
 * External dependencies
 */
import { type Mock, vi } from 'vitest';
/**
 * WordPress dependencies
 */
import type realApiFetch from '@wordpress/api-fetch';

const apiFetch = vi.fn() as Mock & typeof realApiFetch;

apiFetch.use = vi.fn();
apiFetch.createNonceMiddleware = vi.fn( () => {
	const middleware = vi.fn();
	( middleware as Mock & { nonce: string } ).nonce = '';
	return middleware as Mock & { nonce: string };
} );

export default apiFetch;
