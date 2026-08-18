<script lang="ts">
	// Las fichas levantadas que todavía no llegaron a la Alcaldía.
	//
	// No es un adorno. Un sistema que envía solo, pero no muestra qué debe,
	// genera una desconfianza justificada: el censador acaba anotando en papel
	// «por si acaso», que es exactamente lo que este formulario vino a evitar.
	// Verlas listadas, con su dirección y su hora, es lo que permite cerrar la
	// jornada sabiendo que no quedó nada suelto.

	import { CloudOff, LoaderCircle, RefreshCw, TriangleAlert } from '@lucide/svelte';
	import { fichasPendientes, type FichaEnCola } from '../cola';

	type Props = {
		/** Cambia cuando la cola se mueve, para volver a leerla. */
		version?: number;
		enLinea?: boolean;
		onReintentar?: () => void;
	};

	let { version = 0, enLinea = true, onReintentar }: Props = $props();

	let fichas = $state<FichaEnCola[]>([]);

	$effect(() => {
		// Se relee con cada cambio de `version`: la cola la mueven el Service
		// Worker y el gestor de envío, no este componente.
		void version;
		void fichasPendientes().then((f) => (fichas = f));
	});

	function hora(ms: number): string {
		return new Date(ms).toLocaleString('es-CO', {
			day: '2-digit',
			month: 'short',
			hour: '2-digit',
			minute: '2-digit'
		});
	}
</script>

{#if fichas.length > 0}
	<section class="pendientes" aria-label="Fichas pendientes de enviar">
		<header class="pendientes__cabecera">
			<span class="pendientes__titulo">
				<CloudOff size={15} aria-hidden="true" />
				{fichas.length === 1 ? '1 ficha sin enviar' : `${fichas.length} fichas sin enviar`}
			</span>

			{#if onReintentar}
				<button type="button" class="boton boton--suave" onclick={onReintentar} disabled={!enLinea}>
					<RefreshCw size={14} aria-hidden="true" />
					Intentar ahora
				</button>
			{/if}
		</header>

		<ul class="pendientes__lista">
			{#each fichas as ficha (ficha.envioId)}
				<li class="pendiente">
					<span class="pendiente__datos">
						<span class="pendiente__direccion">{ficha.resumen.direccion}</span>
						<span class="pendiente__meta">
							{ficha.resumen.evento} · {ficha.resumen.personas}
							{ficha.resumen.personas === 1 ? 'persona' : 'personas'} · {hora(ficha.creadoEn)}
						</span>

						{#if ficha.error}
							<span class="pendiente__error">
								<TriangleAlert size={12} aria-hidden="true" />
								{ficha.error}
							</span>
						{/if}
					</span>

					{#if ficha.estado === 'enviando'}
						<LoaderCircle size={15} class="girando" aria-hidden="true" />
					{/if}
				</li>
			{/each}
		</ul>

		<p class="pendientes__nota">
			Están guardadas en este teléfono. No cierre sesión ni borre los datos del navegador hasta
			que se hayan enviado.
		</p>
	</section>
{/if}

<style>
	.pendientes {
		margin-bottom: 1.2rem;
		padding: 0.85rem;
		border: 1px solid var(--aviso-info-borde);
		border-radius: 12px;
		background: var(--color-info-bg);
	}

	.pendientes__cabecera {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.5rem;
		flex-wrap: wrap;
		margin-bottom: 0.6rem;
	}

	.pendientes__titulo {
		display: flex;
		align-items: center;
		gap: 0.35rem;
		font-size: 0.86rem;
		font-weight: 700;
		color: var(--color-primary-dark);
	}

	.pendientes__lista {
		list-style: none;
		margin: 0 0 0.6rem;
		padding: 0;
		display: grid;
		gap: 0.4rem;
	}

	.pendiente {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		padding: 0.5rem 0.6rem;
		border-radius: 8px;
		background: var(--color-surface);
	}

	.pendiente__datos {
		flex: 1 1 8rem;
		min-width: 0;
	}

	.pendiente__direccion {
		display: block;
		font-size: 0.84rem;
		font-weight: 600;
		overflow-wrap: anywhere;
	}

	.pendiente__meta {
		display: block;
		font-size: 0.74rem;
		color: var(--color-muted);
		overflow-wrap: anywhere;
	}

	.pendiente__error {
		display: flex;
		align-items: flex-start;
		gap: 0.25rem;
		flex-wrap: wrap;
		margin-top: 0.2rem;
		font-size: 0.72rem;
		color: var(--color-warning);
		overflow-wrap: anywhere;
	}

	.pendientes__nota {
		margin: 0;
		font-size: 0.75rem;
		color: var(--color-muted);
	}
</style>
