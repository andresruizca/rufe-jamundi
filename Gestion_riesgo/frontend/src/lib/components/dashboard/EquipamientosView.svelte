<script lang="ts">
	import { onMount } from 'svelte';
	import { EQUIPAMIENTOS_FALLBACK } from '$lib/equipamientos/data';
	import type { EquipamientosDataset } from '$lib/equipamientos/types';
	import { fetchLiveEquipamientos } from '$lib/equipamientos/live';
	import {
		aggregateEquipamientos,
		filterEquipamientos,
		sortEquipamientos
	} from '$lib/equipamientosAggregate';
	import type { EquipamientosSortKey } from '$lib/equipamientosAggregate';
	import SearchBox from '$lib/components/SearchBox.svelte';
	import KpiTile from '$lib/components/KpiTile.svelte';
	import BarRow from '$lib/components/BarRow.svelte';
	import LiveStatus from '$lib/components/LiveStatus.svelte';
	import EquipamientosTable from '$lib/components/EquipamientosTable.svelte';
	import { BarChart3, ShieldAlert, Wrench, FileText, Table2, FileEdit } from '@lucide/svelte';

	const REFRESH_MS = 3 * 60 * 1000;

	let liveDataset = $state<EquipamientosDataset | null>(null);
	let liveStatus = $state<'loading' | 'live' | 'stale'>('loading');
	let liveError = $state<string | undefined>(undefined);
	let refreshing = $state(false);

	const DATA = $derived(liveDataset ?? EQUIPAMIENTOS_FALLBACK);

	async function refresh() {
		refreshing = true;
		try {
			liveDataset = await fetchLiveEquipamientos();
			liveStatus = 'live';
			liveError = undefined;
		} catch (e) {
			liveStatus = liveDataset ? 'live' : 'stale';
			liveError = e instanceof Error ? e.message : 'No se pudo conectar con la hoja en vivo.';
		} finally {
			refreshing = false;
		}
	}

	onMount(() => {
		refresh();
		const interval = setInterval(() => {
			if (document.visibilityState === 'visible') refresh();
		}, REFRESH_MS);
		return () => clearInterval(interval);
	});

	let query = $state('');
	let sortKey = $state<EquipamientosSortKey>('categoria');
	let sortDir = $state<1 | -1>(1);

	const filtered = $derived(filterEquipamientos(DATA.items, query));
	const agg = $derived(aggregateEquipamientos(DATA, filtered));
	const sortedRows = $derived(sortEquipamientos(filtered, sortKey, sortDir));

	const isFiltered = $derived(query.length > 0);
	const heroScopeLabel = $derived(
		query ? `Resultados para "${query}"` : 'Unidades reportadas · todas las categorías'
	);

	const fmt = (n: number) => n.toLocaleString('es-CO');

	const notasCategorias = $derived(DATA.categorias.filter((c) => c.nota));

	const maxCategoria = $derived(Math.max(...agg.porCategoria.map((c) => c.unidades), 1));

	const ESTADO_COLOR: Record<string, string> = {
		Averiado: 'var(--status-warning)',
		Destruido: 'var(--status-critical)',
		'No habitable': 'var(--status-serious)',
		Habitable: 'var(--status-good)',
		'En verificación': 'var(--color-secondary)'
	};
	const estadoRows = $derived(
		Object.entries(agg.porEstado)
			.map(([name, value]) => ({ name, value }))
			.sort((a, b) => b.value - a.value)
	);
	const maxEstado = $derived(Math.max(...estadoRows.map((r) => r.value), 1));

	const maxVisita = $derived(
		Math.max(agg.visitaSi, agg.visitaNo, agg.visitaPorConfirmar, agg.visitaSinDato, 1)
	);
	const maxInforme = $derived(Math.max(agg.informeSi, agg.informeNo, agg.informeSinDato, 1));

	const TEXT_KEYS = new Set<EquipamientosSortKey>([
		'categoria',
		'nombre',
		'estado',
		'visita',
		'informe'
	]);
	function toggleSort(key: EquipamientosSortKey) {
		if (sortKey === key) {
			sortDir = (sortDir * -1) as 1 | -1;
		} else {
			sortKey = key;
			sortDir = TEXT_KEYS.has(key) ? 1 : -1;
		}
	}
</script>

<LiveStatus status={liveStatus} asOf={DATA.asOf} error={liveError} {refreshing} onRefresh={refresh} />

<!--
	Estas dos pestañas siguen leyendo la hoja de Google: ese censo —colegios y
	equipamientos afectados— nunca entró al sistema, así que no hay de dónde
	sacarlo. La pestaña de Personas sí muestra ya los datos oficiales, y quien
	mira el tablero tiene que poder distinguir una cosa de la otra sin saber
	cómo está hecho por dentro.
-->
<p class="fuente-externa">
	Fuente: hoja de cálculo de la dependencia, no la base del sistema. Este censo
	todavía no se ha incorporado al Sistema de Gestión del Riesgo.
</p>

<div class="advisory">
	<svg
		width="16"
		height="16"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		stroke-width="2"
		stroke-linecap="round"
		stroke-linejoin="round"
		aria-hidden="true"
		><path
			d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"
		/><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg
	>
	<div>
		<strong>Datos del formulario FR-1703-SMD-69, pestaña Equipamientos.</strong> Esta hoja no es una
		tabla plana: reporta por categoría (Alcaldía, Instalaciones de cultura, Sector religioso, Centros
		de desarrollo, Centros comerciales, etc.), a veces con varias unidades juntas sin nombre individual
		— esas quedan agrupadas como "Sin detalle — N unidades" en vez de inventarles nombres. Cifras de
		apoyo operativo, no un reporte técnico certificado.
	</div>
</div>

<div class="filterbar">
	<SearchBox
		bind:query
		placeholder="Buscar equipamiento o categoría…"
		label="Buscar equipamiento o categoría"
		id="equip-search"
	/>
</div>

<div class="hero-total">
	<div class="label">{heroScopeLabel}</div>
	<div class="value">
		{fmt(agg.unidades)}{#if isFiltered}<small>
				de {fmt(DATA.items.reduce((a, i) => a + i.cantidad, 0))}</small
			>{/if}
	</div>
</div>

<div class="kpi-grid">
	<KpiTile
		label="Categorías"
		value={agg.categorias}
		color="var(--color-primary)"
		sub="con al menos un equipamiento, dentro del filtro"
	/>
	<KpiTile
		label="Equipamientos con detalle"
		value={filtered.filter((i) => !i.sinDetalle).length}
		color="var(--series-mujeres)"
		sub="con nombre individual"
	/>
	<KpiTile
		label="Con visita realizada"
		value={agg.visitaSi}
		color="var(--status-good)"
		sub="unidades, dentro del filtro"
	/>
	<KpiTile
		label="Con informe disponible"
		value={agg.informeSi}
		color="var(--status-warning)"
		sub="unidades, dentro del filtro"
	/>
</div>

<div class="chart-grid">
	<div class="card span-2">
		<div class="card-head">
			<div>
				<h2><BarChart3 size={16} strokeWidth={2.25} aria-hidden="true" /> Equipamientos por categoría</h2>
				<p class="card-note">Unidades reportadas por categoría, dentro del filtro activo</p>
			</div>
		</div>
		{#if agg.porCategoria.length === 0}
			<p class="card-note">Sin resultados para este filtro.</p>
		{:else}
			<div class="bars">
				{#each agg.porCategoria as c (c.nombre)}
					<BarRow label={c.nombre} value={c.unidades} max={maxCategoria} color="var(--seq-adultos)" />
				{/each}
			</div>
		{/if}
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2><ShieldAlert size={16} strokeWidth={2.25} aria-hidden="true" /> Estado</h2>
				<p class="card-note">{fmt(agg.unidades)} unidades, dentro del filtro activo</p>
			</div>
		</div>
		<div class="bars">
			{#each estadoRows as r (r.name)}
				<BarRow
					label={r.name}
					value={r.value}
					max={maxEstado}
					color={ESTADO_COLOR[r.name] ?? 'var(--color-accent)'}
				/>
			{/each}
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2><Wrench size={16} strokeWidth={2.25} aria-hidden="true" /> Visita técnica realizada</h2>
				<p class="card-note">Si ya se hizo la visita de verificación</p>
			</div>
		</div>
		<div class="bars">
			<BarRow label="Sí" value={agg.visitaSi} max={maxVisita} color="var(--status-good)" />
			<BarRow label="No" value={agg.visitaNo} max={maxVisita} color="var(--status-serious)" />
			{#if agg.visitaPorConfirmar > 0}
				<BarRow
					label="Por confirmar"
					value={agg.visitaPorConfirmar}
					max={maxVisita}
					color="var(--status-warning)"
				/>
			{/if}
			{#if agg.visitaSinDato > 0}
				<BarRow label="Sin dato" value={agg.visitaSinDato} max={maxVisita} color="" dim />
			{/if}
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2><FileText size={16} strokeWidth={2.25} aria-hidden="true" /> Informe disponible</h2>
				<p class="card-note">Si ya existe un informe técnico sobre el equipamiento</p>
			</div>
		</div>
		<div class="bars">
			<BarRow label="Sí" value={agg.informeSi} max={maxInforme} color="var(--status-good)" />
			<BarRow label="No" value={agg.informeNo} max={maxInforme} color="var(--status-serious)" />
			{#if agg.informeSinDato > 0}
				<BarRow label="Sin dato" value={agg.informeSinDato} max={maxInforme} color="" dim />
			{/if}
		</div>
	</div>

	{#if notasCategorias.length > 0}
		<div class="card">
			<div class="card-head">
				<div>
					<h2>
						<FileEdit size={16} strokeWidth={2.25} aria-hidden="true" /> Categorías con nota, sin conteo
						aún
					</h2>
					<p class="card-note">Categorías reportadas sin número de unidades todavía</p>
				</div>
			</div>
			<ul class="notas-list">
				{#each notasCategorias as c (c.nombre)}
					<li><b>{c.nombre}:</b> {c.nota}</li>
				{/each}
			</ul>
		</div>
	{/if}
</div>

<div class="card">
	<div class="card-head">
		<div>
			<h2><Table2 size={16} strokeWidth={2.25} aria-hidden="true" /> Detalle por equipamiento</h2>
			<p class="card-note">
				{fmt(sortedRows.length)} equipamientos · toca un encabezado para ordenar · desliza para ver
				todas las columnas
			</p>
		</div>
	</div>
	<EquipamientosTable rows={sortedRows} {sortKey} {sortDir} onSort={toggleSort} />
</div>

<footer>
	<p>
		<strong>RUFE</strong> — Registro consolidado, pestaña Equipamientos, código FR-1703-SMD-69 (SMD-ERE),
		Alcaldía Municipal de Jamundí.
	</p>
	<p>
		El tablero lee la hoja de Google directamente desde el navegador de quien lo visita — no pasa
		por ningún servidor propio. Si la hoja deja de estar compartida como "Cualquiera con el
		enlace", el tablero sigue mostrando el último snapshot descargado (ver aviso arriba).
	</p>
</footer>

<style>
	.advisory {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		background: var(--color-warning-bg);
		border: 1px solid color-mix(in srgb, var(--color-warning) 35%, transparent);
		color: color-mix(in srgb, var(--color-warning) 70%, var(--color-text));
		border-radius: var(--radius);
		padding: 10px 12px;
		font-size: 12.5px;
		line-height: 1.5;
	}
	.advisory svg {
		flex: none;
		margin-top: 1px;
	}
	.advisory strong {
		color: inherit;
	}
	.notas-list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
		font-size: 12.5px;
		line-height: 1.5;
		color: var(--color-text);
	}
	.notas-list b {
		color: var(--color-primary-dark);
	}
	.fuente-externa {
		margin: 0 0 12px;
		padding: 8px 12px;
		border: 1px dashed var(--color-border);
		border-radius: 8px;
		font-size: 0.8rem;
		line-height: 1.45;
		color: var(--color-muted);
	}

</style>
