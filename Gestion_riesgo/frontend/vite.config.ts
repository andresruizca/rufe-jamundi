import { defineConfig } from 'vitest/config';
import adapter from '@sveltejs/adapter-static';
import { sveltekit } from '@sveltejs/kit/vite';

// El sitio se sirve desde la raíz de su propio dominio, así que `base` queda
// vacío. BASE_PATH se conserva por si alguna vez se publica bajo un
// subdirectorio (como hace GitHub Pages con /<repo>/).
const envBase = process.env.BASE_PATH;
const base: '' | `/${string}` = envBase && envBase.startsWith('/') ? (envBase as `/${string}`) : '';

export default defineConfig({
	plugins: [
		sveltekit({
			compilerOptions: {
				runes: ({ filename }) =>
					filename.split(/[/\\]/).includes('node_modules') ? undefined : true
			},
			// `fallback` genera un 200.html para las rutas que no se
			// pre-renderizan: el tablero y la administración dependen de la
			// sesión, así que se resuelven en el navegador, no en el build.
			adapter: adapter({ fallback: '200.html' }),
			paths: { base },
			prerender: { entries: [] }
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
