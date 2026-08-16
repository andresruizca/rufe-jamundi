<script lang="ts">
	import { page } from '$app/state';
	import { resolve } from '$app/paths';
	import HouseIcon from 'phosphor-svelte/lib/HouseIcon';
	import GraduationCapIcon from 'phosphor-svelte/lib/GraduationCapIcon';
	import BuildingsIcon from 'phosphor-svelte/lib/BuildingsIcon';

	const TABS = [
		{ href: resolve('/'), label: 'Personas', icon: HouseIcon },
		{
			href: resolve('/instituciones-educativas'),
			label: 'Inst. educativas',
			icon: GraduationCapIcon
		},
		{ href: resolve('/equipamientos'), label: 'Equipamientos', icon: BuildingsIcon }
	];

	function isActive(href: string): boolean {
		const path = page.url.pathname;
		return href.endsWith('/') ? path === href : path === href || path === href + '/';
	}
</script>

<nav class="tabs" aria-label="Secciones del tablero">
	{#each TABS as tab (tab.href)}
		<a
			href={tab.href}
			class="tab"
			class:active={isActive(tab.href)}
			aria-current={isActive(tab.href) ? 'page' : undefined}
		>
			<tab.icon size={16} weight={isActive(tab.href) ? 'fill' : 'regular'} aria-hidden="true" />
			{tab.label}
		</a>
	{/each}
</nav>

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
		font-size: 12.5px;
		font-weight: 700;
		color: var(--color-muted);
		text-decoration: none;
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
