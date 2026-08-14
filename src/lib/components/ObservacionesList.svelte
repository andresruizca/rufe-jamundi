<script lang="ts">
	import type { ObservacionItem } from '$lib/hogaresAggregate';

	const VISIBLE_CAP = 30;

	let { items }: { items: ObservacionItem[] } = $props();
	const visible = $derived(items.slice(0, VISIBLE_CAP));
</script>

{#if items.length === 0}
	<p class="empty">Sin observaciones registradas para este filtro.</p>
{:else}
	<ul class="obs-list">
		{#each visible as item (item.hogar + item.texto)}
			<li>
				<span
					class="badge"
					class:urbana={item.zona === 'Urbana'}
					class:rural={item.zona === 'Rural'}>{item.barrio}</span
				>
				<span class="texto">{item.texto}</span>
			</li>
		{/each}
	</ul>
	{#if items.length > VISIBLE_CAP}
		<p class="more">
			Mostrando {VISIBLE_CAP} de {items.length} — usa el filtro de zona o el buscador de barrio para ver
			un grupo más específico.
		</p>
	{/if}
{/if}

<style>
	.empty {
		font-size: 12.5px;
		color: var(--color-muted);
		margin: 0;
	}
	.obs-list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
		max-height: 360px;
		overflow-y: auto;
	}
	.obs-list li {
		display: flex;
		gap: 8px;
		align-items: baseline;
		font-size: 12.5px;
		line-height: 1.45;
		padding-bottom: 8px;
		border-bottom: 1px solid var(--color-border);
	}
	.obs-list li:last-child {
		border-bottom: 0;
		padding-bottom: 0;
	}
	.badge {
		flex: none;
		font-size: 10.5px;
		font-weight: 700;
		padding: 2px 7px;
		border-radius: var(--radius-full);
		letter-spacing: 0.02em;
		white-space: nowrap;
	}
	.badge.urbana {
		background: color-mix(in srgb, var(--series-mujeres) 16%, transparent);
		color: var(--series-mujeres);
	}
	.badge.rural {
		background: color-mix(in srgb, var(--series-hombres) 18%, transparent);
		color: var(--series-hombres);
	}
	.texto {
		color: var(--color-text);
	}
	.more {
		margin: 10px 0 0;
		font-size: 11.5px;
		color: var(--color-muted);
	}
</style>
