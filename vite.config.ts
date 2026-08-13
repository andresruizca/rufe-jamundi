import { defineConfig } from 'vitest/config';
import adapter from '@sveltejs/adapter-static';
import { sveltekit } from '@sveltejs/kit/vite';

// GitHub Pages sirve el sitio desde /<repo>/, así que en el build de CI
// exportamos BASE_PATH=/rufe-jamundi (ver .github/workflows/deploy.yml).
// En desarrollo local queda vacío para que todo resuelva desde la raíz.
const envBase = process.env.BASE_PATH;
const base: '' | `/${string}` = envBase && envBase.startsWith('/') ? (envBase as `/${string}`) : '';

export default defineConfig({
	plugins: [
		sveltekit({
			compilerOptions: {
				// Force runes mode for the project, except for libraries. Can be removed in svelte 6.
				runes: ({ filename }) =>
					filename.split(/[/\\]/).includes('node_modules') ? undefined : true
			},
			adapter: adapter(),
			paths: { base }
		})
	],
	test: {
		expect: { requireAssertions: true },
		projects: [
			{
				extends: './vite.config.ts',
				test: {
					name: 'server',
					environment: 'node',
					include: ['src/**/*.{test,spec}.{js,ts}'],
					exclude: ['src/**/*.svelte.{test,spec}.{js,ts}']
				}
			}
		]
	}
});
