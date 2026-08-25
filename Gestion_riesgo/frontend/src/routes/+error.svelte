<script lang="ts">
	// Cuando la dirección no lleva a ninguna parte.
	//
	// Sin este archivo, SvelteKit sirve su página por defecto: fondo blanco,
	// «404 · Not Found» en inglés y ninguna salida. En un sistema que es todo en
	// español y que se usa desde el teléfono de un censador en una vereda, eso no
	// es una pantalla de error: es una aplicación rota.
	//
	// Y hay un caso concreto que hoy llega aquí: quien tenga guardado el enlace
	// de «Pendientes», que dejó de existir cuando la cola de envío se mudó dentro
	// de cada formato. A esa persona no le sirve «no encontrado» — le sirve saber
	// a dónde se fue lo que buscaba.

	import { page } from '$app/state';
	import { FileQuestion, MoveRight } from '@lucide/svelte';

	/**
	 * Pantallas que existieron y se movieron.
	 *
	 * Un enlace guardado sobrevive a un rediseño. Decir «no existe» a quien
	 * escribió bien la dirección es culpar al usuario de una decisión nuestra.
	 */
	const MUDADAS: Record<string, { a: string; etiqueta: string; porque: string }> = {
		'/riesgo/pendientes': {
			a: '/riesgo/reportar',
			etiqueta: 'Ir al formulario RUFE',
			porque:
				'Las fichas que aún no llegaron a la Alcaldía ya no tienen pantalla propia: ' +
				'aparecen dentro del formulario que las levantó, junto a las que quedaron a medias.'
		}
	};

	const mudada = $derived(MUDADAS[page.url.pathname] ?? null);
	const esNoEncontrada = $derived(page.status === 404);
</script>

<div class="tarjeta error">
	<FileQuestion size={34} aria-hidden="true" />

	{#if mudada}
		<h2 class="error__titulo">Esa pantalla se movió</h2>
		<p class="error__texto">{mudada.porque}</p>
		<a class="boton boton--principal" href={mudada.a}>
			{mudada.etiqueta}
			<MoveRight size={15} aria-hidden="true" />
		</a>
	{:else if esNoEncontrada}
		<h2 class="error__titulo">No encontramos esa página</h2>
		<p class="error__texto">
			La dirección <code>{page.url.pathname}</code> no corresponde a ninguna pantalla del sistema.
			Puede que el enlace esté mal escrito o que haya cambiado.
		</p>
		<a class="boton boton--principal" href="/">Volver al inicio</a>
	{:else}
		<h2 class="error__titulo">Algo salió mal</h2>
		<p class="error__texto">
			{page.error?.message ?? 'No se pudo mostrar esta pantalla.'}
		</p>
		<!-- Recargar y no «volver a intentar»: lo segundo promete que el sistema
		     hará algo, y lo único que hay aquí es volver a pedir la página. -->
		<div class="error__acciones">
			<button type="button" class="boton boton--principal" onclick={() => location.reload()}>
				Recargar
			</button>
			<a class="boton boton--suave" href="/">Volver al inicio</a>
		</div>
	{/if}

	<!--
		Lo importante para quien está en campo: nada de lo guardado se pierde por
		haber caído aquí. Es la primera pregunta de quien lleva media jornada de
		fichas en el teléfono.
	-->
	<p class="error__ojo">
		Las fichas guardadas en este aparato siguen ahí. Esta pantalla no borra nada.
	</p>
</div>

<style>
	.error {
		display: grid;
		justify-items: center;
		gap: 0.65rem;
		max-width: 34rem;
		margin: 2rem auto;
		padding: 2rem 1.4rem;
		text-align: center;
		color: var(--color-muted);
	}

	.error__titulo {
		margin: 0.2rem 0 0;
		font-size: 1.15rem;
		color: var(--color-text);
	}

	.error__texto {
		margin: 0;
		font-size: 0.9rem;
		line-height: 1.55;
	}

	.error__texto code {
		font-size: 0.85em;
		overflow-wrap: anywhere;
	}

	.error__acciones {
		display: flex;
		gap: 0.5rem;
		flex-wrap: wrap;
		justify-content: center;
	}

	.error__ojo {
		margin: 0.6rem 0 0;
		padding-top: 0.85rem;
		border-top: 1px solid var(--color-border);
		width: 100%;
		font-size: 0.79rem;
		line-height: 1.45;
	}
</style>
