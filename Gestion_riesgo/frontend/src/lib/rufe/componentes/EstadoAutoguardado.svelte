<script lang="ts">
	// Aviso permanente de dónde está guardado el reporte.
	//
	// Se dice explícitamente "en este dispositivo" porque es la verdad y porque
	// cambia lo que el ciudadano debe esperar: si limpia el navegador o cambia de
	// teléfono, el borrador no le sigue.

	import { Check, CloudOff, LoaderCircle, TriangleAlert, RotateCcw } from '@lucide/svelte';
	import { describirEstado, type EstadoGuardado } from '../borrador.svelte';

	type Props = { estado: EstadoGuardado; guardadoEn: number | null; enLinea: boolean };

	let { estado, guardadoEn, enLinea }: Props = $props();

	const texto = $derived(describirEstado(estado, guardadoEn));
</script>

<p class="estado" class:estado--error={estado === 'error'} role="status" aria-live="polite">
	{#if !enLinea}
		<CloudOff size={14} aria-hidden="true" />
		Sin conexión. Su reporte está guardado en este dispositivo.
	{:else if estado === 'guardando'}
		<LoaderCircle size={14} class="girando" aria-hidden="true" />
		{texto}
	{:else if estado === 'error'}
		<TriangleAlert size={14} aria-hidden="true" />
		{texto}
	{:else if estado === 'recuperado'}
		<RotateCcw size={14} aria-hidden="true" />
		{texto}
	{:else if estado === 'guardado'}
		<Check size={14} aria-hidden="true" />
		{texto}
	{:else}
		{texto}
	{/if}
</p>

<style>
	.estado {
		display: flex;
		align-items: center;
		gap: 0.35rem;
		margin: 0 0 0.9rem;
		font-size: 0.78rem;
		color: var(--color-muted);
		min-height: 1.2rem;
	}

	.estado--error {
		color: var(--color-danger);
	}
</style>
