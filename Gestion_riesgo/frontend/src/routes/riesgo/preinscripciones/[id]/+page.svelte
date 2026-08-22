<script lang="ts">
	// Una solicitud ciudadana: qué mandó, y qué se hace con ella.
	//
	// Lo que importa de esta pantalla es el botón de convertir. Todo lo demás
	// —los datos, las fotos, el punto GPS— está aquí para que quien decide pueda
	// hacerlo con criterio y sin llamar por teléfono primero.
	//
	// «Convertida» no se marca a mano: la pone el sistema cuando de verdad nace
	// la inspección. Marcarla desde aquí permitiría cerrar una solicitud diciendo
	// que se atendió sin que exista la ficha.

	import { onMount } from 'svelte';
	import { page } from '$app/state';
	import {
		ArrowLeft, ArrowRight, Check, LoaderCircle, MapPin, TriangleAlert, Video
	} from '@lucide/svelte';
	import { ApiError } from '$lib/api/client';
	import { preinscripcionApi, type PreinscripcionDetalle } from '$lib/api/servicios';
	import { sesion } from '$lib/stores/sesion.svelte';
	import { ESCRITURA } from '$lib/navigation';
	import VisorEvidencias from '$lib/components/VisorEvidencias.svelte';
	import { fechaHora } from '$lib/formato';

	let detalle = $state<PreinscripcionDetalle | null>(null);
	let cargando = $state(true);
	let error = $state<string | null>(null);
	let exito = $state<string | null>(null);

	let nuevoEstado = $state('');
	let nota = $state('');
	let guardando = $state(false);
	let erroresCampo = $state<Record<string, string>>({});

	const id = $derived(Number(page.params.id));
	const puedeDecidir = $derived(!!sesion.rol && ESCRITURA.includes(sesion.rol));

	const p = $derived(detalle?.preinscripcion ?? null);

	const ESTADOS = [
		{ codigo: 'EN_REVISION', etiqueta: 'En revisión', nota: 'Alguien la está estudiando.' },
		{ codigo: 'RECIBIDA', etiqueta: 'Sin atender', nota: 'Vuelve a la cola de pendientes.' },
		{ codigo: 'DESCARTADA', etiqueta: 'Descartada', nota: 'Requiere explicar el motivo.' }
	];

	const ETIQUETA_ESTADO: Record<string, string> = {
		RECIBIDA: 'Sin atender',
		EN_REVISION: 'En revisión',
		CONVERTIDA: 'Convertida en inspección',
		DESCARTADA: 'Descartada'
	};

	const faltaMotivo = $derived(nuevoEstado === 'DESCARTADA' && nota.trim() === '');
	const yaConvertida = $derived(p?.estado === 'CONVERTIDA');

	/**
	 * Los videos se traen de uno en uno, y solo cuando alguien los pide.
	 *
	 * Pesan megabytes: descargarlos todos al abrir la ficha gastaría la conexión
	 * de la oficina en videos que quizá nadie va a mirar.
	 */
	let urlsVideo = $state<Record<number, string>>({});
	let cargandoVideo = $state<Record<number, boolean>>({});

	async function verVideo(idVideo: number) {
		if (urlsVideo[idVideo] || cargandoVideo[idVideo]) return;

		cargandoVideo = { ...cargandoVideo, [idVideo]: true };

		try {
			urlsVideo = { ...urlsVideo, [idVideo]: await preinscripcionApi.verVideo(id, idVideo) };
		} catch {
			error = 'No se pudo abrir el video.';
		} finally {
			cargandoVideo = { ...cargandoVideo, [idVideo]: false };
		}
	}

	function pesoLegible(bytes: number): string {
		return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`;
	}

	const lugar = $derived(
		p
			? [
					p.direccion,
					p.vereda,
					p.corregimiento,
					p.zona === 'RURAL' ? 'zona rural' : p.zona === 'URBANA' ? 'zona urbana' : null
				]
					.filter(Boolean)
					.join(' · ')
			: '—'
	);

	onMount(cargar);

	async function cargar() {
		cargando = true;
		error = null;

		try {
			detalle = await preinscripcionApi.ver(id);
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudo cargar la solicitud.';
		} finally {
			cargando = false;
		}
	}

	async function decidir() {
		if (!nuevoEstado || guardando || faltaMotivo) return;

		guardando = true;
		error = null;
		exito = null;
		erroresCampo = {};

		try {
			await preinscripcionApi.cambiarEstado(id, nuevoEstado, nota);
			exito = 'El estado de la solicitud se actualizó.';
			nuevoEstado = '';
			nota = '';
			await cargar();
		} catch (e) {
			if (e instanceof ApiError) {
				error = e.message;
				erroresCampo = e.errors;
			} else {
				error = 'No se pudo aplicar la decisión.';
			}
		} finally {
			guardando = false;
		}
	}
</script>

<svelte:head><title>Solicitud ciudadana · SGR Jamundí</title></svelte:head>

<a class="volver" href="/riesgo/preinscripciones">
	<ArrowLeft size={15} aria-hidden="true" />
	Volver a las solicitudes
</a>

{#if cargando}
	<p class="cargando"><LoaderCircle size={18} class="girando" aria-hidden="true" /> Cargando…</p>
{:else if error && !detalle}
	<p class="aviso aviso--error" role="alert">{error}</p>
{:else if detalle && p}
	{#if error}<p class="aviso aviso--error" role="alert">{error}</p>{/if}
	{#if exito}<p class="aviso aviso--exito" role="status">{exito}</p>{/if}

	<div class="tarjeta">
		<header class="encabezado">
			<div>
				<p class="radicado">{p.radicado}</p>
				<h1 class="tarjeta__titulo">{p.nombre_completo}</h1>
				<p class="fecha">Recibida el {fechaHora(String(p.creado_en))}</p>
			</div>
			<span class="pastilla">{ETIQUETA_ESTADO[String(p.estado)] ?? p.estado}</span>
		</header>

		<dl class="datos">
			<div><dt>Cédula</dt><dd>{p.documento}</dd></div>
			<div><dt>Teléfono</dt><dd>{p.telefono}</dd></div>
			{#if p.correo}<div><dt>Correo</dt><dd>{p.correo}</dd></div>{/if}
			<div><dt>Vivienda</dt><dd>{lugar}</dd></div>

			{#if p.latitud !== null && p.longitud !== null}
				<div>
					<dt>Coordenadas</dt>
					<dd>
						<a
							href="https://www.openstreetmap.org/?mlat={p.latitud}&mlon={p.longitud}#map=18/{p.latitud}/{p.longitud}"
							target="_blank"
							rel="noopener noreferrer"
						>
							<MapPin size={13} aria-hidden="true" />
							{p.latitud}, {p.longitud}
							{#if p.precision_m}(±{p.precision_m} m){/if}
						</a>
					</dd>
				</div>
			{/if}

			<!--
				Lo que el ciudadano marcó NO es una clasificación de daño: es lo que
				alcanzó a reconocer mirando su casa. Se muestra con la etiqueta que
				vio en su momento, no con la que hoy diría el catálogo, y se dice
				expresamente para qué sirve — para saber qué mirar primero en la
				visita, no para dar por evaluado nada.
			-->
			<div>
				<dt>Daños que reconoció</dt>
				<dd>
					{#if detalle.senales.length === 0}
						<span class="vacio">No marcó ninguno</span>
					{:else}
						<ul class="senales">
							{#each detalle.senales as s (s.codigo)}
								<li>{s.etiqueta}</li>
							{/each}
						</ul>
					{/if}
				</dd>
			</div>

			{#if p.descripcion_dano}
				<div><dt>Lo que reportó</dt><dd class="relato">{p.descripcion_dano}</dd></div>
			{/if}

			<div>
				<dt>Autorización de datos</dt>
				<dd>
					{p.autoriza_datos ? 'Otorgada' : 'No otorgada'}
					{#if p.autorizacion_en}· {fechaHora(String(p.autorizacion_en))}{/if}
					{#if p.aviso_version}· aviso {p.aviso_version}{/if}
				</dd>
			</div>
		</dl>
	</div>

	{#if detalle.fotos.length > 0}
		<div class="tarjeta">
			<h2 class="tarjeta__titulo">Fotos que envió</h2>
			<VisorEvidencias reporteId={Number(p.id)} evidencias={detalle.fotos} origen="preinscripcion" />
		</div>
	{/if}

	{#if detalle.videos.length > 0}
		<div class="tarjeta">
			<h2 class="tarjeta__titulo">Videos que grabó</h2>

			<ul class="videos">
				{#each detalle.videos as v (v.id)}
					<li class="video">
						<p class="video__nombre">
							<Video size={15} aria-hidden="true" />
							{v.categoria_nombre}
							<span class="video__meta">
								{#if v.segundos}{v.segundos}s · {/if}{pesoLegible(v.tamano_bytes)}
							</span>
						</p>

						{#if !v.disponible}
							<!-- El archivo se borra al decidir la solicitud; la fila queda
							     como constancia de que el video existió. -->
							<p class="video__ido">
								El archivo se eliminó al cerrarse la solicitud. Queda la constancia de que se
								grabó.
							</p>
						{:else if urlsVideo[v.id]}
							<!-- svelte-ignore a11y_media_has_caption -->
							<video class="video__reproductor" src={urlsVideo[v.id]} controls playsinline></video>
						{:else}
							<button
								type="button"
								class="boton boton--suave"
								onclick={() => verVideo(v.id)}
								disabled={cargandoVideo[v.id]}
							>
								{#if cargandoVideo[v.id]}
									<LoaderCircle size={15} class="girando" aria-hidden="true" />
									Cargando…
								{:else}
									Ver el video
								{/if}
							</button>
						{/if}
					</li>
				{/each}
			</ul>
		</div>
	{/if}

	{#if puedeDecidir}
		<div class="tarjeta">
			<h2 class="tarjeta__titulo">Qué se hace con esta solicitud</h2>

			{#if yaConvertida}
				<p class="aviso aviso--exito" role="status">
					<Check size={15} aria-hidden="true" />
					Ya se levantó la inspección
					{#if p.inspeccion_id}
						<a href="/riesgo/inspecciones/{p.inspeccion_id}">ver la ficha</a>
					{/if}
				</p>
			{:else}
				<!--
					El camino principal, y por eso va primero y con el botón lleno:
					el resto de esta pantalla existe para poder pulsarlo con criterio.
				-->
				<p class="tarjeta__nota">
					Al convertirla, el formato de inspección se abre con el propietario, la dirección y las
					coordenadas ya cargados. La solicitud queda marcada como atendida cuando la ficha se
					guarde, no antes.
				</p>

				<a class="boton boton--grande" href="/riesgo/inspeccionar?preinscripcion={p.id}">
					Convertir en inspección
					<ArrowRight size={16} aria-hidden="true" />
				</a>

				<hr class="separador" />

				<fieldset class="campo decision">
					<legend class="campo__etiqueta">O cambiar su estado</legend>
					<div class="opciones">
						{#each ESTADOS.filter((e) => e.codigo !== p.estado) as e (e.codigo)}
							<label class="opcion" class:opcion--activa={nuevoEstado === e.codigo}>
								<input type="radio" name="nuevo-estado" value={e.codigo} bind:group={nuevoEstado} />
								<span class="opcion__texto">
									{e.etiqueta}
									<span class="opcion__nota">{e.nota}</span>
								</span>
							</label>
						{/each}
					</div>
				</fieldset>

				<div class="campo" class:campo--invalido={!!erroresCampo.nota}>
					<label class="campo__etiqueta" for="nota">
						Nota {nuevoEstado === 'DESCARTADA' ? '(obligatoria)' : '(opcional)'}
					</label>
					<textarea id="nota" class="campo__control" rows="3" maxlength="500" bind:value={nota}
					></textarea>
					{#if erroresCampo.nota}
						<span class="campo__error">{erroresCampo.nota}</span>
					{:else if faltaMotivo}
						<span class="campo__ayuda">
							Explique por qué se descarta: es lo que se le responderá a la familia si llama a
							preguntar.
						</span>
					{/if}
				</div>

				<button
					type="button"
					class="boton"
					onclick={decidir}
					disabled={!nuevoEstado || guardando || faltaMotivo}
				>
					{#if guardando}
						<LoaderCircle size={15} class="girando" aria-hidden="true" />
						Guardando…
					{:else}
						<Check size={15} aria-hidden="true" />
						Aplicar
					{/if}
				</button>
			{/if}
		</div>
	{/if}

	<div class="tarjeta">
		<h2 class="tarjeta__titulo">Historial</h2>
		{#if detalle.historial.length > 0}
			<ol class="historial">
				{#each detalle.historial as h, n (n)}
					<li>
						<span class="historial__estado">{ETIQUETA_ESTADO[h.estado] ?? h.estado}</span>
						<span class="historial__meta">
							{fechaHora(h.creado_en)}{h.usuario_email ? ` · ${h.usuario_email}` : ''}
						</span>
						{#if h.nota}<span class="historial__nota">{h.nota}</span>{/if}
					</li>
				{/each}
			</ol>
		{:else}
			<p class="tarjeta__nota">Todavía nadie ha tocado esta solicitud.</p>
		{/if}
	</div>
{/if}

<style>
	.volver {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		margin-bottom: 0.9rem;
		font-size: 0.84rem;
		color: var(--color-primary-dark);
	}

	.cargando {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		color: var(--color-muted);
	}

	.encabezado {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 0.75rem;
		flex-wrap: wrap;
		margin-bottom: 1rem;
	}

	.radicado {
		margin: 0 0 0.2rem;
		font-family: ui-monospace, 'SFMono-Regular', monospace;
		font-size: 0.8rem;
		color: var(--color-muted);
	}

	.fecha {
		margin: 0.2rem 0 0;
		font-size: 0.8rem;
		color: var(--color-muted);
	}

	.pastilla {
		padding: 0.15rem 0.55rem;
		border: 1px solid var(--color-border-strong);
		border-radius: 999px;
		font-size: 0.76rem;
		font-weight: 600;
		white-space: nowrap;
	}

	.datos {
		margin: 0;
		display: grid;
		gap: 0.45rem;
	}

	.datos > div {
		display: grid;
		grid-template-columns: minmax(11rem, 30%) 1fr;
		gap: 0.6rem;
		font-size: 0.85rem;
	}

	.datos dt {
		color: var(--color-muted);
	}

	.datos dd {
		margin: 0;
		word-break: break-word;
	}

	.relato {
		white-space: pre-wrap;
	}

	.senales {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-wrap: wrap;
		gap: 0.35rem;
	}

	.senales li {
		padding: 0.15rem 0.55rem;
		border: 1px solid var(--color-border-strong);
		border-radius: 999px;
		font-size: 0.8rem;
		background: var(--color-surface-alt);
	}

	.vacio {
		color: var(--color-muted);
	}

	.videos {
		list-style: none;
		margin: 0;
		padding: 0;
		display: grid;
		gap: 0.8rem;
	}

	.video__nombre {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		flex-wrap: wrap;
		margin: 0 0 0.4rem;
		font-size: 0.9rem;
		font-weight: 600;
	}

	.video__meta {
		font-size: 0.78rem;
		font-weight: 400;
		color: var(--color-muted);
	}

	.video__ido {
		margin: 0;
		font-size: 0.8rem;
		color: var(--color-muted);
	}

	.video__reproductor {
		width: 100%;
		max-height: 60vh;
		border-radius: 0.5rem;
		background: #000;
	}

	.boton--grande {
		width: 100%;
		justify-content: center;
		min-height: 3rem;
		font-size: 0.95rem;
	}

	.separador {
		margin: 1.3rem 0 1rem;
		border: 0;
		border-top: 1px solid var(--color-border);
	}

	.decision {
		border: 0;
		padding: 0;
		min-width: 0;
	}

	.historial {
		list-style: none;
		margin: 0;
		padding: 0;
		display: grid;
		gap: 0.6rem;
	}

	.historial li {
		padding-left: 0.8rem;
		border-left: 3px solid var(--color-border-strong);
	}

	.historial__estado {
		display: block;
		font-size: 0.85rem;
		font-weight: 600;
	}

	.historial__meta {
		display: block;
		font-size: 0.75rem;
		color: var(--color-muted);
	}

	.historial__nota {
		display: block;
		margin-top: 0.2rem;
		font-size: 0.82rem;
	}
</style>
