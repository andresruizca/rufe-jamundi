<script lang="ts">
	import { onMount } from 'svelte';
	import { INST_EDUCATIVAS_FALLBACK } from '$lib/instEducativas/data';
	import type { InstEducativasDataset } from '$lib/instEducativas/types';
	import type { Zona } from '$lib/data';
	import { fetchLiveInstEducativas } from '$lib/instEducativas/live';
	import {
		aggregateInstEducativas,
		filterSedes,
		sortSedes,
		listDanos
	} from '$lib/instEducativasAggregate';
	import type { SedesSortKey } from '$lib/instEducativasAggregate';
	import ZonaFilter from '$lib/components/ZonaFilter.svelte';
	import SearchBox from '$lib/components/SearchBox.svelte';
	import KpiTile from '$lib/components/KpiTile.svelte';
	import BarRow from '$lib/components/BarRow.svelte';
	import SedesTable from '$lib/components/SedesTable.svelte';
	import LiveStatus from '$lib/components/LiveStatus.svelte';
	import DanosList from '$lib/components/DanosList.svelte';
	import {
		MapPin,
		ShieldAlert,
		DoorOpen,
		Wrench,
		Construction,
		CalendarX,
		FileText,
		Flag,
		Tent,
		BarChart3,
		Table2,
		TriangleAlert
	} from '@lucide/svelte';

	const REFRESH_MS = 3 * 60 * 1000;

	let liveDataset = $state<InstEducativasDataset | null>(null);
	let liveStatus = $state<'loading' | 'live' | 'stale'>('loading');
	let liveError = $state<string | undefined>(undefined);
	let refreshing = $state(false);

	const DATA = $derived(liveDataset ?? INST_EDUCATIVAS_FALLBACK);

	async function refresh() {
		refreshing = true;
		try {
			liveDataset = await fetchLiveInstEducativas();
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
	let sortKey = $state<SedesSortKey>('matricula');
	let sortDir = $state<1 | -1>(-1);

	const filtered = $derived(filterSedes(DATA.sedes, zona, query));
	const agg = $derived(aggregateInstEducativas(filtered));
	const sortedRows = $derived(sortSedes(filtered, sortKey, sortDir));
	const danos = $derived(listDanos(filtered));

	const ranked = $derived([...filtered].sort((a, b) => b.matricula - a.matricula).slice(0, 12));
	const maxRanked = $derived(Math.max(...ranked.map((s) => s.matricula), 1));

	const isFiltered = $derived(zona !== 'todas' || query.length > 0);
	const heroScopeLabel = $derived(
		(query ? `Resultados para "${query}" · ` : 'Sedes educativas · ') +
			(zona === 'todas' ? 'todas las zonas' : `zona ${zona}`)
	);

	const fmt = (n: number) => n.toLocaleString('es-CO');
	const maxZona = $derived(Math.max(agg.urbana, agg.rural, 1));

	const ESTADO_ORDER = [
		'Afectación menor',
		'Afectación parcial',
		'Colapso parcial',
		'Riesgo inminente de colapso'
	];
	const ESTADO_COLOR: Record<string, string> = {
		'Afectación menor': 'var(--status-warning)',
		'Afectación parcial': 'var(--status-serious)',
		'Colapso parcial': 'var(--status-critical)',
		'Riesgo inminente de colapso': 'var(--status-critical)'
	};
	const estadoRows = $derived(
		ESTADO_ORDER.map((name) => ({ name, value: agg.estadoFisico[name] ?? 0 })).filter(
			(r) => r.value > 0
		)
	);
	const estadoSinDato = $derived(agg.estadoFisico['Sin dato'] ?? 0);
	const maxEstado = $derived(Math.max(...estadoRows.map((r) => r.value), estadoSinDato, 1));

	const PRIORIDAD_ORDER = ['Inmediata', 'Alta', 'Media', 'Baja'];
	const PRIORIDAD_COLOR: Record<string, string> = {
		Inmediata: 'var(--status-critical)',
		Alta: 'var(--status-serious)',
		Media: 'var(--status-warning)',
		Baja: 'var(--status-good)'
	};
	const prioridadRows = $derived(
		PRIORIDAD_ORDER.map((name) => ({ name, value: agg.prioridad[name] ?? 0 })).filter(
			(r) => r.value > 0
		)
	);
	const prioridadSinDato = $derived(agg.prioridad['Sin dato'] ?? 0);
	const maxPrioridad = $derived(
		Math.max(...prioridadRows.map((r) => r.value), prioridadSinDato, 1)
	);

	const maxEvac = $derived(
		Math.max(agg.requiereEvacuacion['Sí'] ?? 0, agg.requiereEvacuacion['No'] ?? 0, 1)
	);
	const maxVisita = $derived(
		Math.max(agg.requiereVisitaTecnica['Sí'] ?? 0, agg.requiereVisitaTecnica['No'] ?? 0, 1)
	);
	const maxVias = $derived(Math.max(agg.viasDeAcceso['Sí'] ?? 0, agg.viasDeAcceso['No'] ?? 0, 1));
	const maxClases = $derived(
		Math.max(agg.suspendieronClases['Sí'] ?? 0, agg.suspendieronClases['No'] ?? 0, 1)
	);
	const maxConcepto = $derived(
		Math.max(
			agg.conceptoTecnico['Sí'] ?? 0,
			agg.conceptoTecnico['No'] ?? 0,
			agg.conceptoTecnico['En proceso'] ?? 0,
			1
		)
	);
	const maxAlbergue = $derived(
		Math.max(agg.usadaComoAlbergue['Sí'] ?? 0, agg.usadaComoAlbergue['No'] ?? 0, 1)
	);

	const TEXT_KEYS = new Set<SedesSortKey>([
		'sede',
		'establecimiento',
		'barrio',
		'zona',
		'estadoFisico',
		'prioridad'
	]);
	function toggleSort(key: SedesSortKey) {
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
		<strong>Datos del formulario FR-1703-SMD-69, pestaña Instituciones Educativas.</strong> Varias columnas
		(vías de acceso, suspensión de clases, concepto técnico, visita técnica) traen respuestas de texto
		libre inconsistentes y se agrupan por palabra clave. "Prioridad de atención" mezcla niveles reales
		(Alta/Media/Baja/Inmediata) con respuestas sueltas "SI/NO" que no indican nivel — esas se muestran
		como "Sin dato" en vez de adivinarlas. Cifras de apoyo operativo, no un reporte técnico certificado.
	</div>
</div>

<div class="filterbar">
	<ZonaFilter bind:zona />
	<SearchBox
		bind:query
		placeholder="Buscar sede, institución o barrio…"
		label="Buscar sede, institución o barrio"
		id="sede-search"
	/>
</div>

<div class="hero-total">
	<div class="label">{heroScopeLabel}</div>
	<div class="value">
		{fmt(agg.sedes)}{#if isFiltered}<small> de {fmt(DATA.sedes.length)}</small>{/if}
	</div>
</div>

<div class="kpi-grid">
	<KpiTile
		label="Establecimientos"
		value={agg.establecimientos}
		color="var(--color-primary)"
		sub="instituciones únicas, dentro del filtro"
	/>
	<KpiTile
		label="Matrícula total"
		value={agg.matricula}
		color="var(--series-mujeres)"
		sub="estudiantes matriculados"
	/>
	<KpiTile
		label="Estudiantes afectados"
		value={agg.estudiantesAfectados}
		color="var(--color-accent)"
		sub="estimado, dentro del filtro"
	/>
	<KpiTile
		label="Requieren evacuación"
		value={agg.requiereEvacuacion['Sí'] ?? 0}
		color="var(--status-critical)"
		sub="sedes, dentro del filtro"
	/>
</div>

<div class="chart-grid">
	<div class="card">
		<div class="card-head">
			<div>
				<h2><MapPin size={16} strokeWidth={2.25} aria-hidden="true" /> Zona rural / urbana</h2>
				<p class="card-note">Sedes por zona, según la columna ZONA del formulario</p>
			</div>
		</div>
		<div class="bars">
			<BarRow label="Urbana" value={agg.urbana} max={maxZona} color="var(--series-mujeres)" />
			<BarRow label="Rural" value={agg.rural} max={maxZona} color="var(--series-hombres)" />
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2>
					<ShieldAlert size={16} strokeWidth={2.25} aria-hidden="true" /> Estado físico de la infraestructura
				</h2>
				<p class="card-note">{fmt(agg.sedes)} sedes, dentro del filtro activo</p>
			</div>
		</div>
		{#if estadoRows.length === 0}
			<p class="card-note">Sin datos de estado físico para este filtro.</p>
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
				<h2><DoorOpen size={16} strokeWidth={2.25} aria-hidden="true" /> Requiere evacuación</h2>
				<p class="card-note">Sedes cuya infraestructura requiere evacuar</p>
			</div>
		</div>
		<div class="bars">
			<BarRow
				label="Sí"
				value={agg.requiereEvacuacion['Sí'] ?? 0}
				max={maxEvac}
				color="var(--status-critical)"
			/>
			{#if agg.requiereEvacuacion['Parcial']}
				<BarRow
					label="Parcial"
					value={agg.requiereEvacuacion['Parcial']}
					max={maxEvac}
					color="var(--status-serious)"
				/>
			{/if}
			<BarRow
				label="No"
				value={agg.requiereEvacuacion['No'] ?? 0}
				max={maxEvac}
				color="var(--status-good)"
			/>
			{#if agg.requiereEvacuacion['Sin dato']}
				<BarRow
					label="Sin dato"
					value={agg.requiereEvacuacion['Sin dato']}
					max={maxEvac}
					color=""
					dim
				/>
			{/if}
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2><Wrench size={16} strokeWidth={2.25} aria-hidden="true" /> Requiere visita técnica</h2>
				<p class="card-note">Sedes pendientes de verificación en sitio</p>
			</div>
		</div>
		<div class="bars">
			<BarRow
				label="Sí"
				value={agg.requiereVisitaTecnica['Sí'] ?? 0}
				max={maxVisita}
				color="var(--status-warning)"
			/>
			<BarRow
				label="No"
				value={agg.requiereVisitaTecnica['No'] ?? 0}
				max={maxVisita}
				color="var(--status-good)"
			/>
			{#if agg.requiereVisitaTecnica['Sin dato']}
				<BarRow
					label="Sin dato"
					value={agg.requiereVisitaTecnica['Sin dato']}
					max={maxVisita}
					color=""
					dim
				/>
			{/if}
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2><Construction size={16} strokeWidth={2.25} aria-hidden="true" /> Vías de acceso</h2>
				<p class="card-note">Si las vías permiten llegar a la sede</p>
			</div>
		</div>
		<div class="bars">
			<BarRow
				label="Permiten acceso"
				value={agg.viasDeAcceso['Sí'] ?? 0}
				max={maxVias}
				color="var(--status-good)"
			/>
			<BarRow
				label="No permiten"
				value={agg.viasDeAcceso['No'] ?? 0}
				max={maxVias}
				color="var(--status-serious)"
			/>
			{#if agg.viasDeAcceso['Sin dato']}
				<BarRow label="Sin dato" value={agg.viasDeAcceso['Sin dato']} max={maxVias} color="" dim />
			{/if}
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2><CalendarX size={16} strokeWidth={2.25} aria-hidden="true" /> ¿Se suspendieron clases?</h2>
				<p class="card-note">Sedes que suspendieron clases tras el sismo</p>
			</div>
		</div>
		<div class="bars">
			<BarRow
				label="Sí"
				value={agg.suspendieronClases['Sí'] ?? 0}
				max={maxClases}
				color="var(--status-warning)"
			/>
			<BarRow
				label="No"
				value={agg.suspendieronClases['No'] ?? 0}
				max={maxClases}
				color="var(--status-good)"
			/>
			{#if agg.suspendieronClases['Sin dato']}
				<BarRow
					label="Sin dato"
					value={agg.suspendieronClases['Sin dato']}
					max={maxClases}
					color=""
					dim
				/>
			{/if}
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2><FileText size={16} strokeWidth={2.25} aria-hidden="true" /> Concepto técnico disponible</h2>
				<p class="card-note">Si ya existe un concepto técnico sobre la infraestructura</p>
			</div>
		</div>
		<div class="bars">
			<BarRow
				label="Sí"
				value={agg.conceptoTecnico['Sí'] ?? 0}
				max={maxConcepto}
				color="var(--status-good)"
			/>
			<BarRow
				label="En proceso"
				value={agg.conceptoTecnico['En proceso'] ?? 0}
				max={maxConcepto}
				color="var(--status-warning)"
			/>
			<BarRow
				label="No"
				value={agg.conceptoTecnico['No'] ?? 0}
				max={maxConcepto}
				color="var(--status-serious)"
			/>
			{#if agg.conceptoTecnico['Sin dato']}
				<BarRow
					label="Sin dato"
					value={agg.conceptoTecnico['Sin dato']}
					max={maxConcepto}
					color=""
					dim
				/>
			{/if}
		</div>
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2><Flag size={16} strokeWidth={2.25} aria-hidden="true" /> Prioridad de atención</h2>
				<p class="card-note">Nivel de prioridad reportado para la sede</p>
			</div>
		</div>
		{#if prioridadRows.length === 0}
			<p class="card-note">Sin datos de prioridad para este filtro.</p>
		{:else}
			<div class="bars">
				{#each prioridadRows as r (r.name)}
					<BarRow
						label={r.name}
						value={r.value}
						max={maxPrioridad}
						color={PRIORIDAD_COLOR[r.name]}
					/>
				{/each}
				{#if prioridadSinDato > 0}
					<BarRow label="Sin dato" value={prioridadSinDato} max={maxPrioridad} color="" dim />
				{/if}
			</div>
		{/if}
	</div>

	<div class="card">
		<div class="card-head">
			<div>
				<h2><Tent size={16} strokeWidth={2.25} aria-hidden="true" /> Usada como albergue</h2>
				<p class="card-note">Si la sede se está usando como albergue temporal</p>
			</div>
		</div>
		<div class="bars">
			<BarRow
				label="Sí"
				value={agg.usadaComoAlbergue['Sí'] ?? 0}
				max={maxAlbergue}
				color="var(--color-secondary)"
			/>
			<BarRow label="No" value={agg.usadaComoAlbergue['No'] ?? 0} max={maxAlbergue} color="" dim />
		</div>
	</div>

	<div class="card span-2">
		<div class="card-head">
			<div>
				<h2><BarChart3 size={16} strokeWidth={2.25} aria-hidden="true" /> Sedes con mayor matrícula</h2>
				<p class="card-note">Los 12 con más estudiantes matriculados, dentro del filtro activo</p>
			</div>
		</div>
		{#if ranked.length === 0}
			<p class="card-note">Sin resultados para este filtro.</p>
		{:else}
			<div class="bars">
				{#each ranked as s (s.sede + s.establecimiento)}
					<BarRow label={s.sede} value={s.matricula} max={maxRanked} color="var(--seq-adultos)" />
				{/each}
			</div>
		{/if}
	</div>

	<div class="card span-2">
		<div class="card-head">
			<div>
				<h2><TriangleAlert size={16} strokeWidth={2.25} aria-hidden="true" /> Daños y observaciones</h2>
				<p class="card-note">
					{fmt(agg.conDanosObservados)} de {fmt(agg.sedes)} sedes tienen daños descritos
				</p>
			</div>
		</div>
		<DanosList items={danos} />
	</div>
</div>

<div class="card">
	<div class="card-head">
		<div>
			<h2><Table2 size={16} strokeWidth={2.25} aria-hidden="true" /> Detalle por sede</h2>
			<p class="card-note">
				{fmt(sortedRows.length)} sedes · toca un encabezado para ordenar · desliza para ver todas las
				columnas
			</p>
		</div>
	</div>
	<SedesTable rows={sortedRows} {sortKey} {sortDir} onSort={toggleSort} />
</div>

<footer>
	<p>
		<strong>RUFE</strong> — Registro consolidado, pestaña Instituciones Educativas, código FR-1703-SMD-69
		(SMD-ERE), Alcaldía Municipal de Jamundí.
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
