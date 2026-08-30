<script lang="ts">
	// Poner la base al día después de un despliegue.
	//
	// ── Por qué esta pantalla existe ─────────────────────────────────────────
	//
	// El script de despliegue sube el código y NO corre migraciones, a
	// propósito: reescribir el esquema de una base con datos de familias
	// damnificadas sin poder mirar el resultado es peor que acordarse a mano.
	//
	// Pero «acordarse a mano» necesita un sitio donde acordarse. Hasta hoy la
	// única forma de correrlas era la actualización completa, que se baja el
	// código de GitHub y reescribe el sitio entero: un martillo enorme cuando
	// lo único que falta es crear dos tablas.
	//
	// ── Por qué se puede pulsar sin miedo ────────────────────────────────────
	//
	// Las migraciones de este sistema solo AÑADEN. Hay pruebas que rechazan un
	// UPDATE, un MODIFY o un DROP dentro de database/, y todas llevan IF NOT
	// EXISTS. Correr esto dos veces no hace nada la segunda vez.

	import { Database, LoaderCircle, Check, TriangleAlert } from '@lucide/svelte';
	import { api, ApiError } from '$lib/api/client';

	type Resultado = { archivos: string[]; tablas_nuevas: string[]; tablas: number };

	let corriendo = $state(false);
	let resultado = $state<Resultado | null>(null);
	let error = $state('');

	async function correr() {
		if (corriendo) return;

		corriendo = true;
		error = '';
		resultado = null;

		try {
			resultado = await api.post<Resultado>('/sistema/migrar');
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudo actualizar la base de datos.';
		} finally {
			corriendo = false;
		}
	}
</script>

<svelte:head>
	<title>Actualizar la base de datos — SGR Jamundí</title>
</svelte:head>

<div class="tarjeta">
	<h2 class="tarjeta__titulo">
		<Database size={17} aria-hidden="true" /> Actualizar la base de datos
	</h2>

	<p class="tarjeta__nota">
		Crea las tablas y columnas que traiga una versión nueva del sistema. El despliegue sube el
		código pero no toca la base: este es el paso que falta, y hace falta después de cada
		actualización que añada algo.
	</p>

	<p class="tarjeta__nota">
		<strong>Se puede pulsar sin miedo y las veces que haga falta.</strong> Estas actualizaciones
		solo añaden: nunca borran ni modifican lo que ya está guardado. Si no hay nada nuevo, no pasa
		nada.
	</p>

	<button type="button" class="boton boton--primario" onclick={correr} disabled={corriendo}>
		{#if corriendo}
			<LoaderCircle class="girando" size={16} aria-hidden="true" />
			Actualizando…
		{:else}
			<Database size={16} aria-hidden="true" />
			Poner la base al día
		{/if}
	</button>

	{#if error}
		<p class="aviso aviso--error" role="alert">
			<TriangleAlert size={16} aria-hidden="true" />
			{error}
		</p>
	{:else if resultado}
		<div class="resultado" role="status">
			<p class="resultado__titulo">
				<Check size={16} aria-hidden="true" />
				{#if resultado.tablas_nuevas.length > 0}
					Listo: {resultado.tablas_nuevas.length}
					{resultado.tablas_nuevas.length === 1 ? 'tabla nueva' : 'tablas nuevas'}.
				{:else}
					<!-- Decir «no había nada que hacer» y no callar: el silencio
					     después de pulsar se lee como que no funcionó. -->
					Listo. No había nada nuevo que crear.
				{/if}
			</p>

			{#if resultado.tablas_nuevas.length > 0}
				<ul class="resultado__tablas">
					{#each resultado.tablas_nuevas as tabla (tabla)}
						<li>{tabla}</li>
					{/each}
				</ul>
			{/if}

			<p class="tarjeta__nota">
				{resultado.archivos.length} archivos revisados · {resultado.tablas} tablas en total.
			</p>
		</div>
	{/if}
</div>

<style>
	.tarjeta__titulo {
		display: flex;
		align-items: center;
		gap: 0.5rem;
	}

	.tarjeta__nota {
		max-width: 44rem;
		margin: 0 0 0.8rem;
		font-size: 0.9rem;
		line-height: 1.55;
		color: var(--color-muted);
	}

	.boton {
		display: inline-flex;
		align-items: center;
		gap: 0.45rem;
		margin-top: 0.4rem;
	}

	.resultado {
		margin-top: 1.1rem;
		padding: 0.9rem 1rem;
		border: 1px solid var(--color-success);
		border-radius: 10px;
		background: color-mix(in srgb, var(--color-success) 8%, transparent);
	}

	.resultado__titulo {
		display: flex;
		align-items: center;
		gap: 0.45rem;
		margin: 0 0 0.5rem;
		font-weight: 600;
		color: var(--color-text);
	}

	.resultado__tablas {
		margin: 0 0 0.7rem;
		padding-left: 1.2rem;
		font-family: ui-monospace, monospace;
		font-size: 0.85rem;
		color: var(--color-text);
	}

	.aviso {
		display: flex;
		align-items: center;
		gap: 0.45rem;
		margin-top: 1rem;
	}
</style>
