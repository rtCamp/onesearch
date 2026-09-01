/**
 * External dependencies
 */
import { defineConfig } from '@playwright/test';

/**
 * Internal dependencies
 */
import baseConfig from './playwright.config';

/**
 * The opt-in suite that talks to Algolia for real.
 *
 * It lives behind its own config, and its own `testDir`, so the default run
 * cannot pick it up by accident. It is not part of CI for pull requests: it
 * needs paid credentials, writes to a shared mutable index, and goes red
 * whenever Algolia has a bad day.
 */
export default defineConfig( {
	...baseConfig,
	testDir: './tests/e2e-smoke',
	outputDir: './tests/_output/e2e-smoke',
} );
