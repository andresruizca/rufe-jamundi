<script lang="ts">
	import { onMount } from 'svelte';
	import { FALLBACK_DATA } from '$lib/data';
	import type { Zona, Dataset } from '$lib/data';
	import { fetchLiveDataset } from '$lib/rufe/live';
	import { aggregate, filterBarrios, sortBarrios, fmt, pct } from '$lib/aggregate';
	import type { SortKey } from '$lib/aggregate';
	import {
		aggregateHogares,
		filterHogares,
		tagObservaciones,
		listObservaciones,
		criticalSeverity
	} from '$lib/hogaresAggregate';
	import Header from '$lib/components/Header.svelte';
	import ZonaFilter from '$lib/components/ZonaFilter.svelte';
	import SearchBox from '$lib/components/SearchBox.svelte';
	import KpiTile from '$lib/components/KpiTile.svelte';
	import BarRow from '$lib/components/BarRow.svelte';
	import BarrioTable from '$lib/components/BarrioTable.svelte';
	import LiveStatus from '$lib/components/LiveStatus.svelte';
	import ObservacionesList from '$lib/components/ObservacionesList.svelte';
	import HogaresCriticosBadge from '$lib/components/HogaresCriticosBadge.svelte';
	import {
		House,
		MapPin,
		CalendarDays,
		VenusAndMars,
		BarChart3,
		ShieldAlert,
		Building2,
		KeyRound,
		ClipboardCheck,
		Siren,
		MessageSquareText,
		Table2,
		Baby,
		GraduationCap,
		User,
		Glasses,
		Venus,
		Mars
	} from '@lucide/svelte';

	const REFRESH_MS = 3 * 60 * 1000;

	let liveDataset = $state<Dataset | null>(null);
	let liveStatus = $state<'loading' | 'live' | 'stale'>('loading');
	let liveError = $state<string | undefined>(undefined);
	let refreshing = $state(false);

	const DATA = $derived(liveDataset ?? FALLBACK_DATA);

	async function refresh() {
		refreshing = true;
		try {
			liveDataset = await fetchLiveDataset();
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

	let zona = $state<Zona | 'todas'>('todas');
	let query = $state('');
	let sortKey = $state<SortKey>('total');
	let sortDir = $state<1 | -1>(-1);

	const filtered = $derived(filterBarrios(DATA.barrios, zona, query));
	const agg = $derived(aggregate(filtered));
	const sortedRows = $derived(sortBarrios(filtered, sortKey, sortDir));
	const ranked = $derived([...filtered].sort((a, b) => b.total - a.total).slice(0, 12));
	const maxRanked = $derived(Math.max(...ranked.map((b) => b.total), 1));
	// Un mismo nombre de barrio puede tener una entrada Urbana y otra Rural
	// (predios puntuales de un corregimiento con cabecera urbana, ver
	// BASE-DATOS RUFE) — cuando las dos caen en el top 12 a la vez, la
	// etiqueta suma la zona entre paréntesis para no mostrar dos barras
	// idénticas sin forma de distinguirlas.
	const rankedNameCounts = $derived.by(() => {
		const counts: Record<string, number> = {};
		for (const b of ranked) counts[b.name] = (counts[b.name] ?? 0) + 1;
		return counts;
	});

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

	const filteredHogares = $derived(filterHogares(DATA.hogares, zona, query));
	const hogaresAgg = $derived(aggregateHogares(filteredHogares));
	const obsTags = $derived(tagObservaciones(filteredHogares));
	const obsList = $derived(listObservaciones(filteredHogares));
	// TODOS los hogares críticos según la observación (toda la data filtrada,
	// evacuados o no) — no solo el subconjunto sin evacuar. Ordenados por
	// severidad (cuántas señales de peligro distintas menciona la
	// observación) para que el popup muestre primero lo más grave; el estado
	// de evacuación se muestra por fila para no perder ese dato.
	const hogaresCriticos = $derived(obsList.filter((o) => o.critical));
	const top20Criticos = $derived(
		[...hogaresCriticos]
			.sort(
				(a, b) => criticalSeverity(b.texto) - criticalSeverity(a.texto) || b.personas - a.personas
			)
			.slice(0, 20)
	);

	const maxHogaresZona = $derived(Math.max(hogaresAgg.urbana, hogaresAgg.rural, 1));
	const promedioPersonasPorHogar = $derived(
		hogaresAgg.count > 0 ? (agg.total / hogaresAgg.count).toFixed(1) : '0'
	);

	const ESTADO_ORDER = ['Habitable', 'Averiado', 'No habitable', 'Destruido'];
	const ESTADO_COLOR: Record<string, string> = {
		Habitable: 'var(--status-good)',
		Averiado: 'var(--status-warning)',
		'No habitable': 'var(--status-serious)',
		Destruido: 'var(--status-critical)'
	};
	const estadoRows = $derived(
		ESTADO_ORDER.map((name) => ({ name, value: hogaresAgg.estadoBien[name] ?? 0 })).filter(
			(r) => r.value > 0
		)
	);
	// "No informa" (alguien revisó y no pudo determinar el estado) y "Sin
	// dato" (nunca se diligenció) se muestran juntos, atenuados, aparte de
	// las 4 categorías de severidad real — mezclarlas ahí distorsionaría la
	// escala de las barras con color de estado.
	const estadoSinDato = $derived(
		(hogaresAgg.estadoBien['No informa'] ?? 0) + (hogaresAgg.estadoBien['Sin dato'] ?? 0)
	);
	const maxEstado = $derived(Math.max(...estadoRows.map((r) => r.value), estadoSinDato, 1));

	const tipoRows = $derived(
		Object.entries(hogaresAgg.tipoBien)
			.map(([name, value]) => ({ name, value }))
			.sort((a, b) => b.value - a.value)
	);
	const maxTipo = $derived(Math.max(...tipoRows.map((r) => r.value), 1));
	const maxVisita = $derived(Math.max(hogaresAgg.visitaSi, hogaresAgg.visitaNo, 1));
	const maxObsTag = $derived(Math.max(...obsTags.map((t) => t.count), 1));

	// Colores distintivos de la paleta de INNOLAB (más allá del azul/naranja
	// ya usado en zona y género), para que esta tarjeta resalte de un
	// vistazo: teal → dorado → coral → azul claro, en ese orden fijo.
	const TENENCIA_COLORS = [
		'var(--color-secondary)',
		'var(--color-highlight)',
		'var(--color-accent)',
		'var(--color-primary-light)'
	];
	// "No informa" (se preguntó y no se pudo determinar) se agrupa con "Sin
	// dato" (nunca se preguntó) — son la misma idea de fondo ("no sabemos"),
	// y así los 4 colores distintivos alcanzan sin repetirse entre categorías
	// que sí tienen significado propio.
	const tenenciaRows = $derived(
		Object.entries(hogaresAgg.tenencia)
			.filter(([name]) => name !== 'Sin dato' && name !== 'No informa')
			.sort((a, b) => b[1] - a[1])
			.map(([name, value], i) => ({
				name,
				value,
				color: TENENCIA_COLORS[i % TENENCIA_COLORS.length]
			}))
	);
	const tenenciaSinDato = $derived(
		(hogaresAgg.tenencia['Sin dato'] ?? 0) + (hogaresAgg.tenencia['No informa'] ?? 0)
	);
	const maxTenencia = $derived(Math.max(...tenenciaRows.map((r) => r.value), tenenciaSinDato, 1));

	const maxPersonasEvac = $derived(
		Math.max(hogaresAgg.personasEvacuadas, hogaresAgg.personasNoEvacuadas, 1)
	);

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
	<Header />

	<LiveStatus
		status={liveStatus}
		asOf={DATA.asOf}
		error={liveError}
		{refreshing}
		onRefresh={refresh}
	/>

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
			<strong>Datos en vivo desde el RUFE (FR-1703-SMD-69).</strong> El tablero se conecta directo a la
			hoja de Google del consolidado y se refresca solo cada 3 minutos. El género se toma directo de la
			columna "Identidad de género" del formulario y puede contener registros sin diligenciar; la zona
			(rural/urbana) se infiere del corregimiento reportado. Cifras de apoyo operativo para la respuesta
			a la emergencia, no un censo certificado.
		</div>
	</div>

	<div class="filterbar">
		<ZonaFilter bind:zona />
		<SearchBox bind:query placeholder="Buscar barrio o vereda…" />
	</div>

	<div class="hero-row">
		<div class="hero-total">
			<div class="label">{heroScopeLabel}</div>
			<div class="value">
				{fmt(agg.total)}{#if isFiltered}<small> de {fmt(DATA.total)}</small>{/if}
			</div>
		</div>
		<HogaresCriticosBadge top={top20Criticos} total={hogaresCriticos.length} />
	</div>

	<div class="kpi-grid">
		<KpiTile
			label="Mujeres"
			value={agg.F}
			color="var(--series-mujeres)"
			icon={Venus}
			sub="{pct(agg.F, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Hombres"
			value={agg.M}
			color="var(--series-hombres)"
			icon={Mars}
			sub="{pct(agg.M, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Niños (0–11)"
			value={agg.Ninos}
			color="var(--color-highlight-dark)"
			icon={Baby}
			sub="{pct(agg.Ninos, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Jóvenes (12–28)"
			value={agg.Jovenes}
			color="var(--color-secondary-dark)"
			icon={GraduationCap}
			sub="{pct(agg.Jovenes, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Adultos (29–59)"
			value={agg.Adultos}
			color="var(--seq-adultos)"
			icon={User}
			sub="{pct(agg.Adultos, agg.total)}% del total filtrado"
		/>
		<KpiTile
			label="Ad. mayores (60+)"
			value={agg.AdultosMayores}
			color="var(--seq-mayores)"
			icon={Glasses}
			sub="{pct(agg.AdultosMayores, agg.total)}% del total filtrado"
		/>
	</div>

	<div class="chart-grid">
		<div class="card">
			<div class="card-head">
				<div>
					<h2><House size={16} strokeWidth={2.25} aria-hidden="true" /> Hogares registrados</h2>
					<p class="card-note">
						Personas agrupadas por número de hogar (código de familia del RUFE) — un hogar puede
						tener varios integrantes
					</p>
				</div>
			</div>
			<div class="hogares-total">
				<span class="hogares-total-value">{fmt(hogaresAgg.count)}</span>
				<span class="hogares-total-label">hogares</span>
			</div>
			{#if zona === 'todas'}
				<div class="bars">
					<BarRow
						label="Urbana"
						value={hogaresAgg.urbana}
						max={maxHogaresZona}
						color="var(--series-mujeres)"
					/>
					<BarRow
						label="Rural"
						value={hogaresAgg.rural}
						max={maxHogaresZona}
						color="var(--series-hombres)"
					/>
				</div>
			{/if}
			<p class="card-note hogares-promedio">
				Promedio de <b>{promedioPersonasPorHogar}</b> personas por hogar, dentro del filtro activo
			</p>
		</div>

		{#if zona === 'todas'}
			<div class="card">
				<div class="card-head">
					<div>
						<h2><MapPin size={16} strokeWidth={2.25} aria-hidden="true" /> Zona rural / urbana</h2>
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
					<h2><CalendarDays size={16} strokeWidth={2.25} aria-hidden="true" /> Grupo de edad</h2>
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
				<BarRow
					label="Adultos 29–59"
					value={agg.Adultos}
					max={maxEdad}
					color="var(--seq-adultos)"
				/>
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
					<h2><VenusAndMars size={16} strokeWidth={2.25} aria-hidden="true" /> {generoTitle}</h2>
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
					<h2><BarChart3 size={16} strokeWidth={2.25} aria-hidden="true" /> {rankingTitle}</h2>
					<p class="card-note">Los 12 con mayor número de personas, dentro del filtro activo</p>
				</div>
			</div>
			{#if ranked.length === 0}
				<p class="card-note">Sin resultados para este filtro.</p>
			{:else}
				<div class="bars">
					{#each ranked as b (b.name + '::' + b.zona)}
						<BarRow
							label={rankedNameCounts[b.name] > 1 ? `${b.name} (${b.zona})` : b.name}
							value={b.total}
							max={maxRanked}
							color="var(--seq-adultos)"
						/>
					{/each}
				</div>
			{/if}
		</div>

		<div class="card">
			<div class="card-head">
				<div>
					<h2><ShieldAlert size={16} strokeWidth={2.25} aria-hidden="true" /> Estado del bien</h2>
					<p class="card-note">
						{fmt(hogaresAgg.count)} hogares con predio identificado, dentro del filtro activo
					</p>
				</div>
			</div>
			{#if estadoRows.length === 0}
				<p class="card-note">Sin datos de estado del bien para este filtro.</p>
			{:else}
				<div class="bars">
					{#each estadoRows as r (r.name)}
						<BarRow label={r.name} value={r.value} max={maxEstado} color={ESTADO_COLOR[r.name]} />
					{/each}
					{#if estadoSinDato > 0}
						<BarRow label="Sin dato" value={estadoSinDato} max={maxEstado} color="" dim />
					{/if}
				</div>
			{/if}
		</div>

		<div class="card">
			<div class="card-head">
				<div>
					<h2><Building2 size={16} strokeWidth={2.25} aria-hidden="true" /> Tipo de bien</h2>
					<p class="card-note">Vivienda, local comercial, finca, etc.</p>
				</div>
			</div>
			<div class="bars">
				{#each tipoRows as r (r.name)}
					<BarRow
						label={r.name}
						value={r.value}
						max={maxTipo}
						color={r.name === 'Sin dato' ? '' : 'var(--seq-adultos)'}
						dim={r.name === 'Sin dato'}
					/>
				{/each}
			</div>
		</div>

		<div class="card">
			<div class="card-head">
				<div>
					<h2><KeyRound size={16} strokeWidth={2.25} aria-hidden="true" /> Forma de tenencia</h2>
					<p class="card-note">Propietario, arrendatario, poseedor u ocupante del predio</p>
				</div>
			</div>
			<div class="bars">
				{#each tenenciaRows as r (r.name)}
					<BarRow label={r.name} value={r.value} max={maxTenencia} color={r.color} />
				{/each}
				{#if tenenciaSinDato > 0}
					<BarRow label="Sin dato" value={tenenciaSinDato} max={maxTenencia} color="" dim />
				{/if}
			</div>
		</div>

		<div class="card span-2">
			<div class="card-head">
				<div>
					<h2>
						<ClipboardCheck size={16} strokeWidth={2.25} aria-hidden="true" /> Visitas técnicas
					</h2>
					<p class="card-note">Si ya se realizó la visita de verificación al predio</p>
				</div>
			</div>
			<div class="bars">
				<BarRow
					label="Realizada"
					value={hogaresAgg.visitaSi}
					max={maxVisita}
					color="var(--status-good)"
				/>
				<BarRow
					label="Pendiente"
					value={hogaresAgg.visitaNo}
					max={maxVisita}
					color="var(--status-warning)"
				/>
				{#if hogaresAgg.visitaSinDato > 0}
					<BarRow label="Sin dato" value={hogaresAgg.visitaSinDato} max={maxVisita} color="" dim />
				{/if}
			</div>
			{#if hogaresAgg.visitantes.length > 0}
				<div class="visitantes">
					<p class="card-note visitantes-label">Quién realizó la visita</p>
					<div class="visitantes-tags">
						{#each hogaresAgg.visitantes.slice(0, 12) as v (v.nombre)}
							<span class="visitante-tag">{v.nombre} <b>{v.count}</b></span>
						{/each}
					</div>
				</div>
			{/if}
		</div>

		<div class="card span-2">
			<div class="card-head">
				<div>
					<h2><Siren size={16} strokeWidth={2.25} aria-hidden="true" /> Personal evacuado</h2>
					<p class="card-note">
						Personas cuyo hogar fue evacuado tras el sismo, dentro del filtro activo
					</p>
				</div>
			</div>
			<div class="evac-total">
				<span class="evac-total-value critical">{fmt(hogaresAgg.personasEvacuadas)}</span>
				<span class="evac-total-label"
					>personas evacuadas · {fmt(hogaresAgg.evacuadaSi)} hogares</span
				>
			</div>
			<div class="bars">
				<BarRow
					label="Evacuadas"
					value={hogaresAgg.personasEvacuadas}
					max={maxPersonasEvac}
					color="var(--color-accent)"
				/>
				<BarRow
					label="No evacuadas"
					value={hogaresAgg.personasNoEvacuadas}
					max={maxPersonasEvac}
					color="var(--color-secondary)"
				/>
				{#if hogaresAgg.personasSinDatoEvacuacion > 0}
					<BarRow
						label="Sin dato"
						value={hogaresAgg.personasSinDatoEvacuacion}
						max={maxPersonasEvac}
						color=""
						dim
					/>
				{/if}
			</div>
		</div>

		<div class="card span-2">
			<div class="card-head">
				<div>
					<h2>
						<MessageSquareText size={16} strokeWidth={2.25} aria-hidden="true" /> Observaciones
					</h2>
					<p class="card-note">
						{fmt(hogaresAgg.conObservacion)} de {fmt(hogaresAgg.count)} hogares tienen una observación
						registrada
					</p>
				</div>
			</div>
			{#if obsTags.length > 0}
				<div class="bars obs-tags">
					{#each obsTags as t (t.label)}
						<BarRow label={t.label} value={t.count} max={maxObsTag} color="var(--seq-jovenes)" />
					{/each}
				</div>
			{/if}
			<ObservacionesList items={obsList} />
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2>
					<Table2 size={16} strokeWidth={2.25} aria-hidden="true" /> Detalle por barrio / vereda
				</h2>
				<p class="card-note">
					{fmt(sortedRows.length)} barrios/veredas · toca un encabezado para ordenar · desliza para ver
					todas las columnas
				</p>
			</div>
		</div>
		<BarrioTable rows={sortedRows} {sortKey} {sortDir} onSort={toggleSort} />
	</div>

	<footer>
		<p>
			<strong>RUFE</strong> — Registro consolidado de familias/personas afectadas, código FR-1703-SMD-69
			(SMD-ERE), Alcaldía Municipal de Jamundí.
		</p>
		<p>
			Grupos de edad: Niños 0–11 años · Jóvenes 12–28 años · Adultos 29–59 años · Adultos mayores 60
			años o más. "Sin dato" agrupa registros sin fecha de nacimiento o sin identidad de género
			diligenciada en el formulario.
		</p>
		<p>
			El tablero lee la hoja de Google directamente desde el navegador de quien lo visita — no pasa
			por ningún servidor propio. Si la hoja deja de estar compartida como "Cualquiera con el
			enlace", el tablero sigue mostrando el último snapshot descargado (ver aviso arriba).
		</p>
	</footer>
</div>

<style>
	/* Estructura compartida (.wrap, .card, .chart-grid, .bars, etc.) vive en
	   $lib/dashboard.css, importado desde +layout.svelte — aquí solo queda
	   lo específico del contenido de esta página. */

	.hero-row {
		display: flex;
		gap: 12px;
		flex-wrap: wrap;
		align-items: stretch;
	}
	.hero-row .hero-total {
		flex: 1;
		min-width: 220px;
	}

	.hogares-total {
		display: flex;
		align-items: baseline;
		gap: 7px;
		margin-bottom: 12px;
	}
	.hogares-total-value {
		font-size: clamp(26px, 7vw, 32px);
		font-weight: 800;
		letter-spacing: -0.01em;
		color: var(--color-text);
		font-variant-numeric: tabular-nums;
	}
	.hogares-total-label {
		font-size: 12.5px;
		font-weight: 600;
		color: var(--color-muted);
	}
	.hogares-promedio {
		margin-top: 12px;
		padding-top: 12px;
		border-top: 1px dashed var(--color-border);
	}
	.hogares-promedio b {
		color: var(--color-text);
		font-variant-numeric: tabular-nums;
	}

	.evac-total {
		display: flex;
		align-items: baseline;
		flex-wrap: wrap;
		gap: 7px;
		margin-bottom: 12px;
	}
	.evac-total-value {
		font-size: clamp(26px, 7vw, 32px);
		font-weight: 800;
		letter-spacing: -0.01em;
		font-variant-numeric: tabular-nums;
	}
	.evac-total-value.critical {
		color: var(--color-accent);
	}
	.evac-total-label {
		font-size: 12.5px;
		font-weight: 600;
		color: var(--color-muted);
	}

	.visitantes {
		margin-top: 14px;
		padding-top: 12px;
		border-top: 1px dashed var(--color-border);
	}
	.visitantes-label {
		margin: 0 0 8px;
	}
	.visitantes-tags {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}
	.visitante-tag {
		font-size: 11.5px;
		font-weight: 600;
		color: var(--color-text);
		background: var(--color-surface-alt);
		border: 1px solid var(--color-border);
		border-radius: var(--radius-full);
		padding: 3px 9px;
	}
	.visitante-tag b {
		color: var(--color-primary-dark);
		font-weight: 800;
	}

	.obs-tags {
		margin-bottom: 14px;
		padding-bottom: 14px;
		border-bottom: 1px dashed var(--color-border);
	}
	/* Las etiquetas de esta tarjeta ("Riesgo colapso", "Fuga agua/gas") son
	   más largas que un nombre de barrio típico — la columna angosta por
	   defecto de BarRow las corta demasiado. */
	.obs-tags :global(.bar-row) {
		grid-template-columns: 118px 1fr 34px;
	}
	@media (min-width: 520px) {
		.obs-tags :global(.bar-row) {
			grid-template-columns: 148px 1fr 38px;
		}
	}
</style>
