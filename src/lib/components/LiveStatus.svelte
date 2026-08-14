<script lang="ts">
	let {
		status,
		asOf,
		error,
		refreshing,
		onRefresh
	}: {
		status: 'loading' | 'live' | 'stale';
		asOf: string;
		error?: string;
		refreshing: boolean;
		onRefresh: () => void;
	} = $props();
</script>

<div class="live-status" class:stale={status === 'stale'}>
	<span class="dot" class:pulse={status === 'loading' || refreshing}></span>
	<span class="text">
		{#if status === 'loading'}
			Conectando con la hoja en vivo…
		{:else if status === 'live'}
			En vivo · actualizado {asOf}
		{:else}
			Sin conexión con la hoja — mostrando el último snapshot ({asOf})
		{/if}
	</span>
	<button type="button" onclick={onRefresh} disabled={refreshing}>
		{refreshing ? 'Actualizando…' : 'Actualizar'}
	</button>
</div>
{#if status === 'stale' && error}
	<p class="error-detail">{error}</p>
{/if}

<style>
	.live-status {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 12px;
		color: var(--color-muted);
	}
	.dot {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		background: var(--color-success);
		flex: none;
	}
	.stale .dot {
		background: var(--color-warning);
	}
	.dot.pulse {
		animation: pulse 1.2s ease-in-out infinite;
	}
	@media (prefers-reduced-motion: reduce) {
		.dot.pulse {
			animation: none;
		}
	}
	.text {
		flex: 1;
		min-width: 0;
	}
	button {
		font: inherit;
		font-size: 11.5px;
		font-weight: 600;
		border: 1px solid var(--color-border-strong);
		background: var(--color-surface);
		color: var(--color-primary-dark);
		border-radius: var(--radius-full);
		padding: 4px 10px;
		cursor: pointer;
		flex: none;
	}
	button:hover:not(:disabled) {
		background: var(--color-info-bg);
	}
	button:disabled {
		opacity: 0.6;
		cursor: default;
	}
	.error-detail {
		margin: 4px 0 0;
		font-size: 11px;
		color: var(--color-warning);
	}

	@keyframes pulse {
		0%,
		100% {
			opacity: 1;
		}
		50% {
			opacity: 0.35;
		}
	}
</style>
