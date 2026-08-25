<script lang="ts">
	// ¿Puede este aparato levantar fichas en una vereda sin señal?
	//
	// Vivía en la pantalla «Pendientes», que se quitó por colgar del menú sin
	// relación con nada. La pregunta, en cambio, sí importa, y se hace en un
	// momento concreto: antes de salir a campo, con el formulario delante.
	//
	// SOLO habla cuando falta algo. Un recuadro verde diciendo «todo listo» cada
	// vez que se abre el formulario es un cartel: quien lo lee todos los días deja
	// de leerlo, y entonces tampoco lo lee el día que dice que falta algo. El
	// silencio es la señal de que va bien.

	import { onMount } from 'svelte';
	import { DownloadCloud, LoaderCircle, TriangleAlert } from '@lucide/svelte';
	import { preparacion } from '$lib/offline/estado.svelte';
	import { aparato } from '$lib/aparato';

	const cual = aparato();

	let enLinea = $state(true);

	onMount(() => {
		enLinea = navigator.onLine;

		// Se comprueba al entrar y no solo al pulsar un botón: quien abre el
		// formulario está a punto de usarlo, y ese es el momento de saberlo.
		void preparacion.ejecutar();

		const conectar = () => (enLinea = true);
		const desconectar = () => (enLinea = false);

		window.addEventListener('online', conectar);
		window.addEventListener('offline', desconectar);

		return () => {
			window.removeEventListener('online', conectar);
			window.removeEventListener('offline', desconectar);
		};
	});
</script>

{#if preparacion.parte && !preparacion.parte.listo}
	<p class="falta" role="status">
		<TriangleAlert size={15} aria-hidden="true" />
		<span>
			<strong>Falta descargar {preparacion.parte.faltantes.join(', ')}</strong> para poder
			trabajar sin señal. Con internet, pulse el botón.
		</span>
		<button
			type="button"
			class="boton boton--suave"
			onclick={() => preparacion.ejecutar()}
			disabled={!enLinea || preparacion.trabajando}
		>
			{#if preparacion.trabajando}
				<LoaderCircle size={14} class="girando" aria-hidden="true" />
				Descargando…
			{:else}
				<DownloadCloud size={14} aria-hidden="true" />
				Preparar {cual.este}
			{/if}
		</button>
	</p>
{/if}

<style>
	.falta {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 0.5rem;
		margin: 0.9rem 0 0;
		padding: 0.7rem 0.8rem;
		border: 1px solid var(--aviso-alerta-borde);
		border-radius: 10px;
		background: var(--aviso-alerta-fondo);
		color: var(--aviso-alerta-texto);
		font-size: 0.82rem;
		line-height: 1.45;
	}

	.falta span {
		flex: 1 1 14rem;
		min-width: 0;
	}
</style>
