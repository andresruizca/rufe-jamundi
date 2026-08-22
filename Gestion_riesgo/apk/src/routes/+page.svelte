<script lang="ts">
	// Marcador de posición hasta la fase 4, que arma el formulario de cuatro
	// pasos sobre las piezas copiadas en `src/formulario/`.
	//
	// Existe para que `npm run build` produzca un APK instalable ya, y se pueda
	// comprobar en un teléfono de verdad que Capacitor arranca, que SQLite abre
	// y que la cámara pide permisos antes de haber construido nada encima.

	import { onMount } from 'svelte';
	import { abrir, dispositivoId } from '$local/base';

	let estado = $state('Abriendo la base local…');

	onMount(() => {
		void (async () => {
			try {
				const db = await abrir();
				const r = await db.query('SELECT COUNT(*) AS n FROM registros');
				const id = await dispositivoId();

				estado =
					`Base local lista · ${r.values?.[0]?.n ?? 0} registro(s) guardados\n` +
					`Este aparato: ${id.slice(0, 8)}…`;
			} catch (e) {
				estado = `No se pudo abrir la base local: ${e instanceof Error ? e.message : e}`;
			}
		})();
	});
</script>

<main>
	<h1>Inspección de Vivienda</h1>
	<p class="entidad">Alcaldía de Jamundí</p>
	<pre>{estado}</pre>
</main>

<style>
	main {
		padding: 2rem 1.2rem;
		color: #16243f;
	}

	h1 {
		margin: 0;
		font-size: 1.3rem;
	}

	.entidad {
		margin: 0.2rem 0 1.5rem;
		font-size: 0.8rem;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: #647189;
	}

	pre {
		white-space: pre-wrap;
		font-size: 0.85rem;
		line-height: 1.5;
	}
</style>
