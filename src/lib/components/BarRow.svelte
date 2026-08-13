<script lang="ts">
	let {
		label,
		value,
		max,
		color,
		dim = false
	}: { label: string; value: number; max: number; color: string; dim?: boolean } = $props();

	const widthPct = $derived(max > 0 ? Math.max(value > 0 ? 2 : 0, (value / max) * 100) : 0);
</script>

<div class="bar-row" class:dim>
	<div class="rlabel" title={label}>{label}</div>
	<div class="rtrack">
		<div class="rfill" style:width="{widthPct}%" style:background={dim ? '' : color}></div>
	</div>
	<div class="rval">{value.toLocaleString('es-CO')}</div>
</div>

<style>
	.bar-row {
		display: grid;
		grid-template-columns: 96px 1fr 34px;
		align-items: center;
		gap: 8px;
	}
	@media (min-width: 520px) {
		.bar-row {
			grid-template-columns: 148px 1fr 38px;
		}
	}
	.rlabel {
		font-size: 12.5px;
		color: var(--color-text);
		font-weight: 600;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.rtrack {
		position: relative;
		height: 16px;
		background: var(--color-surface-alt);
		border-radius: 5px;
		overflow: hidden;
	}
	.rfill {
		position: absolute;
		inset: 0 auto 0 0;
		border-radius: 5px;
		min-width: 3px;
	}
	.rval {
		font-size: 12.5px;
		font-weight: 700;
		text-align: right;
		font-variant-numeric: tabular-nums;
		color: var(--color-text);
	}
	.dim .rlabel,
	.dim .rval {
		color: var(--color-muted);
		font-weight: 500;
	}
	.dim .rfill {
		background: var(--color-surface-alt);
		border: 1px dashed var(--color-border-strong);
	}
</style>
