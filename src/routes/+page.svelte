<script lang="ts">
	import { DATA } from '$lib/data';
	import type { Zona } from '$lib/data';
	import { aggregate, filterBarrios, sortBarrios, fmt, pct } from '$lib/aggregate';
	import type { SortKey } from '$lib/aggregate';
	import Header from '$lib/components/Header.svelte';
	import ZonaFilter from '$lib/components/ZonaFilter.svelte';
	import SearchBox from '$lib/components/SearchBox.svelte';
	import KpiTile from '$lib/components/KpiTile.svelte';
	import BarRow from '$lib/components/BarRow.svelte';
	import BarrioTable from '$lib/components/BarrioTable.svelte';

	let zona = $state<Zona | 'todas'>('todas');
	let query = $state('');
	let sortKey = $state<SortKey>('total');
	let sortDir = $state<1 | -1>(-1);

	const filtered = $derived(filterBarrios(DATA.barrios, zona, query));
	const agg = $derived(aggregate(filtered));
	const sortedRows = $derived(sortBarrios(filtered, sortKey, sortDir));
	const ranked = $derived([...filtered].sort((a, b) => b.total - a.total).slice(0, 12));
	const maxRanked = $derived(Math.max(...ranked.map((b) => b.total), 1));

	const urbanaAgg = $derived(aggregate(filtered.filter((b) => b.zona === 'Urbana')));
	const ruralAgg = $derived(aggregate(filtered.filter((b) => b.zona === 'Rural')));
	const maxZona = $derived(Math.max(agg.Urbana, agg.Rural, 1));
	const maxGeneroTodas = $derived(Math.max(urbanaAgg.F, urbanaAgg.M, ruralAgg.F, ruralAgg.M, 1));
	const maxGeneroUna = $derived(Math.max(agg.F, agg.M, 1));
	const maxEdad = $derived(
		Math.max(agg.Ninos, agg.Jovenes, agg.Adultos, agg.AdultosMayores, agg.sinEdad, 1)
	);

	const isFiltered = $derived(zona !== 'todas' || query.length > 0);
	const heroScopeLabel = $derived(
		(query ? `Resultados para "${query}" · ` : 'Total personas · ') +
			(zona === 'todas' ? 'todas las zonas' : `zona ${zona}`)
	);
	const rankingTitle = $derived(
		'Barrios / veredas con más personas' +
			(zona !== 'todas' ? ` — zona ${zona}` : '') +
			(query ? ` — filtro "${query}"` : '')
	);
	const generoTitle = $derived(zona === 'todas' ? 'Género por zona' : `Género — zona ${zona}`);

	const TEXT_COLUMNS = new Set<SortKey>(['name', 'zona']);
	function toggleSort(key: SortKey) {
		if (sortKey === key) {
			sortDir = (sortDir * -1) as 1 | -1;
		} else {
			sortKey = key;
			sortDir = TEXT_COLUMNS.has(key) ? 1 : -1;
		}
	}
</script>

<svelte:head>
	<title>Tablero RUFE — Sismo Jamundí</title>
	<meta
		name="description"
		content="Tablero interactivo de personas registradas por el sismo del 10 de agosto en Jamundí, por zona rural/urbana, barrio, género y edad."
	/>
</svelte:head>

<div class="wrap">
	<Header asOf={DATA.asOf} total={DATA.total} />

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
			<strong>Versión de trabajo — carga manual.</strong> Estos datos se digitaron a mano desde el
			consolidado RUFE (FR-1703-SMD-69) hasta el corte indicado; aún no hay conexión en vivo con
			Google Sheets. El género se infiere de la marca "X" registrada en el formulario físico y
			puede contener inconsistencias de diligenciamiento; la zona (rural/urbana) se infiere del
			corregimiento reportado. Cifras de apoyo operativo, no un censo certificado.
		</div>
	</div>

	<div class="filterbar">
		<ZonaFilter bind:zona />
		<SearchBox bind:query placeholder="Buscar barrio o vereda…" />
	</div>

	<div class="hero-total">
		<div class="label">{heroScopeLabel}</div>
		<div class="value">
			{fmt(agg.total)}{#if isFiltered}<small> de {fmt(DATA.total)}</small>{/if}
		</div>
	</div>

	<div class="kpi-grid">
		<KpiTile
			label="Mujeres"
			value={agg.F}
			color="var(--series-mujeres)"
			sub="{pct(agg.F, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Hombres"
			value={agg.M}
			color="var(--series-hombres)"
			sub="{pct(agg.M, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Niños (0–11)"
			value={agg.Ninos}
			color="var(--seq-ninos)"
			sub="{pct(agg.Ninos, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Jóvenes (12–28)"
			value={agg.Jovenes}
			color="var(--seq-jovenes)"
			sub="{pct(agg.Jovenes, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Adultos (29–59)"
			value={agg.Adultos}
			color="var(--seq-adultos)"
			sub="{pct(agg.Adultos, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Ad. mayores (60+)"
			value={agg.AdultosMayores}
			color="var(--seq-mayores)"
			sub="{pct(agg.AdultosMayores, agg.total)}% del total filtrado"
		/>
	</div>

	<div class="chart-grid">
		{#if zona === 'todas'}
			<div class="card">
				<div class="card-head">
					<div>
						<h2>Zona rural / urbana</h2>
						<p class="card-note">Personas por zona, según el corregimiento reportado</p>
					</div>
				</div>
				<div class="bars">
					<BarRow
						label="Urbana · {pct(agg.Urbana, agg.total)}%"
						value={agg.Urbana}
						max={maxZona}
						color="var(--series-mujeres)"
					/>
					<BarRow
						label="Rural · {pct(agg.Rural, agg.total)}%"
						value={agg.Rural}
						max={maxZona}
						color="var(--series-hombres)"
					/>
				</div>
			</div>
		{/if}

		<div class="card">
			<div class="card-head">
				<div>
					<h2>Grupo de edad</h2>
					<p class="card-note">Niños 0–11 · Jóvenes 12–28 · Adultos 29–59 · Adultos mayores 60+</p>
				</div>
			</div>
			<div class="bars">
				<BarRow label="Niños 0–11" value={agg.Ninos} max={maxEdad} color="var(--seq-ninos)" />
				<BarRow
					label="Jóvenes 12–28"
					value={agg.Jovenes}
					max={maxEdad}
					color="var(--seq-jovenes)"
				/>
				<BarRow label="Adultos 29–59" value={agg.Adultos} max={maxEdad} color="var(--seq-adultos)" />
				<BarRow
					label="Ad. mayores 60+"
					value={agg.AdultosMayores}
					max={maxEdad}
					color="var(--seq-mayores)"
				/>
				{#if agg.sinEdad > 0}
					<BarRow label="Sin dato" value={agg.sinEdad} max={maxEdad} color="" dim />
				{/if}
			</div>
		</div>

		<div class="card span-2">
			<div class="card-head">
				<div>
					<h2>{generoTitle}</h2>
					<p class="card-note">Personas con género identificado en el formulario (M/F)</p>
				</div>
				<div class="legend">
					<span class="legend-item"
						><span class="swatch" style:background="var(--series-mujeres)"></span>Mujeres</span
					>
					<span class="legend-item"
						><span class="swatch" style:background="var(--series-hombres)"></span>Hombres</span
					>
				</div>
			</div>
			{#if zona === 'todas'}
				<div class="zona-group">
					<div class="zona-title">
						Urbana <span class="card-note">— {fmt(urbanaAgg.total)} personas</span>
					</div>
					<div class="bars">
						<BarRow
							label="Mujeres"
							value={urbanaAgg.F}
							max={maxGeneroTodas}
							color="var(--series-mujeres)"
						/>
						<BarRow
							label="Hombres"
							value={urbanaAgg.M}
							max={maxGeneroTodas}
							color="var(--series-hombres)"
						/>
					</div>
				</div>
				<div class="zona-group">
					<div class="zona-title">
						Rural <span class="card-note">— {fmt(ruralAgg.total)} personas</span>
					</div>
					<div class="bars">
						<BarRow
							label="Mujeres"
							value={ruralAgg.F}
							max={maxGeneroTodas}
							color="var(--series-mujeres)"
						/>
						<BarRow
							label="Hombres"
							value={ruralAgg.M}
							max={maxGeneroTodas}
							color="var(--series-hombres)"
						/>
					</div>
				</div>
			{:else}
				<div class="bars">
					<BarRow label="Mujeres" value={agg.F} max={maxGeneroUna} color="var(--series-mujeres)" />
					<BarRow label="Hombres" value={agg.M} max={maxGeneroUna} color="var(--series-hombres)" />
				</div>
			{/if}
		</div>

		<div class="card span-2">
			<div class="card-head">
				<div>
					<h2>{rankingTitle}</h2>
					<p class="card-note">Los 12 con mayor número de personas, dentro del filtro activo</p>
				</div>
			</div>
			{#if ranked.length === 0}
				<p class="card-note">Sin resultados para este filtro.</p>
			{:else}
				<div class="bars">
					{#each ranked as b (b.name)}
						<BarRow label={b.name} value={b.total} max={maxRanked} color="var(--seq-adultos)" />
					{/each}
				</div>
			{/if}
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2>Detalle por barrio / vereda</h2>
				<p class="card-note">
					{fmt(sortedRows.length)} barrios/veredas · toca un encabezado para ordenar · desliza para
					ver todas las columnas
				</p>
			</div>
		</div>
		<BarrioTable rows={sortedRows} {sortKey} {sortDir} onSort={toggleSort} />
	</div>

	<footer>
		<p>
			<strong>RUFE</strong> — Registro consolidado de familias/personas afectadas, código
			FR-1703-SMD-69 (SMD-ERE), Alcaldía Municipal de Jamundí.
		</p>
		<p>
			Grupos de edad: Niños 0–11 años · Jóvenes 12–28 años · Adultos 29–59 años · Adultos mayores
			60 años o más. "Sin dato" agrupa registros sin fecha de nacimiento o sin marca de género
			legible en el formulario físico.
		</p>
		<p>
			Tablero generado a partir de una exportación puntual del archivo — pendiente habilitar
			actualización automática desde Google Sheets en cuanto se conceda acceso de lectura al
			documento.
		</p>
	</footer>
</div>

<style>
	.wrap {
		max-width: 1180px;
		margin: 0 auto;
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding: 14px 14px 40px;
	}
	@media (min-width: 720px) {
		.wrap {
			gap: 22px;
			padding: 28px 32px 56px;
		}
	}

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

	.filterbar {
		display: flex;
		flex-direction: column;
		gap: 10px;
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		border-radius: var(--radius-lg);
		padding: 10px;
		box-shadow: var(--shadow);
		position: sticky;
		top: 8px;
		z-index: 20;
	}
	@media (min-width: 720px) {
		.filterbar {
			flex-direction: row;
			align-items: center;
			padding: 10px 12px;
		}
	}

	.hero-total {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 12px;
		background: var(--gradient-brand);
		color: #fff;
		border-radius: var(--radius-lg);
		padding: 18px;
		box-shadow: var(--shadow);
	}
	.hero-total .label {
		font-size: 12.5px;
		font-weight: 700;
		letter-spacing: 0.04em;
		text-transform: uppercase;
		opacity: 0.92;
	}
	.hero-total .value {
		font-size: clamp(34px, 10vw, 50px);
		font-weight: 800;
		letter-spacing: -0.02em;
		font-variant-numeric: tabular-nums;
	}
	.hero-total .value small {
		font-size: 0.4em;
		font-weight: 700;
		opacity: 0.85;
		margin-left: 4px;
	}

	.kpi-grid {
		display: grid;
		grid-template-columns: repeat(2, 1fr);
		gap: 10px;
	}
	@media (min-width: 640px) {
		.kpi-grid {
			grid-template-columns: repeat(3, 1fr);
		}
	}
	@media (min-width: 960px) {
		.kpi-grid {
			grid-template-columns: repeat(6, 1fr);
		}
	}

	.chart-grid {
		display: grid;
		grid-template-columns: 1fr;
		gap: 12px;
	}
	@media (min-width: 900px) {
		.chart-grid {
			grid-template-columns: 1fr 1fr;
		}
	}
	.chart-grid :global(.span-2) {
		grid-column: 1 / -1;
	}

	.card {
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		border-radius: var(--radius-lg);
		padding: 15px 15px 17px;
		box-shadow: var(--shadow);
	}
	.card h2 {
		font-size: 14.5px;
		font-weight: 700;
		margin: 0;
		color: var(--color-text);
	}
	.card-note {
		font-size: 11.5px;
		color: var(--color-muted);
		margin: 2px 0 0;
	}
	.card-head {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 10px;
		margin-bottom: 12px;
		flex-wrap: wrap;
	}
	.legend {
		display: flex;
		gap: 12px;
		flex-wrap: wrap;
	}
	.legend-item {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 12px;
		color: var(--color-muted);
		font-weight: 600;
	}
	.legend-item .swatch {
		width: 11px;
		height: 11px;
		border-radius: 3px;
		flex: none;
	}

	.bars {
		display: flex;
		flex-direction: column;
		gap: 9px;
	}

	.zona-group {
		margin-bottom: 4px;
	}
	.zona-group + .zona-group {
		margin-top: 14px;
		padding-top: 12px;
		border-top: 1px dashed var(--color-border);
	}
	.zona-title {
		font-size: 12px;
		font-weight: 700;
		color: var(--color-text);
		margin-bottom: 7px;
		display: flex;
		align-items: center;
		gap: 6px;
	}

	footer {
		font-size: 11.5px;
		color: var(--color-muted);
		border-top: 1px solid var(--color-border);
		padding-top: 14px;
		line-height: 1.6;
	}
	footer p {
		margin: 0 0 6px;
	}
</style>
