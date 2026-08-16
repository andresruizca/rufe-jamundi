<script lang="ts">
	import type { EquipamientoItem } from '$lib/equipamientos/types';
	import type { EquipamientosSortKey } from '$lib/equipamientosAggregate';

	let {
		rows,
		sortKey,
		sortDir,
		onSort
	}: {
		rows: EquipamientoItem[];
		sortKey: EquipamientosSortKey;
		sortDir: 1 | -1;
		onSort: (key: EquipamientosSortKey) => void;
	} = $props();

	const columns: { key: EquipamientosSortKey; label: string; align: 'left' | 'right' }[] = [
		{ key: 'nombre', label: 'Equipamiento', align: 'left' },
		{ key: 'categoria', label: 'Categoría', align: 'left' },
		{ key: 'estado', label: 'Estado', align: 'right' },
		{ key: 'cantidad', label: 'Cantidad', align: 'right' },
		{ key: 'visita', label: 'Visita técnica', align: 'right' },
		{ key: 'informe', label: 'Informe', align: 'right' }
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
					><td colspan="6">No se encontraron equipamientos con ese filtro.</td></tr
				>
			{:else}
				{#each rows as e (e.categoria + e.nombre)}
					<tr class:sin-detalle={e.sinDetalle}>
						<td>{e.nombre}</td>
						<td>{e.categoria}</td>
						<td class="num">{e.estado || 'Sin dato'}</td>
						<td class="num">{fmt(e.cantidad)}</td>
						<td class="num">{e.visita}</td>
						<td class="num">{e.informe}</td>
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
		min-width: 560px;
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
	tbody td:nth-child(2) {
		text-align: left;
		color: var(--color-muted);
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
	tbody tr.sin-detalle td:first-child {
		font-style: italic;
		color: var(--color-muted);
	}
	.empty-row td {
		text-align: center;
		color: var(--color-muted);
		padding: 22px;
	}
</style>
