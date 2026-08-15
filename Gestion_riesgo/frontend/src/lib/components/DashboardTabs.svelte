<script lang="ts">
	import { House, GraduationCap, Building2 } from '@lucide/svelte';
	import type { DashboardTab } from '$lib/dashboardTabs';

	let { active = $bindable() }: { active: DashboardTab } = $props();

	const TABS: { id: DashboardTab; label: string; icon: typeof House }[] = [
		{ id: 'personas', label: 'Personas', icon: House },
		{ id: 'inst-educativas', label: 'Inst. educativas', icon: GraduationCap },
		{ id: 'equipamientos', label: 'Equipamientos', icon: Building2 }
	];
</script>

<div class="tabs" role="tablist" aria-label="Secciones del tablero">
	{#each TABS as tab (tab.id)}
		<button
			type="button"
			role="tab"
			aria-selected={active === tab.id}
			class="tab"
			class:active={active === tab.id}
			onclick={() => (active = tab.id)}
		>
			<tab.icon size={16} strokeWidth={2.25} aria-hidden="true" />
			{tab.label}
		</button>
	{/each}
</div>

<style>
	.tabs {
		display: flex;
		gap: 4px;
		overflow-x: auto;
		padding-bottom: 2px;
	}
	.tab {
		display: flex;
		align-items: center;
		gap: 6px;
		flex: none;
		font: inherit;
		font-size: 12.5px;
		font-weight: 700;
		color: var(--color-muted);
		background: transparent;
		cursor: pointer;
		padding: 7px 12px;
		border-radius: var(--radius-full);
		border: 1px solid transparent;
		transition:
			background var(--transition),
			color var(--transition),
			border-color var(--transition);
		white-space: nowrap;
	}
	.tab:hover {
		background: var(--color-surface-alt);
		color: var(--color-text);
	}
	.tab.active {
		background: var(--color-primary);
		border-color: var(--color-primary);
		color: #fff;
	}
</style>
