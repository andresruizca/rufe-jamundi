<script lang="ts">
	// El guión, delante de la operadora todo el turno.
	//
	// No es una pantalla aparte ni un modal: una operadora no puede irse a otra
	// página a consultar qué decir mientras alguien le contesta el teléfono. En
	// pantalla ancha va pegado a la derecha de la lista y se queda ahí al bajar;
	// en un portátil pequeño o una tableta se abre como una hoja por encima, con
	// un botón que no se mueve nunca de su sitio.
	//
	// Lo edita el administrador desde aquí mismo, sin salir de la pantalla, y
	// cada versión que guarda queda registrada en el servidor.

	import { onMount } from 'svelte';
	import {
		BookOpen,
		Check,
		LoaderCircle,
		MessageSquareQuote,
		Pencil,
		RotateCcw,
		TriangleAlert,
		X
	} from '@lucide/svelte';
	import { callCenterApi } from '$lib/api/servicios';
	import { frasesQueSeDicen, leerGuion } from './guion';
	import { almacenGuion } from './guionStore.svelte';

	let { puedeEditar = false }: { puedeEditar?: boolean } = $props();

	const guion = $derived(almacenGuion.guion);
	const predeterminado = $derived(almacenGuion.predeterminado);
	const cargando = $derived(almacenGuion.cargando && almacenGuion.guion === null);

	let error = $state('');

	/** En pantalla estrecha el panel se abre y se cierra. En ancha está siempre. */
	let abierto = $state(false);

	let editando = $state(false);
	let borrador = $state('');
	let guardando = $state(false);

	const secciones = $derived(leerGuion(guion?.cuerpo ?? ''));

	onMount(() => {
		void almacenGuion.cargar();
	});

	function editar() {
		borrador = guion?.cuerpo ?? '';
		editando = true;
	}

	async function guardar() {
		guardando = true;
		error = '';

		try {
			const r = await callCenterApi.guardarGuion(borrador);
			almacenGuion.fijar(r.guion);
			editando = false;
		} catch (e) {
			const err = e as { errors?: Record<string, string>; message?: string };
			error = err.errors?.cuerpo ?? err.message ?? 'No se pudo guardar el guión.';
		} finally {
			guardando = false;
		}
	}

	function cuando(iso: string | null): string {
		if (!iso) return '';

		return new Date(iso.replace(' ', 'T')).toLocaleDateString('es-CO', {
			day: '2-digit',
			month: 'long',
			year: 'numeric'
		});
	}
</script>

<!--
	El botón solo existe en pantalla estrecha; en ancha el panel ya está a la
	vista y un botón para abrir lo que está abierto solo confunde.
-->
<button
	type="button"
	class="guion__tirador"
	onclick={() => (abierto = !abierto)}
	aria-expanded={abierto}
>
	{#if abierto}
		<X size={18} aria-hidden="true" />
		Cerrar el guión
	{:else}
		<BookOpen size={18} aria-hidden="true" />
		Ver el guión
	{/if}
</button>

<aside class="guion" class:guion--abierto={abierto} aria-label="Guión de la llamada">
	<div class="guion__cabeza">
		<h2 class="guion__titulo">
			<MessageSquareQuote size={17} aria-hidden="true" />
			Guión de la llamada
		</h2>

		{#if puedeEditar && !editando && !cargando}
			<button type="button" class="guion__editar" onclick={editar}>
				<Pencil size={14} aria-hidden="true" />
				Editar
			</button>
		{/if}
	</div>

	{#if error || almacenGuion.error}
		<p class="guion__error" role="alert">
			<TriangleAlert size={14} aria-hidden="true" />
			{error || almacenGuion.error}
		</p>
	{/if}

	{#if cargando}
		<p class="guion__cargando">
			<LoaderCircle size={16} class="girando" aria-hidden="true" />
			Cargando el guión…
		</p>
	{:else if editando}
		<!--
			El editor vive dentro del mismo panel a propósito: quien lo corrige
			tiene que ver, mientras escribe, exactamente lo que va a ver la
			operadora. Una pantalla de edición aparte hace que se escriban guiones
			que en el panel real no caben.
		-->
		<p class="guion__ayuda">
			Cada línea empieza con una marca: <code>##</code> una sección,
			<code>»</code> lo que se lee en voz alta, <code>-</code> una indicación para la operadora,
			<code>!</code> lo que no se debe decir, y <code>?</code> una pregunta frecuente
			(la respuesta va después de <code>»</code>).
		</p>

		<textarea class="guion__editor" bind:value={borrador} spellcheck="true"></textarea>

		<p class="guion__cuenta">
			{frasesQueSeDicen(borrador)} frases para leer en voz alta · {borrador.length} caracteres
		</p>

		{#if frasesQueSeDicen(borrador) === 0}
			<p class="guion__error">
				<TriangleAlert size={14} aria-hidden="true" />
				Este guión no tiene ni una frase para leer. Sin líneas que empiecen por «»», son notas, no
				un guión.
			</p>
		{/if}

		<div class="guion__acciones">
			<button
				type="button"
				class="boton boton--principal"
				onclick={guardar}
				disabled={guardando || borrador.trim().length < 40}
			>
				{guardando ? 'Guardando…' : 'Guardar el guión'}
			</button>
			<button type="button" class="boton boton--suave" onclick={() => (editando = false)}>
				Cancelar
			</button>
			<button
				type="button"
				class="guion__restaurar"
				onclick={() => (borrador = predeterminado)}
				title="Trae de vuelta el texto original. Todavía hay que guardarlo."
			>
				<RotateCcw size={13} aria-hidden="true" />
				Restaurar el original
			</button>
		</div>
	{:else}
		<div class="guion__cuerpo">
			{#each secciones as s, i (i)}
				<section class="paso">
					{#if s.titulo}<h3 class="paso__titulo">{s.titulo}</h3>{/if}

					{#each s.lineas as l, j (j)}
						{#if l.tipo === 'decir'}
							<!-- Lo que se lee en voz alta es lo único con comillas y en
							     cursiva: la operadora tiene que distinguirlo de un vistazo
							     de las notas que NO se dicen. -->
							<p class="decir">{l.texto}</p>
						{:else if l.tipo === 'hacer'}
							<p class="hacer">{l.texto}</p>
						{:else if l.tipo === 'nunca'}
							<p class="nunca">
								<TriangleAlert size={13} aria-hidden="true" />
								{l.texto}
							</p>
						{:else if l.tipo === 'pregunta'}
							<div class="frecuente">
								<p class="frecuente__p">{l.texto}</p>
								{#if l.respuesta}<p class="decir decir--respuesta">{l.respuesta}</p>{/if}
							</div>
						{:else}
							<p class="suelto">{l.texto}</p>
						{/if}
					{/each}
				</section>
			{/each}
		</div>

		<p class="guion__pie">
			{#if guion?.es_predeterminado}
				<Check size={13} aria-hidden="true" />
				Guión original del sistema.
			{:else}
				<Pencil size={13} aria-hidden="true" />
				Actualizado el {cuando(guion?.actualizado_en ?? null)}{guion?.por ? ` por ${guion.por}` : ''}.
			{/if}
		</p>
	{/if}
</aside>

<style>
	/* ── El panel ────────────────────────────────────────────────────────────
	   En pantalla ancha es una columna pegada que se queda a la vista al bajar.
	   El desplazamiento es SUYO, no de la página: si compartiera el de la lista,
	   bajar a buscar un hogar se llevaría el guión fuera de la pantalla, que es
	   justo lo que no puede pasar durante una llamada. */
	.guion {
		display: flex;
		flex-direction: column;
		gap: 0.6rem;
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		border-radius: 12px;
		padding: 0.9rem 1rem 1rem;
	}

	.guion__cabeza {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.5rem;
	}

	.guion__titulo {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 0;
		font-size: 0.95rem;
		font-weight: 700;
		color: var(--color-text);
	}

	.guion__editar,
	.guion__restaurar {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		border: 1px solid var(--color-border);
		background: transparent;
		color: var(--color-muted);
		border-radius: 999px;
		padding: 0.25rem 0.6rem;
		font-size: 0.75rem;
		cursor: pointer;
	}

	.guion__editar:hover,
	.guion__restaurar:hover {
		color: var(--color-text);
		border-color: var(--color-border-strong);
	}

	.guion__cuerpo {
		overflow-y: auto;
		display: flex;
		flex-direction: column;
		gap: 0.9rem;
		padding-right: 0.2rem;
	}

	.paso {
		display: flex;
		flex-direction: column;
		gap: 0.35rem;
	}

	.paso__titulo {
		margin: 0;
		font-size: 0.72rem;
		font-weight: 700;
		letter-spacing: 0.06em;
		text-transform: uppercase;
		color: var(--color-primary);
	}

	/* Lo que se dice en voz alta: es lo que la operadora busca con la mirada
	   mientras le contestan, así que es lo único con fondo propio. */
	.decir {
		margin: 0;
		padding: 0.45rem 0.6rem;
		border-left: 3px solid var(--color-primary);
		background: var(--color-surface-alt);
		border-radius: 0 6px 6px 0;
		font-size: 0.86rem;
		line-height: 1.45;
		color: var(--color-text);
	}

	.decir::before {
		content: '“';
	}

	.decir::after {
		content: '”';
	}

	.hacer,
	.suelto {
		margin: 0;
		font-size: 0.8rem;
		line-height: 1.4;
		color: var(--color-muted);
	}

	.hacer::before {
		content: '▸ ';
		color: var(--color-secondary);
	}

	.nunca {
		display: flex;
		align-items: flex-start;
		gap: 0.35rem;
		margin: 0;
		padding: 0.4rem 0.55rem;
		border-radius: 6px;
		background: var(--color-danger-bg);
		color: var(--color-danger);
		font-size: 0.79rem;
		line-height: 1.4;
		font-weight: 600;
	}

	.frecuente {
		display: flex;
		flex-direction: column;
		gap: 0.25rem;
		padding-top: 0.15rem;
	}

	.frecuente__p {
		margin: 0;
		font-size: 0.8rem;
		font-weight: 700;
		color: var(--color-text);
	}

	.decir--respuesta {
		border-left-color: var(--color-secondary);
	}

	.guion__pie,
	.guion__cargando,
	.guion__cuenta,
	.guion__ayuda {
		display: flex;
		align-items: center;
		gap: 0.35rem;
		margin: 0;
		font-size: 0.73rem;
		color: var(--color-muted);
	}

	.guion__ayuda {
		display: block;
		line-height: 1.5;
	}

	.guion__ayuda code {
		background: var(--color-surface-alt);
		border-radius: 4px;
		padding: 0 0.25rem;
	}

	.guion__error {
		display: flex;
		align-items: flex-start;
		gap: 0.35rem;
		margin: 0;
		font-size: 0.78rem;
		color: var(--color-danger);
	}

	.guion__editor {
		width: 100%;
		min-height: 22rem;
		flex: 1;
		resize: vertical;
		font-family: ui-monospace, 'SF Mono', Menlo, monospace;
		font-size: 0.78rem;
		line-height: 1.55;
		padding: 0.6rem;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		background: var(--color-bg);
		color: var(--color-text);
	}

	.guion__acciones {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 0.5rem;
	}

	/* ── Pantalla estrecha ───────────────────────────────────────────────────
	   Por debajo de 1100 px no caben dos columnas, así que el guión se convierte
	   en una hoja que sube desde abajo. El tirador queda fijo sobre la lista: es
	   lo que garantiza que el guión esté SIEMPRE a un toque, que era el
	   requisito. */
	.guion__tirador {
		display: none;
	}

	@media (max-width: 1100px) {
		.guion__tirador {
			display: inline-flex;
			align-items: center;
			gap: 0.4rem;
			position: fixed;
			right: 1rem;
			bottom: 1rem;
			z-index: 30;
			border: none;
			border-radius: 999px;
			padding: 0.7rem 1.1rem;
			background: var(--color-primary);
			color: #fff;
			font-size: 0.85rem;
			font-weight: 700;
			cursor: pointer;
			box-shadow: 0 6px 20px rgb(0 0 0 / 0.28);
		}

		.guion {
			position: fixed;
			inset: auto 0 0 0;
			z-index: 29;
			max-height: 78vh;
			border-radius: 14px 14px 0 0;
			border-bottom: none;
			box-shadow: 0 -8px 30px rgb(0 0 0 / 0.3);
			transform: translateY(101%);
			transition: transform 0.18s ease-out;
		}

		.guion--abierto {
			transform: translateY(0);
		}

		/* Sitio para que el tirador no tape la última fila de la lista. */
		.guion__cuerpo {
			padding-bottom: 3.5rem;
		}
	}

	@media (prefers-reduced-motion: reduce) {
		.guion {
			transition: none;
		}
	}
</style>
