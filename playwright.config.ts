/**
 * External dependencies
 */
import { defineConfig, type PlaywrightTestConfig } from '@playwright/test';
import path from 'path';

const artifactsPath = path.join( process.cwd(), 'tests/_output/e2e' );

// Ensure WP artifacts (and storage-state) are written into tests/_output
process.env[ 'WP_ARTIFACTS_PATH' ] = artifactsPath;
// Ensure STORAGE_STATE_PATH points into tests/_output as well
process.env[ 'STORAGE_STATE_PATH' ] = path.join(
	artifactsPath,
	'storage-states',
	'admin.json'
);

const baseConfig =
	require( '@wordpress/scripts/config/playwright.config.js' ) as PlaywrightTestConfig;

const config = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	outputDir: './tests/_output/e2e',
	webServer: [
		{
			// Target the dedicated test environment, not the development one the upstream config starts.
			command: 'npm run wp-env:test start',
			port: Number(
				new URL(
					process.env[ 'WP_BASE_URL' ] || 'http://localhost:8889'
				).port
			),
			timeout: 120_000,
			reuseExistingServer: true,
		},
		{
			// The brand site half of the pair, so cross-site requests are made for real.
			command: 'npm run wp-env:test-child start',
			port: 8891,
			timeout: 120_000,
			reuseExistingServer: true,
		},
	],
} );

export default config;
