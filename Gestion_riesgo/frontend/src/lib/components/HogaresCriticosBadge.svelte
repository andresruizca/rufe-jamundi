<script lang="ts">
	import { TriangleAlert, X } from '@lucide/svelte';
	import { criticalSeverity, type ObservacionItem } from '$lib/hogaresAggregate';

	/** `top`: ya viene filtrado a "críticos sin evacuar" y recortado a los
	 * primeros 20 por quien llama — este componente solo los muestra. `total`
	 * es el conteo real (puede ser mayor a 20) para el encabezado del panel. */
	let { top, total }: { top: ObservacionItem[]; total: number } = $props();

	let open = $state(false);
	let wrapEl: HTMLDivElement | undefined = $state();

	// El click SIEMPRE abre (nunca hace toggle): así no compite con el hover
	// en desktop (mover el mouse ya dispara mouseenter=open antes del click,
	// así que un toggle podía terminar cerrando en vez de abrir). Cerrar
	// queda a cargo de mouseleave (desktop), click afuera (touch, sin hover),
	// Escape, o el botón "×" del panel.
	function abrir() {
		open = true;
	}
	function cerrar() {
		open = false;
	}
	function onKeydown(e: KeyboardEvent) {
		// A nivel window (no del panel) porque el foco del teclado se queda en
		// el botón trigger al abrir con click — un handler solo dentro del
		// panel nunca recibiría el Escape ya que el botón no es descendiente
		// del panel (son hermanos).
		if (open && e.key === 'Escape') cerrar();
	}
	function onClickFuera(e: MouseEvent) {
		if (open && wrapEl && !wrapEl.contains(e.target as Node)) cerrar();
	}
</script>

<svelte:window onclick={onClickFuera} onkeydown={onKeydown} />

{#if total > 0}
	<!-- svelte-ignore a11y_no_static_element_interactions -->
	<div class="critico-wrap" bind:this={wrapEl} onmouseenter={abrir} onmouseleave={cerrar}>
		<button
			type="button"
			class="critico-trigger"
			aria-expanded={open}
			aria-haspopup="dialog"
			onclick={abrir}
		>
			<TriangleAlert size={18} strokeWidth={2.5} aria-hidden="true" />
			<span class="critico-value">{total.toLocaleString('es-CO')}</span>
			<span class="critico-label">hogares críticos<br />según observaciones</span>
		</button>

		{#if open}
			<div class="critico-panel" role="dialog" aria-label="Hogares más críticos" tabindex="-1">
				<div class="critico-panel-head">
					<TriangleAlert size={14} strokeWidth={2.5} aria-hidden="true" />
					Top {top.length} más críticos ahora
					{#if total > top.length}<span class="dim">de {total} en total</span>{/if}
					<button type="button" class="critico-close" onclick={cerrar} aria-label="Cerrar">
						<X size={14} strokeWidth={2.5} aria-hidden="true" />
					</button>
				</div>
				<ul class="critico-list">
					{#each top as item (item.hogar + item.texto)}
						<li>
							<div class="critico-row-head">
								<span class="hogar-code">Hogar #{item.hogar || '—'}</span>
								<span
									class="zbadge"
									class:urbana={item.zona === 'Urbana'}
									class:rural={item.zona === 'Rural'}>{item.barrio}</span
								>
								<span class="personas">{item.personas} pers.</span>
								{#if item.evacuada === 'SI'}
									<span class="evac-tag">Ya evacuado</span>
								{/if}
								<span class="severidad" title="{criticalSeverity(item.texto)} señales de peligro">
									{#each { length: criticalSeverity(item.texto) } as _}
										<TriangleAlert size={10} strokeWidth={3} aria-hidden="true" />
									{/each}
								</span>
							</div>
							<span class="texto">{item.texto}</span>
						</li>
					{/each}
				</ul>
			</div>
		{/if}
	</div>
{/if}

<style>
	.critico-wrap {
		position: relative;
		flex: none;
	}
	.critico-trigger {
		display: flex;
		align-items: center;
		gap: 8px;
		height: 100%;
		background: var(--color-danger-bg);
		border: 1px solid color-mix(in srgb, var(--status-critical) 45%, transparent);
		color: var(--status-critical);
		border-radius: var(--radius-lg);
		padding: 14px 16px;
		cursor: pointer;
		font: inherit;
		text-align: left;
		box-shadow: var(--shadow);
	}
	.critico-trigger:hover,
	.critico-trigger:focus-visible {
		background: color-mix(in srgb, var(--status-critical) 16%, var(--color-danger-bg));
		outline: 2px solid var(--status-critical);
		outline-offset: 2px;
	}
	.critico-value {
		font-size: clamp(22px, 6vw, 28px);
		font-weight: 800;
		letter-spacing: -0.01em;
		font-variant-numeric: tabular-nums;
	}
	.critico-label {
		font-size: 11px;
		font-weight: 700;
		line-height: 1.25;
		letter-spacing: 0.01em;
	}

	.critico-panel {
		position: absolute;
		z-index: 30;
		top: calc(100% + 8px);
		right: 0;
		width: min(380px, 88vw);
		max-height: 70vh;
		overflow-y: auto;
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		border-radius: var(--radius-lg);
		box-shadow: var(--shadow-lg, var(--shadow));
		padding: 10px;
	}
	@media (max-width: 480px) {
		.critico-panel {
			right: auto;
			left: 0;
			width: min(380px, 92vw);
		}
	}
	.critico-panel-head {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 11.5px;
		font-weight: 700;
		color: var(--status-critical);
		text-transform: uppercase;
		letter-spacing: 0.03em;
		padding: 4px 4px 8px;
	}
	.critico-close {
		margin-left: auto;
		flex: none;
		display: flex;
		align-items: center;
		justify-content: center;
		width: 22px;
		height: 22px;
		border: 0;
		border-radius: var(--radius-sm);
		background: transparent;
		color: var(--color-muted);
		cursor: pointer;
	}
	.critico-close:hover,
	.critico-close:focus-visible {
		background: var(--color-border);
		color: var(--color-text);
	}
	.critico-panel-head .dim {
		color: var(--color-muted);
		font-weight: 600;
		text-transform: none;
		letter-spacing: normal;
	}

	.critico-list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.critico-list li {
		display: flex;
		flex-direction: column;
		gap: 4px;
		font-size: 12px;
		line-height: 1.4;
		padding: 7px 8px;
		border-radius: var(--radius-sm);
		background: var(--color-danger-bg);
		box-shadow: inset 3px 0 0 var(--status-critical);
	}
	.critico-row-head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}
	.hogar-code {
		flex: none;
		font-size: 10px;
		font-weight: 700;
		color: var(--color-muted);
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}
	.zbadge {
		flex: none;
		font-size: 10px;
		font-weight: 700;
		padding: 2px 6px;
		border-radius: var(--radius-full);
		white-space: nowrap;
	}
	.zbadge.urbana {
		background: color-mix(in srgb, var(--series-mujeres) 16%, transparent);
		color: var(--series-mujeres);
	}
	.zbadge.rural {
		background: color-mix(in srgb, var(--series-hombres) 18%, transparent);
		color: var(--series-hombres);
	}
	.personas {
		flex: none;
		font-size: 10px;
		font-weight: 600;
		color: var(--color-muted);
	}
	.evac-tag {
		flex: none;
		font-size: 10px;
		font-weight: 700;
		padding: 2px 6px;
		border-radius: var(--radius-full);
		white-space: nowrap;
		background: color-mix(in srgb, var(--color-success) 18%, transparent);
		color: var(--color-success);
	}
	.severidad {
		flex: none;
		display: flex;
		gap: 1px;
		margin-left: auto;
		color: var(--status-critical);
	}
	.texto {
		color: var(--color-text);
	}
</style>
