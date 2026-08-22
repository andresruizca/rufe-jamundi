import adapter from '@sveltejs/adapter-static';
import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

/**
 * El APK es una SPA que se sirve desde el propio teléfono: no hay servidor que
 * pueda pre-renderizar, y `fallback` deja que el enrutador del navegador
 * resuelva cualquier ruta sin pedir nada a la red.
 */
export default {
	preprocess: vitePreprocess(),
	kit: {
		adapter: adapter({ fallback: 'index.html', strict: false }),
		alias: { $formulario: 'src/formulario', $local: 'src/local' }
	}
};
