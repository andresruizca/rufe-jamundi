<script lang="ts">
	// Descargar una inspección en el formato oficial de la NGRD.
	//
	// Mismo patrón que el botón del RUFE: el listado no trae los datos completos,
	// así que al pulsar se pide el detalle y con él se arma el PDF. Es una
	// petición más, pero evita cargar la evaluación, el historial y las fotos de
	// todas las filas del listado solo por si alguien descarga una.
	//
	// La librería de PDF pesa, así que entra por importación dinámica: quien no
	// descargue ninguna inspección no la carga nunca.

	import { Download, Eye, LoaderCircle, TriangleAlert } from '@lucide/svelte';
	import { inspeccionApi } from '$lib/api/servicios';
	import { ApiError } from '$lib/api/client';
	import VisorPdf from './VisorPdf.svelte';

	let { id, numero }: { id: number; numero: string } = $props();

	let generando = $state<'descarga' | 'vista' | null>(null);
	let error = $state<string | null>(null);
	let viendo = $state<string | null>(null);
	let nombre = $state('');

	/** El PDF, armado a partir del detalle. Lo comparten ver y descargar. */
	async function construir(): Promise<{ url: string; nombre: string }> {
		const [detalle, { generarInspeccionPdf, nombreArchivo }] = await Promise.all([
			inspeccionApi.ver(id),
			import('$lib/inspeccion-pdf/generar')
		]);

		return {
			url: URL.createObjectURL(await generarInspeccionPdf(detalle)),
			nombre: nombreArchivo(numero)
		};
	}

	/**
	 * Verla sin descargarla.
	 *
	 * Quien revisa la bandeja abre muchas para mirar una cosa concreta —si el
	 * combo quedó bien, si la firma está—, y descargarlas todas le deja la
	 * carpeta llena de formatos con datos de familias damnificadas.
	 */
	async function ver() {
		if (generando) return;

		generando = 'vista';
		error = null;

		try {
			const pdf = await construir();
			nombre = pdf.nombre;
			viendo = pdf.url;
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudo generar el formato. Intente de nuevo.';
		} finally {
			generando = null;
		}
	}

	/**
	 * Al cerrar el visor se libera el PDF.
	 *
	 * ⚠ No antes, como sí hace la descarga: el `iframe` lo sigue necesitando
	 * mientras esté en pantalla, y revocarlo al abrir lo deja en blanco.
	 */
	function cerrarVisor() {
		if (viendo) URL.revokeObjectURL(viendo);
		viendo = null;
	}

	async function descargar() {
		if (generando) return;

		generando = 'descarga';
		error = null;

		try {
			const { url, nombre: comoSeLlama } = await construir();

			const enlace = document.createElement('a');
			enlace.href = url;
			enlace.download = comoSeLlama;
			enlace.click();

			// Sin revocar, el navegador conserva el PDF entero en memoria hasta
			// recargar la página. Descargando varias seguidas se nota.
			URL.revokeObjectURL(url);
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudo generar el formato. Intente de nuevo.';
		} finally {
			generando = null;
		}
	}
</script>

<span class="inspeccion-pdf__grupo">
	<!-- Ver va primero: es lo que se hace más veces. -->
	<button
		type="button"
		class="boton boton--suave inspeccion-pdf"
		onclick={ver}
		disabled={generando !== null}
		title="Ver {numero} sin descargarla"
		aria-label="Ver la inspección {numero} en el formato oficial"
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
		class="boton boton--suave inspeccion-pdf"
		onclick={descargar}
		disabled={generando !== null}
		title="Descargar {numero} en el formato oficial de la NGRD"
		aria-label="Descargar la inspección {numero} en el formato oficial"
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
		titulo="Inspección {numero} · formato oficial NGRD"
		{nombre}
		onCerrar={cerrarVisor}
	/>
{/if}

{#if error}
	<span class="inspeccion-pdf__error" role="alert">
		<TriangleAlert size={13} aria-hidden="true" />
		{error}
	</span>
{/if}

<style>
	.inspeccion-pdf {
		white-space: nowrap;
	}

	.inspeccion-pdf__grupo {
		display: inline-flex;
		gap: 0.3rem;
		flex-wrap: wrap;
	}

	.inspeccion-pdf__error {
		display: inline-flex;
		align-items: center;
		gap: 0.25rem;
		margin-top: 0.25rem;
		font-size: 0.72rem;
		color: var(--aviso-error-texto);
		overflow-wrap: anywhere;
	}
</style>
