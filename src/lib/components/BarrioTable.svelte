<script lang="ts">
	import type { Barrio } from '$lib/data';
	import type { SortKey } from '$lib/aggregate';

	let {
		rows,
		sortKey,
		sortDir,
		onSort
	}: {
		rows: Barrio[];
		sortKey: SortKey;
		sortDir: 1 | -1;
		onSort: (key: SortKey) => void;
	} = $props();

	const columns: { key: SortKey; label: string; align: 'left' | 'right' }[] = [
		{ key: 'name', label: 'Barrio / vereda', align: 'left' },
		{ key: 'zona', label: 'Zona', align: 'right' },
		{ key: 'total', label: 'Total', align: 'right' },
		{ key: 'F', label: 'Mujeres', align: 'right' },
		{ key: 'M', label: 'Hombres', align: 'right' },
		{ key: 'Ninos', label: 'Niños', align: 'right' },
		{ key: 'Jovenes', label: 'Jóvenes', align: 'right' },
		{ key: 'Adultos', label: 'Adultos', align: 'right' },
		{ key: 'AdultosMayores', label: 'Ad. mayores', align: 'right' }
	];

	const fmt = (n: number) => n.toLocaleString('es-CO');
</script>

<div class="table-scroll">
	<table>
		<thead>
			<tr>
				{#each columns as col (col.key)}
					<th
						class:sorted={sortKey === col.key}
						style:text-align={col.align}
						onclick={() => onSort(col.key)}
					>
						<span class="th-label">{col.label}</span>
						<span class="arrow">{sortKey === col.key ? (sortDir === -1 ? '▼' : '▲') : ''}</span>
					</th>
				{/each}
			</tr>
		</thead>
		<tbody>
			{#if rows.length === 0}
				<tr class="empty-row"
					><td colspan="9">No se encontraron barrios o veredas con ese filtro.</td></tr
				>
			{:else}
				{#each rows as b (b.name)}
					<tr>
						<td>{b.name}</td>
						<td class="badge-cell"
							><span
								class="badge"
								class:urbana={b.zona === 'Urbana'}
								class:rural={b.zona === 'Rural'}>{b.zona}</span
							></td
						>
						<td class="num total-col">{fmt(b.total)}</td>
						<td class="num">{fmt(b.F)}</td>
						<td class="num">{fmt(b.M)}</td>
						<td class="num">{fmt(b.Ninos)}</td>
						<td class="num">{fmt(b.Jovenes)}</td>
						<td class="num">{fmt(b.Adultos)}</td>
						<td class="num">{fmt(b.AdultosMayores)}</td>
					</tr>
				{/each}
			{/if}
		</tbody>
	</table>
</div>

<style>
	.table-scroll {
		overflow-x: auto;
		border-radius: 10px;
		border: 1px solid var(--color-border);
	}
	table {
		border-collapse: collapse;
		width: 100%;
		min-width: 640px;
		font-size: 12.5px;
	}
	thead th {
		position: sticky;
		top: 0;
		background: var(--color-surface-alt);
		padding: 9px 10px;
		font-weight: 700;
		color: var(--color-muted);
		border-bottom: 1px solid var(--color-border-strong);
		cursor: pointer;
		user-select: none;
		white-space: nowrap;
	}
	thead th:first-child {
		position: sticky;
		left: 0;
		top: 0;
		z-index: 3;
	}
	thead th.sorted {
		color: var(--color-primary-dark);
	}
	thead th .arrow {
		font-size: 9px;
		margin-left: 3px;
		opacity: 0.7;
	}
	tbody td {
		padding: 8px 10px;
		text-align: right;
		border-bottom: 1px solid var(--color-border);
		white-space: nowrap;
		color: var(--color-text);
	}
	tbody td:first-child {
		text-align: left;
		position: sticky;
		left: 0;
		background: var(--color-surface);
		font-weight: 600;
		box-shadow: 1px 0 0 var(--color-border);
	}
	tbody tr:hover td {
		background: var(--color-info-bg);
	}
	tbody tr:hover td:first-child {
		background: var(--color-info-bg);
	}
	tbody td.num {
		font-variant-numeric: tabular-nums;
	}
	tbody td.total-col {
		font-weight: 800;
	}
	.badge-cell {
		text-align: right;
	}
	.badge {
		font-size: 10.5px;
		font-weight: 700;
		padding: 2px 7px;
		border-radius: var(--radius-full);
		letter-spacing: 0.02em;
	}
	.badge.urbana {
		background: color-mix(in srgb, var(--series-mujeres) 16%, transparent);
		color: var(--series-mujeres);
	}
	.badge.rural {
		background: color-mix(in srgb, var(--series-hombres) 18%, transparent);
		color: var(--series-hombres);
	}
	.empty-row td {
		text-align: center;
		color: var(--color-muted);
		padding: 22px;
	}
</style>
