<script lang="ts">
	// Bandeja de fichas RUFE pendientes de validar.
	//
	// La lista no trae nombres ni documentos: para decidir qué revisar bastan el
	// evento, el lugar y la fecha. Los datos identificatorios solo aparecen al
	// abrir un reporte, que es la acción que queda registrada en auditoría.

	import { onMount } from 'svelte';
	import { FileWarning, Flag, LoaderCircle, Search, X } from '@lucide/svelte';
	import { ApiError } from '$lib/api/client';
	import { rufeApi, type FiltrosReportes } from '$lib/api/servicios';
	import type { Paginacion, ReporteResumen } from '$lib/rufe-form/tipos';
	import { fechaHora, soloFecha } from '$lib/formato';

	let reportes = $state<ReporteResumen[]>([]);
	let paginacion = $state<Paginacion | null>(null);
	let cargando = $state(true);
	let error = $state<string | null>(null);

	let filtros = $state<FiltrosReportes>({ estado: '', zona: '', q: '', pagina: 1 });

	const ESTADOS = [
		{ codigo: '', etiqueta: 'Todos los estados' },
		{ codigo: 'RECIBIDO', etiqueta: 'Recibido' },
		{ codigo: 'EN_VALIDACION', etiqueta: 'En validación' },
		{ codigo: 'VALIDADO', etiqueta: 'Validado' },
		{ codigo: 'RECHAZADO', etiqueta: 'Rechazado' },
		{ codigo: 'ARCHIVADO', etiqueta: 'Archivado' }
	];

	const ZONAS = [
		{ codigo: '', etiqueta: 'Urbano y rural' },
		{ codigo: 'URBANO', etiqueta: 'Urbano' },
		{ codigo: 'RURAL', etiqueta: 'Rural' }
	];

	onMount(cargar);

	async function cargar() {
		cargando = true;
		error = null;

		try {
			const datos = await rufeApi.listar(filtros);
			reportes = datos.reportes;
			paginacion = datos.paginacion;
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudieron cargar los reportes.';
		} finally {
			cargando = false;
		}
	}

	function aplicar() {
		filtros.pagina = 1;
		void cargar();
	}

	function limpiar() {
		filtros = { estado: '', zona: '', q: '', pagina: 1 };
		void cargar();
	}

	function irAPagina(n: number) {
		filtros.pagina = n;
		void cargar();
	}

	function claseEstado(estado: string): string {
		return `pastilla pastilla--${estado.toLowerCase()}`;
	}

	const hayFiltros = $derived(!!(filtros.estado || filtros.zona || filtros.q));
</script>

<div class="tarjeta">
	<h2 class="tarjeta__titulo">Fichas RUFE</h2>
	<p class="tarjeta__nota">
		Registro Unifamiliar de Emergencias levantado en campo por los funcionarios desde
		<strong>Registro</strong>. Las fichas entran en estado «Recibido» y no son oficiales hasta
		que un gestor les da el Vo.Bo.
	</p>

	<form
		class="filtros"
		onsubmit={(e) => {
			e.preventDefault();
			aplicar();
		}}
	>
		<div class="campo filtros__buscar">
			<label class="campo__etiqueta" for="filtro-q">Buscar</label>
			<input
				id="filtro-q"
				class="campo__control"
				type="search"
				placeholder="Radicado, dirección, barrio o evento"
				bind:value={filtros.q}
			/>
		</div>

		<div class="campo">
			<label class="campo__etiqueta" for="filtro-estado">Estado</label>
			<select id="filtro-estado" class="campo__control" bind:value={filtros.estado}>
				{#each ESTADOS as e (e.codigo)}<option value={e.codigo}>{e.etiqueta}</option>{/each}
			</select>
		</div>

		<div class="campo">
			<label class="campo__etiqueta" for="filtro-zona">Zona</label>
			<select id="filtro-zona" class="campo__control" bind:value={filtros.zona}>
				{#each ZONAS as z (z.codigo)}<option value={z.codigo}>{z.etiqueta}</option>{/each}
			</select>
		</div>

		<div class="filtros__acciones">
			<button type="submit" class="boton">
				<Search size={15} aria-hidden="true" />
				Filtrar
			</button>
			{#if hayFiltros}
				<button type="button" class="boton boton--suave" onclick={limpiar}>
					<X size={15} aria-hidden="true" />
					Limpiar
				</button>
			{/if}
		</div>
	</form>

	{#if error}
		<p class="aviso aviso--error" role="alert">{error}</p>
	{/if}

	{#if cargando}
		<p class="cargando">
			<LoaderCircle size={18} class="girando" aria-hidden="true" />
			Cargando reportes…
		</p>
	{:else if reportes.length === 0}
		<p class="vacio">
			{hayFiltros
				? 'Ninguna ficha coincide con los filtros aplicados.'
				: 'Todavía no se ha registrado ninguna ficha.'}
		</p>
	{:else}
		<div class="tabla-envoltura">
			<table class="tabla">
				<caption class="visualmente-oculto">
					Fichas RUFE registradas, ordenadas por fecha
				</caption>
				<thead>
					<tr>
						<th scope="col">Radicado</th>
						<th scope="col">Evento</th>
						<th scope="col">Ubicación</th>
						<th scope="col">Fecha del evento</th>
						<th scope="col">Personas</th>
						<th scope="col">Estado</th>
						<th scope="col">Recibido</th>
					</tr>
				</thead>
				<tbody>
					{#each reportes as reporte (reporte.id)}
						<!-- El enlace va en la celda y no en la fila entera: una <tr> con
						     onclick no es alcanzable con teclado ni se anuncia como enlace. -->
						<tr class="fila" class:fila--prioritaria={reporte.revision_prioritaria}>
							<td>
								<a class="radicado" href="/riesgo/reportes/{reporte.id}">
									{reporte.radicado}
								</a>
								{#if reporte.revision_prioritaria}
									<span class="marca-prioridad" title="Marcado para revisión prioritaria">
										<Flag size={13} aria-hidden="true" />
										<span class="visualmente-oculto">Revisión prioritaria</span>
									</span>
								{/if}
								{#if reporte.anonimizado}
									<span class="etiqueta etiqueta--inactivo">Anonimizado</span>
								{/if}
							</td>
							<td>{reporte.evento}</td>
							<td>
								{reporte.vereda_sector_barrio}
								<span class="sub">
									{reporte.zona_etiqueta}{reporte.corregimiento
										? ` · ${reporte.corregimiento}`
										: ''}
								</span>
							</td>
							<td>{soloFecha(reporte.fecha_evento)}</td>
							<td>
								{reporte.personas}
								{#if reporte.evidencias > 0}
									<span class="sub">{reporte.evidencias} foto(s)</span>
								{/if}
							</td>
							<td><span class={claseEstado(reporte.estado)}>{reporte.estado_etiqueta}</span></td>
							<td>{fechaHora(reporte.creado_en)}</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>

		{#if paginacion && paginacion.paginas > 1}
			<nav class="paginacion" aria-label="Paginación de reportes">
				<button
					type="button"
					class="boton boton--suave"
					disabled={paginacion.pagina <= 1}
					onclick={() => irAPagina(paginacion!.pagina - 1)}
				>
					Anterior
				</button>

				<span class="paginacion__texto" aria-live="polite">
					Página {paginacion.pagina} de {paginacion.paginas} · {paginacion.total} reportes
				</span>

				<button
					type="button"
					class="boton boton--suave"
					disabled={paginacion.pagina >= paginacion.paginas}
					onclick={() => irAPagina(paginacion!.pagina + 1)}
				>
					Siguiente
				</button>
			</nav>
		{/if}
	{/if}
</div>

<div class="tarjeta">
	<h2 class="tarjeta__titulo">
		<FileWarning size={16} aria-hidden="true" />
		Sobre estos datos
	</h2>
	<p class="tarjeta__nota">
		Cada ficha contiene datos personales y datos sensibles (identidad de género y pertenencia
		étnica) de todo un núcleo familiar. Abrirla queda registrado en la auditoría del sistema con
		su usuario y la fecha.
	</p>
</div>

<style>
	.filtros {
		display: grid;
		gap: 0.6rem;
		margin-bottom: 1rem;
	}

	@media (min-width: 760px) {
		.filtros {
			grid-template-columns: 2fr 1fr 1fr auto;
			align-items: end;
		}
	}

	.filtros .campo {
		margin-bottom: 0;
	}

	.filtros__acciones {
		display: flex;
		gap: 0.4rem;
	}

	.fila:hover {
		background: var(--color-surface-alt);
	}

	/* La prioridad no se marca solo con color: lleva además una banderita y su
	   texto alternativo. */
	.fila--prioritaria td:first-child {
		box-shadow: inset 3px 0 0 var(--color-warning);
	}

	.marca-prioridad {
		display: inline-flex;
		vertical-align: middle;
		margin-left: 0.3rem;
		color: var(--color-warning);
	}

	.radicado {
		font-family: ui-monospace, 'SFMono-Regular', monospace;
		font-size: 0.78rem;
		color: var(--color-primary-dark);
	}

	.sub {
		display: block;
		font-size: 0.74rem;
		color: var(--color-muted);
	}

	.pastilla {
		display: inline-block;
		padding: 0.15rem 0.5rem;
		border-radius: var(--radius-full);
		font-size: 0.72rem;
		font-weight: 600;
		border: 1px solid transparent;
	}

	.pastilla--recibido {
		background: var(--color-info-bg);
		color: var(--color-primary-dark);
		border-color: #bcd9f6;
	}

	.pastilla--en_validacion {
		background: #fff4dd;
		color: #8a5a00;
		border-color: #f2d69a;
	}

	.pastilla--validado {
		background: var(--color-success-bg);
		color: #146643;
		border-color: #b6e0cd;
	}

	.pastilla--rechazado {
		background: var(--color-danger-bg);
		color: #8f271c;
		border-color: #f0c2bc;
	}

	.pastilla--archivado {
		background: var(--color-surface-alt);
		color: var(--color-muted);
		border-color: var(--color-border);
	}

	.paginacion {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.6rem;
		margin-top: 1rem;
		flex-wrap: wrap;
	}

	.paginacion__texto {
		font-size: 0.8rem;
		color: var(--color-muted);
	}

	.visualmente-oculto {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
		white-space: nowrap;
	}
</style>
