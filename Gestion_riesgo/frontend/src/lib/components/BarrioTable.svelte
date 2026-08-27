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

	// ── Se van cargando a medida que se baja ────────────────────────────────
	//
	// Son 240 barrios. Dibujarlos todos hacía de esta tabla una pared de dos
	// metros por la que había que pasar para llegar a lo que hubiera debajo, y
	// nadie lee la fila 180 de una tabla ordenada por total.
	//
	// Diez de entrada, y diez más cada vez que el final entra en pantalla. Lo
	// que se busca —el barrio con más gente— está en las primeras filas por
	// definición, porque la tabla llega ordenada.

	const PASO = 10;

	let visibles = $state(PASO);
	let centinela = $state<HTMLDivElement | null>(null);

	const mostradas = $derived(rows.slice(0, visibles));
	const quedan = $derived(Math.max(0, rows.length - visibles));

	// Cambiar de filtro o de orden vuelve a empezar por arriba. Sin esto, quien
	// bajó hasta la fila 200 y luego busca un barrio recibiría doscientas filas
	// de una lista que ahora tiene tres.
	$effect(() => {
		void rows;
		visibles = PASO;
	});

	$effect(() => {
		if (!centinela || quedan === 0) return;

		// `rootMargin` adelanta la carga: pide las siguientes diez cuando el
		// final está a media pantalla, no cuando ya se llegó. Así no se ve el
		// salto de una tabla que se queda corta y crece un momento después.
		const observador = new IntersectionObserver(
			(entradas) => {
				if (entradas.some((e) => e.isIntersecting)) {
					visibles += PASO;
				}
			},
			{ rootMargin: '300px' }
		);

		observador.observe(centinela);

		return () => observador.disconnect();
	});
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
				{#each mostradas as b (b.name + '::' + b.zona)}
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

{#if quedan > 0}
	<!--
		El centinela dispara la carga al acercarse el final. El botón no es un
		adorno: si el navegador no trae IntersectionObserver, o quien usa la
		pantalla se mueve con el teclado y no «baja», sigue habiendo forma de ver
		el resto.
	-->
	<div class="mas" bind:this={centinela}>
		<button type="button" onclick={() => (visibles += PASO)}>
			Ver {Math.min(PASO, quedan)} más
		</button>
		<span class="mas__cuenta">
			{mostradas.length} de {rows.length} barrios/veredas
		</span>
	</div>
{:else if rows.length > PASO}
	<p class="mas__cuenta mas__cuenta--final">
		{rows.length} barrios/veredas · están todos
	</p>
{/if}

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
		/* Debajo de la barra superior de la aplicación. Con `top: 0` la fila de
		   encabezados se pegaba al borde de la pantalla, que es donde está la
		   barra, y se metía detrás de ella. */
		top: calc(var(--alto-barra, 3.8rem) + 8px);
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
		top: calc(var(--alto-barra, 3.8rem) + 8px);
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
	.mas {
		display: flex;
		align-items: center;
		justify-content: center;
		flex-wrap: wrap;
		gap: 0.5rem 0.9rem;
		padding: 0.7rem 0 0.2rem;
	}

	.mas button {
		padding: 0.4rem 0.9rem;
		border: 1px solid var(--color-border);
		border-radius: 999px;
		background: var(--color-surface-alt);
		color: var(--color-text);
		font-size: 0.82rem;
		font-weight: 600;
		cursor: pointer;
	}

	.mas button:hover {
		border-color: var(--color-primary);
	}

	.mas__cuenta {
		font-size: 0.78rem;
		color: var(--color-muted);
	}

	.mas__cuenta--final {
		display: block;
		margin: 0.7rem 0 0;
		text-align: center;
	}

</style>
