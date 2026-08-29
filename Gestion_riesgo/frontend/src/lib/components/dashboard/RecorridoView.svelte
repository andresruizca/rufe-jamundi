<script lang="ts">
	// El camino de una familia damnificada, de punta a punta.
	//
	// ── Por qué esto va primero en el tablero ────────────────────────────────
	//
	// Porque es lo que le preguntan a la Alcaldía: cuánta gente está esperando,
	// y en qué punto. El tablero anterior respondía con detalle a otra pregunta
	// —quiénes son los damnificados, por género y por edad— que también importa,
	// pero que no dice si el sistema está avanzando o parado.
	//
	// Las cifras de las cinco etapas se calculan en el servidor con el MISMO
	// cruce que usa el call center (`App\Riesgo\Recorrido`). Sin eso, esta
	// pantalla y aquella dirían números distintos sobre la misma gente, las dos
	// con aire de verdad.

	import { ArrowRight } from '@lucide/svelte';
	import { pasos } from '$lib/tablero/recorrido';
	import type { EtapaRecorrido } from '$lib/rufe/types';

	let { etapas = [], personas = 0 }: { etapas?: EtapaRecorrido[]; personas?: number } = $props();

	const camino = $derived(pasos(etapas));
	const fmt = (n: number) => n.toLocaleString('es-CO');
</script>

{#if camino.length > 0}
	<section class="recorrido" aria-label="El recorrido de una familia damnificada">
		<div class="recorrido__cabeza">
			<h2>El recorrido</h2>
			<p>
				Dónde está cada familia entre el censo y la ayuda. La caída se mide contra la etapa
				anterior, no contra el censo: es lo que dice dónde intervenir.
			</p>
		</div>

		<ol class="etapas">
			{#each camino as paso, i (paso.etapa.clave)}
				{#if i > 0}
					<li class="salto" aria-hidden="true">
						<ArrowRight size={15} />
						{#if paso.caida !== null}
							<span class="salto__caida">−{paso.caida}%</span>
						{:else if paso.crece}
							<!-- Alguien se preinscribió por su cuenta, sin que lo llamaran. No
							     es una fuga, y pintarle un menos delante diría lo contrario. -->
							<span class="salto__crece">sube</span>
						{/if}
					</li>
				{/if}

				<li class="etapa" class:etapa--fin={i === camino.length - 1}>
					<span class="etapa__n">{String(i + 1).padStart(2, '0')}</span>
					<span class="etapa__nombre">{paso.etapa.nombre}</span>
					<span class="etapa__valor">{fmt(paso.etapa.hogares)}</span>
					<span class="etapa__pie">
						{#if i === 0}
							{paso.etapa.pie}{#if personas > 0} · {fmt(personas)} personas{/if}
						{:else if paso.delCenso !== null}
							{paso.delCenso}% del censo
						{:else}
							{paso.etapa.pie}
						{/if}
					</span>
				</li>
			{/each}
		</ol>
	</section>
{/if}

<style>
	.recorrido {
		background: var(--color-surface, #141b23);
		border: 1px solid var(--color-border, #2a3441);
		border-radius: var(--radius-lg, 12px);
		padding: 1rem 1.1rem 1.15rem;
	}

	.recorrido__cabeza h2 {
		margin: 0 0 0.2rem;
		font-size: 1rem;
		font-weight: 700;
	}

	.recorrido__cabeza p {
		margin: 0 0 0.9rem;
		font-size: 0.82rem;
		line-height: 1.45;
		color: var(--color-muted);
		max-width: 46rem;
	}

	.etapas {
		display: flex;
		align-items: stretch;
		flex-wrap: wrap;
		gap: 0.4rem;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	.etapa {
		flex: 1 1 8.5rem;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 0.15rem;
		padding: 0.6rem 0.7rem;
		border: 1px solid var(--color-border);
		border-radius: 9px;
		background: var(--color-surface-alt, rgb(255 255 255 / 0.02));
	}

	.etapa__n {
		font-size: 0.65rem;
		font-variant-numeric: tabular-nums;
		color: var(--color-muted);
	}

	.etapa__nombre {
		font-size: 0.76rem;
		font-weight: 600;
		color: var(--color-muted);
	}

	.etapa__valor {
		font-size: clamp(1.25rem, 4vw, 1.6rem);
		font-weight: 800;
		letter-spacing: -0.02em;
		font-variant-numeric: tabular-nums;
	}

	.etapa--fin .etapa__valor {
		color: var(--status-good, #3fbf87);
	}

	.etapa__pie {
		font-size: 0.68rem;
		color: var(--color-muted);
	}

	.salto {
		flex: 0 0 3rem;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 0.1rem;
		color: var(--color-muted);
		font-size: 0.65rem;
		font-variant-numeric: tabular-nums;
	}

	.salto__caida {
		color: var(--status-critical, #e2705f);
	}

	.salto__crece {
		color: var(--status-good, #3fbf87);
	}

	/* En un celular las cinco etapas no caben seguidas. La flecha se pone de
	   lado para que la fila siguiente no empiece con un símbolo suelto que no
	   se sabe a qué apunta. */
	@media (max-width: 700px) {
		.salto {
			flex-basis: 100%;
			flex-direction: row;
			gap: 0.35rem;
		}
	}
</style>
