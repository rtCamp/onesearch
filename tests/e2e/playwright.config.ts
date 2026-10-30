/**
 * External dependencies
 */
import { defineConfig, type PlaywrightTestConfig } from '@playwright/test';
import path from 'path';

const root = path.resolve( __dirname, '../..' );
const artifactsPath = path.join( root, 'tests/_output/e2e' );

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
	testDir: '.',
	outputDir: artifactsPath,
	webServer: [
		{
			// Target the dedicated test environment, not the development one the upstream config starts.
			command: 'npm run wp-env:test start',
			cwd: root,
			port: Number(
				new URL(
					process.env[ 'WP_BASE_URL' ] || 'http://localhost:8889'
				).port
			),
			timeout: 120_000,
			reuseExistingServer: true,
		},
		{
			// The brand site, for the cross-site requests.
			command: 'npm run wp-env:test-child start',
			cwd: root,
			port: 8891,
			timeout: 120_000,
			reuseExistingServer: true,
		},
	],
} );

export default config;
