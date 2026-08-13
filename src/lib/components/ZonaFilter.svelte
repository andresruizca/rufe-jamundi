<script lang="ts">
	import type { Zona } from '$lib/data';

	let { zona = $bindable() }: { zona: Zona | 'todas' } = $props();

	const options: { value: Zona | 'todas'; label: string }[] = [
		{ value: 'todas', label: 'Todas las zonas' },
		{ value: 'Urbana', label: 'Urbana' },
		{ value: 'Rural', label: 'Rural' }
	];
</script>

<div class="segmented" role="group" aria-label="Filtrar por zona">
	{#each options as opt (opt.value)}
		<button type="button" aria-pressed={zona === opt.value} onclick={() => (zona = opt.value)}>
			{opt.label}
		</button>
	{/each}
</div>

<style>
	.segmented {
		display: flex;
		background: var(--color-surface-alt);
		border-radius: 9px;
		padding: 3px;
		gap: 3px;
	}
	button {
		flex: 1;
		border: 0;
		background: transparent;
		color: var(--color-muted);
		font: inherit;
		font-weight: 600;
		font-size: 13px;
		padding: 8px 10px;
		border-radius: 7px;
		cursor: pointer;
		transition: background var(--transition), color var(--transition);
	}
	button[aria-pressed='true'] {
		background: var(--color-primary);
		color: #fff;
	}
	button:hover:not([aria-pressed='true']) {
		background: rgba(21, 119, 214, 0.1);
	}
	button:focus-visible {
		outline: 2px solid var(--color-primary);
		outline-offset: 2px;
	}
</style>
