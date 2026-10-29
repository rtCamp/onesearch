/**
 * External dependencies
 */
import { type Mock, vi } from 'vitest';
/**
 * WordPress dependencies
 */
import type realApiFetch from '@wordpress/api-fetch';

const apiFetch = vi.fn() as Mock & typeof realApiFetch;

export default apiFetch;
