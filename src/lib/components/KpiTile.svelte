<script lang="ts">
	let { label, value, color, sub }: { label: string; value: number; color: string; sub: string } =
		$props();
</script>

<div class="kpi-tile">
	<div class="kpi-label"><span class="dot" style:background={color}></span>{label}</div>
	<div class="kpi-value">{value.toLocaleString('es-CO')}</div>
	<div class="kpi-sub">{sub}</div>
</div>

<style>
	.kpi-tile {
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		border-radius: var(--radius);
		padding: 12px 13px;
		display: flex;
		flex-direction: column;
		gap: 5px;
		box-shadow: var(--shadow-sm);
	}
	.kpi-label {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 12px;
		font-weight: 600;
		color: var(--color-muted);
	}
	.dot {
		width: 9px;
		height: 9px;
		border-radius: 50%;
		flex: none;
	}
	.kpi-value {
		font-size: clamp(21px, 6vw, 27px);
		font-weight: 800;
		letter-spacing: -0.01em;
		color: var(--color-text);
		font-variant-numeric: tabular-nums;
	}
	.kpi-sub {
		font-size: 11.5px;
		color: var(--color-muted);
	}
</style>
