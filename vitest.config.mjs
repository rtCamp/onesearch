/**
 * Vitest configuration for OneSearch.
 *
 * @see https://vitest.dev/config/
 */

/**
 * External dependencies
 */
import { fileURLToPath } from 'node:url';
import { configDefaults, defineConfig } from 'vitest/config';

export default defineConfig( {
	resolve: {
		alias: {
			// Path alias for assets/src directory
			'@': fileURLToPath( new URL( './assets/src', import.meta.url ) ),
		},
	},
	test: {
		environment: 'jsdom',
		setupFiles: [ './tests/js/setup.ts' ],
		exclude: [
			...configDefaults.exclude,
			'tests/e2e/**',
			'tests/e2e-smoke/**',
		],
		testTimeout: 10000,
		// Testing Library advances Jest's fake timers while it waits but not Vitest's, so let them follow the real clock.
		fakeTimers: { shouldAdvanceTime: true },
		// Pre-bundle the heavy dependencies.
		deps: {
			optimizer: {
				client: {
					enabled: true,
					include: [
						'react-dom',
						'react-dom/client',
						'@wordpress/components',
						'@wordpress/element',
						'@wordpress/i18n',
						'@testing-library/react',
						'@testing-library/user-event',
					],
					exclude: [ 'uuid', 'react/jsx-runtime' ],
				},
			},
		},
		coverage: {
			include: [ 'assets/src/**/*.{js,jsx,ts,tsx}' ],
			exclude: [
				// Type definition files
				'assets/src/**/*.d.ts',
				// Barrel exports
				'assets/src/**/index.{js,tsx,jsx}',
			],
			reportsDirectory: 'tests/_output/js-coverage',
			reporter: [ 'text', 'text-summary', 'lcov', 'html' ],
		},
	},
} );
