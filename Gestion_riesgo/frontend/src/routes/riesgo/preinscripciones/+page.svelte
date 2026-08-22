<script lang="ts">
	// Las solicitudes que mandaron los ciudadanos, para revisarlas y decidir.
	//
	// Es la contraparte del formulario público: sin esta pantalla, lo que la
	// gente envía no lo ve nadie. Por defecto se muestran las que están sin
	// atender, que es el trabajo pendiente; el resto se consulta con el filtro.

	import { onMount } from 'svelte';
	import { IdCard, Image, Inbox, LoaderCircle, MapPin, Video } from '@lucide/svelte';
	import { ApiError } from '$lib/api/client';
	import { preinscripcionApi } from '$lib/api/servicios';
	import { fechaHora } from '$lib/formato';
	import IconoSenal from '$lib/preinscripcion/IconoSenal.svelte';

	type Fila = {
		id: number;
		radicado: string;
		nombre_completo: string;
		documento: string;
		telefono: string;
		correo: string | null;
		direccion: string;
		zona: 'URBANA' | 'RURAL' | null;
		corregimiento: string | null;
		vereda: string | null;
		estado: string;
		inspeccion_id: number | null;
		creado_en: string;
		/** Lo que marcó, con su dibujo. La etiqueta es la que se le mostró. */
		senales: { codigo: string; etiqueta: string; icono: string }[];
		fotos: number;
		cedula: boolean;
		videos: number;
		/** Solo si mandó punto GPS; las coordenadas no viajan al listado. */
		ubicada: boolean;
	};

	let filas = $state<Fila[]>([]);
	let total = $state(0);
	let pagina = $state(1);
	let estado = $state('RECIBIDA');
	let cargando = $state(true);
	let error = $state('');

	const paginas = $derived(Math.max(1, Math.ceil(total / 25)));

	const ESTADOS = [
		{ valor: 'RECIBIDA', etiqueta: 'Sin atender' },
		{ valor: 'EN_REVISION', etiqueta: 'En revisión' },
		{ valor: 'CONVERTIDA', etiqueta: 'Convertidas' },
		{ valor: 'DESCARTADA', etiqueta: 'Descartadas' },
		{ valor: '', etiqueta: 'Todas' }
	];

	const ETIQUETA_ESTADO: Record<string, string> = {
		RECIBIDA: 'Sin atender',
		EN_REVISION: 'En revisión',
		CONVERTIDA: 'Convertida',
		DESCARTADA: 'Descartada'
	};

	onMount(cargar);

	async function cargar() {
		cargando = true;
		error = '';

		try {
			const r = await preinscripcionApi.listar({ estado, pagina });
			filas = r.preinscripciones as unknown as Fila[];
			total = r.total;
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudieron cargar las solicitudes.';
		} finally {
			cargando = false;
		}
	}

	function filtrar(valor: string) {
		estado = valor;
		pagina = 1;
		void cargar();
	}

	function irA(n: number) {
		pagina = Math.min(Math.max(1, n), paginas);
		void cargar();
	}

	function lugar(f: Fila): string {
		return [f.direccion, f.vereda, f.corregimiento].filter(Boolean).join(' · ');
	}

	const ETIQUETA_ZONA: Record<string, string> = { URBANA: 'Urbana', RURAL: 'Rural' };
</script>

<div class="tarjeta">
	<p class="tarjeta__nota">
		Solicitudes que los ciudadanos enviaron desde el formulario público. Revíselas y conviértalas en
		inspección cuando corresponda.
	</p>

	<div class="filtros" role="group" aria-label="Filtrar por estado">
		{#each ESTADOS as e (e.valor)}
			<button
				type="button"
				class="boton boton--suave"
				class:filtro--activo={estado === e.valor}
				aria-pressed={estado === e.valor}
				onclick={() => filtrar(e.valor)}
			>
				{e.etiqueta}
			</button>
		{/each}
	</div>

	{#if error}<p class="aviso aviso--error" role="alert">{error}</p>{/if}

	{#if cargando}
		<p class="cargando"><LoaderCircle size={18} class="girando" aria-hidden="true" /> Cargando…</p>
	{:else if filas.length === 0}
		<p class="vacio">
			<Inbox size={22} aria-hidden="true" />
			No hay solicitudes en este estado.
		</p>
	{:else}
		<div class="tabla-envoltura">
			<table class="tabla">
				<thead>
					<tr>
						<th scope="col">Radicado</th>
						<th scope="col">Solicitante</th>
						<th scope="col">Vivienda</th>
						<th scope="col">Qué reportó</th>
						<th scope="col">Adjuntó</th>
						<th scope="col">Recibida</th>
						<th scope="col">Estado</th>
					</tr>
				</thead>
				<tbody>
					{#each filas as f (f.id)}
						<tr>
							<td class="radicado">
								<a href="/riesgo/preinscripciones/{f.id}">{f.radicado}</a>
							</td>
							<td>
								{f.nombre_completo}
								<small>C.C. {f.documento} · {f.telefono}</small>
								{#if f.correo}<small>{f.correo}</small>{/if}
							</td>
							<td>
								{lugar(f)}
								{#if f.zona}
									<small>
										Zona {ETIQUETA_ZONA[f.zona] ?? f.zona}
										{#if f.ubicada}
											· <span class="ubicada"><MapPin size={11} aria-hidden="true" /> con ubicación</span>
										{/if}
									</small>
								{/if}
							</td>

							<!--
								Los dibujos y no los nombres: son las mismas ocho figuras que
								vio el ciudadano al marcarlas, y de un vistazo separan un techo
								caído de una tubería rota sin leer nada. El nombre va en el
								`title` y en el texto para lector de pantalla, porque un dibujo
								sin palabra no es accesible.
							-->
							<td>
								{#if f.senales.length === 0}
									<span class="nada">Nada marcado</span>
								{:else}
									<ul class="senales">
										{#each f.senales as s (s.codigo)}
											<li class="senal" title={s.etiqueta}>
												<IconoSenal icono={s.icono} compacto />
												<span class="solo-lectores">{s.etiqueta}</span>
											</li>
										{/each}
									</ul>
								{/if}
							</td>

							<td>
								{#if !f.cedula && f.fotos === 0 && f.videos === 0}
									<span class="nada">Sin archivos</span>
								{:else}
									<ul class="adjuntos">
										{#if f.cedula}
											<li title="Mandó la foto de su cédula">
												<IdCard size={13} aria-hidden="true" />
												Cédula
											</li>
										{/if}
										{#if f.fotos > 0}
											<li title="Fotos del daño">
												<Image size={13} aria-hidden="true" />
												{f.fotos}
											</li>
										{/if}
										{#if f.videos > 0}
											<li title="Videos de la vivienda">
												<Video size={13} aria-hidden="true" />
												{f.videos}
											</li>
										{/if}
									</ul>
								{/if}
							</td>

							<td class="fecha">{fechaHora(f.creado_en)}</td>
							<td><span class="marca">{ETIQUETA_ESTADO[f.estado] ?? f.estado}</span></td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>

		{#if paginas > 1}
			<div class="paginacion">
				<button
					type="button"
					class="boton boton--suave"
					disabled={pagina <= 1}
					onclick={() => irA(pagina - 1)}
				>
					Anterior
				</button>
				<span>Página {pagina} de {paginas}</span>
				<button
					type="button"
					class="boton boton--suave"
					disabled={pagina >= paginas}
					onclick={() => irA(pagina + 1)}
				>
					Siguiente
				</button>
			</div>
		{/if}
	{/if}
</div>

<style>
	.filtros {
		display: flex;
		flex-wrap: wrap;
		gap: 0.4rem;
		margin: 0.8rem 0 1rem;
	}

	.filtro--activo {
		border-color: var(--color-primary);
		background: var(--color-info-bg);
		color: var(--aviso-info-texto);
		font-weight: 600;
	}

	.cargando,
	.vacio {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 0.5rem;
		padding: 2rem 0;
		color: var(--color-muted);
	}

	.radicado {
		font-family: ui-monospace, 'SFMono-Regular', monospace;
		font-size: 0.82rem;
		white-space: nowrap;
	}

	.tabla small {
		display: block;
		font-size: 0.75rem;
		color: var(--color-muted);
	}

	.fecha {
		white-space: nowrap;
		font-size: 0.8rem;
		color: var(--color-muted);
	}

	.marca {
		display: inline-block;
		padding: 0.1rem 0.45rem;
		border: 1px solid var(--color-border-strong);
		border-radius: 999px;
		font-size: 0.74rem;
		white-space: nowrap;
	}

	.paginacion {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 0.7rem;
		margin-top: 1rem;
		font-size: 0.84rem;
		color: var(--color-muted);
	}

	/* ── Lo que mandó el ciudadano ──────────────────────────────────────── */

	.senales {
		list-style: none;
		display: flex;
		flex-wrap: wrap;
		gap: 0.3rem;
		margin: 0;
		padding: 0;
		/* Ocho señales caben en dos filas de cuatro sin ensanchar la tabla más
		   allá de lo que ya se desplaza. */
		max-width: 9.5rem;
	}

	.senal {
		width: 2rem;
		padding: 0.15rem;
		border: 1px solid var(--color-border);
		border-radius: 6px;
		background: var(--color-surface-alt);
	}

	.adjuntos {
		list-style: none;
		display: flex;
		flex-wrap: wrap;
		gap: 0.3rem;
		margin: 0;
		padding: 0;
	}

	.adjuntos li {
		display: inline-flex;
		align-items: center;
		gap: 0.25rem;
		padding: 0.1rem 0.4rem;
		border: 1px solid var(--color-border-strong);
		border-radius: 999px;
		font-size: 0.74rem;
		white-space: nowrap;
	}

	.nada {
		font-size: 0.76rem;
		color: var(--color-muted);
	}

	.ubicada {
		display: inline-flex;
		align-items: center;
		gap: 0.15rem;
	}

	/*
		Visible para el lector de pantalla y no para el ojo. No se usa
		`display:none` ni `visibility:hidden`: las dos lo sacan también del árbol
		de accesibilidad, y entonces la columna de señales sería ocho dibujos sin
		una sola palabra que los nombre.
	*/
	.solo-lectores {
		position: absolute;
		width: 1px;
		height: 1px;
		padding: 0;
		margin: -1px;
		overflow: hidden;
		clip-path: inset(50%);
		white-space: nowrap;
		border: 0;
	}
</style>
