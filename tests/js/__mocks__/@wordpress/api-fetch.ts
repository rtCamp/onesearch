/**
 * External dependencies
 */
import { type Mock, vi } from 'vitest';
/**
 * WordPress dependencies
 */
import type { ApiFetch } from '@wordpress/api-fetch';

const apiFetch = vi.fn() as Mock & ApiFetch;

apiFetch.use = vi.fn();
apiFetch.createNonceMiddleware = vi.fn( () => {
	const middleware = vi.fn();
	( middleware as Mock & { nonce: string } ).nonce = '';
	return middleware as Mock & { nonce: string };
} );

export default apiFetch;
