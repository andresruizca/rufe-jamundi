<script lang="ts">
	// Descargar una ficha en el formato oficial FR-1703-SMD-69.
	//
	// El listado no trae los datos completos —solo el resumen de cada fila—, así
	// que al pulsar se pide el detalle y con él se arma el PDF. Es una petición
	// más, pero evita cargar de golpe las personas, el agropecuario y el historial
	// de cada una de las fichas del listado solo por si alguien descarga una.
	//
	// La librería de PDF pesa, así que entra por importación dinámica: quien no
	// descargue ninguna ficha no la carga nunca.

	import { Download, Eye, LoaderCircle, TriangleAlert } from '@lucide/svelte';
	import { rufeApi } from '$lib/api/servicios';
	import { ApiError } from '$lib/api/client';
	import { nombreArchivo } from '$lib/ficha-pdf/texto';
	import VisorPdf from './VisorPdf.svelte';

	let { id, radicado }: { id: number; radicado: string } = $props();

	let generando = $state<'descarga' | 'vista' | null>(null);
	let error = $state<string | null>(null);
	let viendo = $state<string | null>(null);

	/** El PDF, armado a partir del detalle. Lo comparten ver y descargar. */
	async function construir(): Promise<string> {
		const [detalle, { generarFichaPdf }] = await Promise.all([
			rufeApi.ver(id),
			import('$lib/ficha-pdf/generar')
		]);

		return URL.createObjectURL(await generarFichaPdf(detalle));
	}

	async function descargar() {
		if (generando) return;

		generando = 'descarga';
		error = null;

		try {
			const url = await construir();

			const enlace = document.createElement('a');
			enlace.href = url;
			enlace.download = nombreArchivo(radicado);
			enlace.click();

			// Sin revocar, el navegador conserva el PDF entero en memoria hasta
			// recargar la página. Descargando varias fichas seguidas se nota.
			URL.revokeObjectURL(url);
		} catch (e) {
			error =
				e instanceof ApiError ? e.message : 'No se pudo generar la ficha. Intente de nuevo.';
		} finally {
			generando = null;
		}
	}

	/**
	 * Verla sin descargarla.
	 *
	 * Quien revisa la bandeja abre muchas para mirar una cosa concreta, y
	 * descargarlas todas le deja la carpeta llena de fichas con datos de familias
	 * damnificadas, que ahí se quedan hasta que alguien se acuerde de borrarlas.
	 */
	async function ver() {
		if (generando) return;

		generando = 'vista';
		error = null;

		try {
			viendo = await construir();
		} catch (e) {
			error =
				e instanceof ApiError ? e.message : 'No se pudo generar la ficha. Intente de nuevo.';
		} finally {
			generando = null;
		}
	}

	/**
	 * Al cerrar el visor se libera el PDF.
	 *
	 * ⚠ No se puede revocar antes, como sí hace la descarga: el `iframe` lo sigue
	 * necesitando mientras esté en pantalla. Revocarlo al abrir deja el visor en
	 * blanco.
	 */
	function cerrarVisor() {
		if (viendo) URL.revokeObjectURL(viendo);
		viendo = null;
	}
</script>

<span class="ficha-pdf__grupo">
	<!--
		Ver va primero: es lo que se hace más veces. Descargar es el gesto de
		quien ya decidió quedarse el documento.
	-->
	<button
		type="button"
		class="boton boton--suave ficha-pdf"
		onclick={ver}
		disabled={generando !== null}
		title="Ver {radicado} sin descargarla"
		aria-label="Ver la ficha {radicado} en el formato oficial"
	>
		{#if generando === 'vista'}
			<LoaderCircle size={14} class="girando" aria-hidden="true" />
			Abriendo…
		{:else}
			<Eye size={14} aria-hidden="true" />
			Ver
		{/if}
	</button>

	<button
		type="button"
		class="boton boton--suave ficha-pdf"
		onclick={descargar}
		disabled={generando !== null}
		title="Descargar {radicado} en el formato oficial FR-1703-SMD-69"
		aria-label="Descargar la ficha {radicado} en el formato oficial"
	>
		{#if generando === 'descarga'}
			<LoaderCircle size={14} class="girando" aria-hidden="true" />
			Generando…
		{:else}
			<Download size={14} aria-hidden="true" />
			PDF
		{/if}
	</button>
</span>

{#if viendo}
	<VisorPdf
		url={viendo}
		titulo="Ficha {radicado} · formato FR-1703-SMD-69"
		nombre={nombreArchivo(radicado)}
		onCerrar={cerrarVisor}
	/>
{/if}

{#if error}
	<span class="ficha-pdf__error" role="alert">
		<TriangleAlert size={13} aria-hidden="true" />
		{error}
	</span>
{/if}

<style>
	.ficha-pdf {
		white-space: nowrap;
	}

	.ficha-pdf__grupo {
		display: inline-flex;
		gap: 0.3rem;
		flex-wrap: wrap;
	}

	.ficha-pdf__error {
		display: inline-flex;
		align-items: center;
		gap: 0.25rem;
		margin-top: 0.25rem;
		font-size: 0.72rem;
		color: var(--aviso-error-texto);
		overflow-wrap: anywhere;
	}
</style>
