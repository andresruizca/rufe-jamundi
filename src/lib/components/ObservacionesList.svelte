<script lang="ts">
	import type { ObservacionItem } from '$lib/hogaresAggregate';

	const CAP = 30;
	const CAP_CRITICAL_ONLY = 80;

	let { items }: { items: ObservacionItem[] } = $props();

	let onlyCritical = $state(false);

	const criticalCount = $derived(items.filter((i) => i.critical).length);
	const source = $derived(onlyCritical ? items.filter((i) => i.critical) : items);
	const cap = $derived(onlyCritical ? CAP_CRITICAL_ONLY : CAP);
	const visible = $derived(source.slice(0, cap));
</script>

{#if items.length === 0}
	<p class="empty">Sin observaciones registradas para este filtro.</p>
{:else}
	{#if criticalCount > 0}
		<label class="critical-toggle">
			<input type="checkbox" bind:checked={onlyCritical} />
			Mostrar solo críticas ({criticalCount})
		</label>
	{/if}
	<ul class="obs-list">
		{#each visible as item (item.hogar + item.texto)}
			<li class:critical={item.critical}>
				<div class="tags">
					<span class="hogar-code" title="Código de hogar / familia en el RUFE">
						Hogar #{item.hogar || '—'}
					</span>
					<span
						class="badge"
						class:urbana={item.zona === 'Urbana'}
						class:rural={item.zona === 'Rural'}>{item.barrio}</span
					>
					{#if item.critical}
						<span class="critical-badge">Crítica</span>
					{/if}
				</div>
				<span class="texto">{item.texto}</span>
			</li>
		{/each}
	</ul>
	{#if source.length > cap}
		<p class="more">
			Mostrando {cap} de {source.length}{onlyCritical ? ' críticas' : ''} — usa el filtro de zona o el
			buscador de barrio para ver un grupo más específico.
		</p>
	{/if}
{/if}

<style>
	.empty {
		font-size: 12.5px;
		color: var(--color-muted);
		margin: 0;
	}
	.critical-toggle {
		display: flex;
		align-items: center;
		gap: 7px;
		font-size: 12px;
		font-weight: 600;
		color: var(--color-text);
		margin: 0 0 12px;
		cursor: pointer;
		width: fit-content;
	}
	.critical-toggle input {
		accent-color: var(--status-critical);
		width: 15px;
		height: 15px;
		cursor: pointer;
	}
	.obs-list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
		max-height: 420px;
		overflow-y: auto;
	}
	.obs-list li {
		display: flex;
		flex-direction: column;
		gap: 4px;
		font-size: 12.5px;
		line-height: 1.45;
		padding: 7px 8px 8px;
		border-bottom: 1px solid var(--color-border);
		border-radius: var(--radius-sm);
	}
	.obs-list li:last-child {
		border-bottom: 0;
		padding-bottom: 0;
	}
	.obs-list li.critical {
		background: var(--color-danger-bg);
		border-bottom-color: transparent;
		box-shadow: inset 3px 0 0 var(--status-critical);
	}
	.tags {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}
	.hogar-code {
		flex: none;
		font-size: 10.5px;
		font-weight: 700;
		color: var(--color-muted);
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
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
	.critical-badge {
		flex: none;
		font-size: 10.5px;
		font-weight: 800;
		padding: 2px 7px;
		border-radius: var(--radius-full);
		letter-spacing: 0.02em;
		white-space: nowrap;
		background: color-mix(in srgb, var(--status-critical) 20%, transparent);
		color: var(--status-critical);
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
