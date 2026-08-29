<script lang="ts">
	// Dónde está parado el proceso ahora mismo.
	//
	// No son cifras para mirar: son trabajo pendiente. Por eso cada una es un
	// enlace a la pantalla donde se resuelve —la ruta la decide el servidor
	// junto con la cifra—. Un atasco sin sitio a donde ir es una alarma que
	// suena y no dice dónde, y con tres operadoras y un ingeniero eso acaba en
	// que no lo atiende nadie.

	import { ArrowUpRight } from '@lucide/svelte';
	import { base } from '$app/paths';
	import type { Atasco } from '$lib/rufe/types';

	let { atascos = [] }: { atascos?: Atasco[] } = $props();

	// Los que están en cero se quedan, y a propósito: «cero solicitudes
	// demoradas» es una respuesta, y esconderla dejaría a quien mira sin saber
	// si es que no hay o es que no se está midiendo.
	const fmt = (n: number) => n.toLocaleString('es-CO');
</script>

{#if atascos.length > 0}
	<section class="atascos" aria-label="Dónde está parado el proceso">
		<div class="atascos__cabeza">
			<h2>Dónde se atascó</h2>
			<p>Cada una lleva a la pantalla donde se resuelve.</p>
		</div>

		<div class="rejilla">
			{#each atascos as a (a.clave)}
				<a
					class="atasco"
					class:atasco--critico={a.nivel === 'critico' && a.valor > 0}
					class:atasco--aviso={a.nivel === 'aviso' && a.valor > 0}
					href="{base}{a.ruta}"
				>
					<span class="atasco__nombre">
						{a.nombre}
						<ArrowUpRight size={13} aria-hidden="true" />
					</span>
					<span class="atasco__valor">{fmt(a.valor)}</span>
					<span class="atasco__pie">{a.pie}</span>
				</a>
			{/each}
		</div>
	</section>
{/if}

<style>
	.atascos {
		background: var(--color-surface, #141b23);
		border: 1px solid var(--color-border, #2a3441);
		border-radius: var(--radius-lg, 12px);
		padding: 1rem 1.1rem 1.15rem;
	}

	.atascos__cabeza h2 {
		margin: 0 0 0.2rem;
		font-size: 1rem;
		font-weight: 700;
	}

	.atascos__cabeza p {
		margin: 0 0 0.9rem;
		font-size: 0.82rem;
		color: var(--color-muted);
	}

	.rejilla {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr));
		gap: 0.5rem;
	}

	.atasco {
		display: flex;
		flex-direction: column;
		gap: 0.12rem;
		padding: 0.6rem 0.7rem;
		border: 1px solid var(--color-border);
		border-radius: 9px;
		background: var(--color-surface-alt, rgb(255 255 255 / 0.02));
		color: inherit;
		text-decoration: none;
		transition: border-color 0.12s ease;
	}

	.atasco:hover,
	.atasco:focus-visible {
		border-color: var(--color-primary, #4d9bf0);
	}

	.atasco__nombre {
		display: flex;
		align-items: center;
		gap: 0.3rem;
		font-size: 0.75rem;
		font-weight: 600;
		color: var(--color-muted);
	}

	.atasco__valor {
		font-size: 1.35rem;
		font-weight: 800;
		font-variant-numeric: tabular-nums;
		letter-spacing: -0.02em;
	}

	.atasco__pie {
		font-size: 0.68rem;
		color: var(--color-muted);
	}

	/* El color solo cuando hay algo que atender. Un cero pintado de rojo enseña
	   a la gente a ignorar el rojo, y entonces el día que importe no lo verá
	   nadie. */
	.atasco--critico {
		border-color: color-mix(in srgb, var(--status-critical, #e2705f) 45%, transparent);
		background: color-mix(in srgb, var(--status-critical, #e2705f) 7%, transparent);
	}

	.atasco--critico .atasco__valor {
		color: var(--status-critical, #e2705f);
	}

	.atasco--aviso {
		border-color: color-mix(in srgb, var(--status-warning, #e0a53c) 40%, transparent);
		background: color-mix(in srgb, var(--status-warning, #e0a53c) 6%, transparent);
	}

	.atasco--aviso .atasco__valor {
		color: var(--status-warning, #e0a53c);
	}
</style>
