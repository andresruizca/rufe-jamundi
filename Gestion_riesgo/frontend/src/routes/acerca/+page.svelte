<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/state';
	import { replaceState } from '$app/navigation';
	import {
		LoaderCircle,
		RefreshCw,
		GitCommitHorizontal,
		ExternalLink,
		CircleCheck,
		CircleAlert,
		Database,
		Server
	} from '@lucide/svelte';
	import { acercaApi } from '$lib/api/servicios';
	import type { Actualizaciones, InfoSistema } from '$lib/api/tipos';
	import { fechaHora, haceCuanto } from '$lib/formato';

	type Pestana = 'sistema' | 'actualizaciones';

	// La pestaña se refleja en la URL para poder enlazar directo a las
	// actualizaciones (?tab=actualizaciones) y para que recargar no la pierda.
	let pestana = $state<Pestana>(
		page.url.searchParams.get('tab') === 'actualizaciones' ? 'actualizaciones' : 'sistema'
	);

	let info = $state<InfoSistema | null>(null);
	let cargandoInfo = $state(true);
	let errorInfo = $state('');

	let actualizaciones = $state<Actualizaciones | null>(null);
	let cargandoAct = $state(false);
	let refrescando = $state(false);
	let errorAct = $state('');
	let autorFiltro = $state<string>('todos');

	const commitsVisibles = $derived(
		autorFiltro === 'todos'
			? (actualizaciones?.commits ?? [])
			: (actualizaciones?.commits ?? []).filter((c) => c.equipo_clave === autorFiltro)
	);

	function cambiarPestana(nueva: Pestana) {
		pestana = nueva;
		const url = new URL(page.url);
		if (nueva === 'sistema') url.searchParams.delete('tab');
		else url.searchParams.set('tab', nueva);
		replaceState(url, page.state);

		if (nueva === 'actualizaciones' && actualizaciones === null) void cargarActualizaciones();
	}

	async function cargarInfo() {
		cargandoInfo = true;
		errorInfo = '';
		try {
			info = await acercaApi.sistema();
		} catch (e) {
			errorInfo = e instanceof Error ? e.message : 'No se pudo cargar la información.';
		} finally {
			cargandoInfo = false;
		}
	}

	async function cargarActualizaciones(refrescar = false) {
		if (refrescar) refrescando = true;
		else cargandoAct = true;
		errorAct = '';

		try {
			actualizaciones = await acercaApi.actualizaciones(refrescar);
		} catch (e) {
			errorAct = e instanceof Error ? e.message : 'No se pudo consultar el repositorio.';
		} finally {
			cargandoAct = false;
			refrescando = false;
		}
	}

	onMount(() => {
		void cargarInfo();
		if (pestana === 'actualizaciones') void cargarActualizaciones();
	});
</script>

<div class="pestanas" role="tablist" aria-label="Secciones de Acerca de">
	<button
		class="pestana"
		role="tab"
		type="button"
		aria-selected={pestana === 'sistema'}
		onclick={() => cambiarPestana('sistema')}
	>
		Sistema actual
	</button>
	<button
		class="pestana"
		role="tab"
		type="button"
		aria-selected={pestana === 'actualizaciones'}
		onclick={() => cambiarPestana('actualizaciones')}
	>
		Actualizaciones del sistema
	</button>
</div>

{#if pestana === 'sistema'}
	{#if cargandoInfo}
		<div class="cargando"><LoaderCircle size={20} class="girando" /> Cargando…</div>
	{:else if errorInfo}
		<p class="aviso aviso--error" role="alert">{errorInfo}</p>
	{:else if info}
		<div class="tarjeta encabezado">
			<h2 class="tarjeta__titulo">{info.aplicacion.nombre}</h2>
			<p class="tarjeta__nota">
				{info.aplicacion.entidad} · {info.aplicacion.dependencia}
			</p>
			<p class="descripcion">{info.aplicacion.descripcion}</p>
			<div class="chips">
				<span class="chip">Versión {info.aplicacion.version}</span>
				<span class="chip">Entorno: {info.aplicacion.entorno}</span>
				<a class="chip chip--enlace" href={info.repositorio.url} target="_blank" rel="noopener">
					{info.repositorio.owner}/{info.repositorio.repo}
					<ExternalLink size={12} aria-hidden="true" />
				</a>
			</div>
		</div>

		<div class="rejilla seccion">
			<div class="tarjeta">
				<h3 class="tarjeta__titulo">Estado del servicio</h3>
				<p class="tarjeta__nota">Comprobado al abrir esta página.</p>
				<ul class="lista-estado">
					<li>
						{#if info.estado.base_datos.conectada}
							<CircleCheck size={16} class="ok" aria-hidden="true" />
						{:else}
							<CircleAlert size={16} class="mal" aria-hidden="true" />
						{/if}
						<span>
							Base de datos
							<strong>{info.estado.base_datos.conectada ? 'conectada' : 'sin conexión'}</strong>
							<em>({info.estado.base_datos.nombre})</em>
						</span>
					</li>
					<li><Server size={16} aria-hidden="true" /><span>PHP <strong>{info.estado.php}</strong></span></li>
					<li>
						<Database size={16} aria-hidden="true" />
						<span>Usuarios activos: <strong>{info.estado.usuarios_activos}</strong></span>
					</li>
					<li>
						<Database size={16} aria-hidden="true" />
						<span>Sesiones abiertas: <strong>{info.estado.sesiones_activas}</strong></span>
					</li>
					<li>
						<Server size={16} aria-hidden="true" />
						<span>Hora del servidor: <strong>{fechaHora(info.estado.hora_servidor)}</strong></span>
					</li>
				</ul>
			</div>

			<div class="tarjeta">
				<h3 class="tarjeta__titulo">Tecnología</h3>
				<p class="tarjeta__nota">Con qué está construido cada componente.</p>
				<ul class="lista-tecnologia">
					{#each info.tecnologia as t (t.capa)}
						<li><span class="capa">{t.capa}</span><span>{t.detalle}</span></li>
					{/each}
				</ul>
			</div>
		</div>

		<div class="tarjeta seccion">
			<h3 class="tarjeta__titulo">Módulos del sistema</h3>
			<p class="tarjeta__nota">Qué hace cada sección y quién puede entrar.</p>
			<div class="rejilla">
				{#each info.modulos as m (m.nombre)}
					<div class="modulo">
						<h4 class="modulo__nombre">{m.nombre}</h4>
						<p class="modulo__desc">{m.descripcion}</p>
						<div class="chips">
							{#each m.roles as r (r)}<span class="chip chip--sm">{r}</span>{/each}
						</div>
					</div>
				{/each}
			</div>
		</div>

		<div class="tarjeta seccion">
			<h3 class="tarjeta__titulo">Roles y permisos</h3>
			<p class="tarjeta__nota">Los tres niveles de acceso definidos para el sistema.</p>
			<div class="rejilla">
				{#each info.roles as r (r.valor)}
					<div class="modulo">
						<h4 class="modulo__nombre">{r.etiqueta}</h4>
						<p class="modulo__desc">{r.descripcion}</p>
						<div class="chips">
							{#each r.capacidades as c (c)}<span class="chip chip--sm">{c}</span>{/each}
						</div>
					</div>
				{/each}
			</div>
		</div>
	{/if}
{:else}
	<!-- ── Pestaña: Actualizaciones del sistema ── -->
	<div class="barra-acciones">
		<p class="tarjeta__nota" style="margin:0">
			Historial de cambios publicados en el repositorio del sistema.
			{#if actualizaciones?.desde_cache}<em>(información en caché)</em>{/if}
		</p>
		<button
			class="boton boton--suave"
			type="button"
			onclick={() => cargarActualizaciones(true)}
			disabled={refrescando || cargandoAct}
		>
			<RefreshCw size={15} class={refrescando ? 'girando' : ''} aria-hidden="true" />
			{refrescando ? 'Consultando…' : 'Buscar actualizaciones'}
		</button>
	</div>

	{#if cargandoAct}
		<div class="cargando"><LoaderCircle size={20} class="girando" /> Consultando GitHub…</div>
	{:else if errorAct}
		<p class="aviso aviso--error" role="alert">{errorAct}</p>
	{:else if actualizaciones}
		{#if actualizaciones.error}
			<p class="aviso aviso--info" role="status">
				No se pudo consultar GitHub ({actualizaciones.error}). Se muestra la última información
				disponible.
			</p>
		{/if}

		<div class="rejilla seccion">
			{#each actualizaciones.autores as a (a.clave)}
				<button
					class="autor"
					class:autor--activo={autorFiltro === a.clave}
					type="button"
					onclick={() => (autorFiltro = autorFiltro === a.clave ? 'todos' : a.clave)}
				>
					{#if a.avatar}
						<img class="autor__avatar" src={a.avatar} alt="" aria-hidden="true" />
					{:else}
						<div class="autor__avatar autor__avatar--vacio" aria-hidden="true">
							{a.nombre.charAt(0)}
						</div>
					{/if}
					<div class="autor__datos">
						<div class="autor__nombre">{a.nombre}</div>
						<div class="autor__rol">{a.rol}</div>
						<div class="autor__meta">
							<strong>{a.total}</strong>
							{a.total === 1 ? 'actualización' : 'actualizaciones'}
							{#if a.ultima_fecha}· última {haceCuanto(a.ultima_fecha)}{/if}
						</div>
					</div>
				</button>
			{/each}
		</div>

		{#if autorFiltro !== 'todos'}
			<p class="filtro-aviso">
				Mostrando solo las actualizaciones de
				<strong>{actualizaciones.autores.find((a) => a.clave === autorFiltro)?.nombre}</strong>.
				<button class="enlace" type="button" onclick={() => (autorFiltro = 'todos')}>
					Ver todas
				</button>
			</p>
		{/if}

		{#if commitsVisibles.length === 0}
			<p class="vacio">No hay actualizaciones registradas para este filtro.</p>
		{:else}
			<ol class="linea-tiempo">
				{#each commitsVisibles as c (c.sha)}
					<li class="hito">
						<div class="hito__icono" aria-hidden="true"><GitCommitHorizontal size={15} /></div>
						<div class="hito__cuerpo">
							<div class="hito__cabecera">
								<span class="hito__autor">{c.equipo_nombre}</span>
								<span class="hito__fecha" title={fechaHora(c.fecha)}>{haceCuanto(c.fecha)}</span>
							</div>
							<p class="hito__titulo">{c.titulo}</p>
							{#if c.descripcion}<p class="hito__desc">{c.descripcion}</p>{/if}
							<a class="hito__sha" href={c.url} target="_blank" rel="noopener">
								{c.sha_corto}
								<ExternalLink size={11} aria-hidden="true" />
							</a>
						</div>
					</li>
				{/each}
			</ol>
		{/if}
	{/if}
{/if}

<style>
	.seccion {
		margin-top: 1rem;
	}

	.descripcion {
		margin: 0 0 0.9rem;
		font-size: 0.9rem;
		line-height: 1.55;
		color: var(--color-text);
		max-width: 70ch;
	}

	.chips {
		display: flex;
		flex-wrap: wrap;
		gap: 0.4rem;
	}

	.chip {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		background: var(--color-surface-alt);
		border: 1px solid var(--color-border);
		border-radius: 999px;
		padding: 0.22rem 0.6rem;
		font-size: 0.75rem;
		font-weight: 600;
		color: var(--color-muted);
	}

	.chip--sm {
		font-size: 0.7rem;
		font-weight: 500;
	}

	.chip--enlace {
		color: var(--color-primary-dark);
		text-decoration: none;
	}

	.lista-estado,
	.lista-tecnologia {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 0.55rem;
		font-size: 0.85rem;
	}

	.lista-estado li {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		color: var(--color-text);
	}

	.lista-estado em {
		color: var(--color-muted);
		font-style: normal;
		font-size: 0.8rem;
	}

	.lista-tecnologia li {
		display: flex;
		flex-direction: column;
		gap: 0.1rem;
	}

	.capa {
		font-size: 0.72rem;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		font-weight: 700;
		color: var(--color-muted);
	}

	.modulo {
		border: 1px solid var(--color-border);
		border-radius: 10px;
		padding: 0.85rem;
		background: var(--color-surface-alt);
	}

	.modulo__nombre {
		margin: 0 0 0.3rem;
		font-size: 0.92rem;
		font-weight: 700;
		color: var(--color-text);
	}

	.modulo__desc {
		margin: 0 0 0.6rem;
		font-size: 0.82rem;
		line-height: 1.5;
		color: var(--color-muted);
	}

	.barra-acciones {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.75rem;
		flex-wrap: wrap;
		margin-bottom: 1rem;
	}

	.autor {
		display: flex;
		align-items: center;
		gap: 0.75rem;
		text-align: left;
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		border-radius: 12px;
		padding: 0.9rem;
		cursor: pointer;
		font: inherit;
	}

	.autor:hover {
		border-color: var(--color-border-strong);
	}

	.autor--activo {
		border-color: var(--color-primary);
		box-shadow: inset 0 0 0 1px var(--color-primary);
	}

	.autor__avatar {
		width: 46px;
		height: 46px;
		border-radius: 50%;
		flex: 0 0 auto;
		object-fit: cover;
	}

	.autor__avatar--vacio {
		display: grid;
		place-items: center;
		background: var(--color-primary);
		color: #fff;
		font-weight: 700;
	}

	.autor__datos {
		min-width: 0;
	}

	.autor__nombre {
		font-weight: 700;
		font-size: 0.92rem;
		color: var(--color-text);
	}

	.autor__rol {
		font-size: 0.76rem;
		color: var(--color-muted);
	}

	.autor__meta {
		font-size: 0.78rem;
		color: var(--color-muted);
		margin-top: 0.2rem;
	}

	.filtro-aviso {
		font-size: 0.82rem;
		color: var(--color-muted);
		margin: 0 0 0.85rem;
	}

	.enlace {
		background: none;
		border: 0;
		padding: 0;
		font: inherit;
		color: var(--color-primary);
		text-decoration: underline;
		cursor: pointer;
	}

	.linea-tiempo {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
	}

	.hito {
		display: flex;
		gap: 0.75rem;
		position: relative;
		padding-bottom: 0.9rem;
	}

	/* Línea vertical que une los hitos, salvo bajo el último. */
	.hito:not(:last-child)::before {
		content: '';
		position: absolute;
		left: 13px;
		top: 30px;
		bottom: 0;
		width: 2px;
		background: var(--color-border);
	}

	.hito__icono {
		width: 28px;
		height: 28px;
		flex: 0 0 auto;
		border-radius: 50%;
		display: grid;
		place-items: center;
		background: var(--color-info-bg);
		color: var(--color-primary-dark);
		border: 1px solid var(--color-border);
		z-index: 1;
	}

	.hito__cuerpo {
		flex: 1;
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		border-radius: 10px;
		padding: 0.65rem 0.8rem;
		min-width: 0;
	}

	.hito__cabecera {
		display: flex;
		justify-content: space-between;
		gap: 0.5rem;
		flex-wrap: wrap;
		margin-bottom: 0.2rem;
	}

	.hito__autor {
		font-size: 0.8rem;
		font-weight: 700;
		color: var(--color-primary-dark);
	}

	.hito__fecha {
		font-size: 0.75rem;
		color: var(--color-muted);
	}

	.hito__titulo {
		margin: 0;
		font-size: 0.88rem;
		font-weight: 600;
		color: var(--color-text);
		overflow-wrap: anywhere;
	}

	.hito__desc {
		margin: 0.3rem 0 0;
		font-size: 0.8rem;
		color: var(--color-muted);
		white-space: pre-line;
		overflow-wrap: anywhere;
	}

	.hito__sha {
		display: inline-flex;
		align-items: center;
		gap: 0.25rem;
		margin-top: 0.45rem;
		font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
		font-size: 0.74rem;
		color: var(--color-muted);
		text-decoration: none;
	}

	.hito__sha:hover {
		color: var(--color-primary);
	}
</style>
