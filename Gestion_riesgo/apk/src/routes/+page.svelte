<script lang="ts">
	// El formulario ciudadano, en el teléfono y sin conexión.
	//
	// Es el mismo de la web en pasos, textos y validación —las piezas están
	// copiadas en `src/formulario/`— con una diferencia de fondo: al terminar no
	// se envía nada. Se guarda en SQLite y `SyncWorker.kt` lo manda cuando haya
	// señal, con la aplicación cerrada si hace falta.
	//
	// Eso cambia lo que se le promete a la persona. En la web, «Enviar» envía.
	// Aquí el botón dice «Guardar» y la pantalla final no da un radicado —todavía
	// no existe— sino la explicación de qué va a pasar. Prometer un envío que aún
	// no ocurrió es exactamente cómo alguien desinstala creyendo que ya mandó su
	// solicitud.

	import { onMount } from 'svelte';
	import {
		ArrowLeft, ArrowRight, Camera, Check, Image, LoaderCircle, MapPin, Save, Trash2,
		TriangleAlert
	} from '@lucide/svelte';

	import {
		bloqueoDeAvance, datosVacios, pasosVigentes, validarPaso, type DatosPre
	} from '$formulario/pasos';
	import SelectorSenales from '$formulario/SelectorSenales.svelte';
	import AutorizacionDatos from '$formulario/AutorizacionDatos.svelte';
	import GrabadorVideo from '$formulario/GrabadorVideo.svelte';

	import { catalogoVigente, refrescarCatalogo, type Catalogo } from '$local/catalogo';
	import { abrir, leerAjuste } from '$local/base';
	import { empezar, guardar } from '$local/registros';
	import { tomarFoto, quitarAdjunto } from '../captura/foto';

	let catalogo = $state<Catalogo | null>(null);
	let registroId = $state('');
	let indice = $state(0);
	let datos = $state<DatosPre>(datosVacios());
	let errores = $state<Record<string, string>>({});
	let aviso = $state('');
	let guardando = $state(false);
	let listo = $state(false);

	/** Lo adjuntado hasta ahora, para poder enseñarlo y quitarlo. */
	let adjuntos = $state<{ id: string; tipo: string; bytes: number }[]>([]);
	let capturando = $state(false);
	let videosSubiendo = $state<number[]>([]);
	let ubicando = $state(false);

	const hayVideos = $derived((catalogo?.categorias_video ?? []).length > 0);
	const pasos = $derived(pasosVigentes(hayVideos));
	const paso = $derived(pasos[Math.min(indice, pasos.length - 1)]);
	const esUltimo = $derived(indice === pasos.length - 1);

	const cedulas = $derived(adjuntos.filter((a) => a.tipo === 'PRE_CEDULA'));
	const fotosDano = $derived(adjuntos.filter((a) => a.tipo === 'PRE_DANO'));

	onMount(() => {
		void (async () => {
			// El registro se crea ANTES de tomar fotos: los adjuntos necesitan a
			// qué registro pertenecer.
			registroId = await empezar();
			catalogo = await catalogoVigente();

			// Al fondo y sin avisar. Si no hay señal —lo normal— el catálogo
			// embebido sirve igual.
			const base = await leerAjuste('api_base');
			if (base) void refrescarCatalogo(base);
		})();
	});

	// ── Adjuntos ────────────────────────────────────────────────────────────

	async function capturar(tipo: 'PRE_CEDULA' | 'PRE_DANO', fuente: 'camara' | 'galeria') {
		if (capturando) return;

		capturando = true;
		aviso = '';

		try {
			const r = await tomarFoto(registroId, tipo, fuente);

			if (!r.ok) {
				// Cancelar la cámara devuelve motivo vacío: no es un error y no
				// se le enseña nada a nadie.
				if (r.motivo) aviso = r.motivo;

				return;
			}

			adjuntos = [...adjuntos, { id: r.adjunto.id, tipo, bytes: r.adjunto.bytes }];
		} finally {
			capturando = false;
		}
	}

	async function quitar(id: string) {
		await quitarAdjunto(id);
		adjuntos = adjuntos.filter((a) => a.id !== id);
	}

	function peso(bytes: number): string {
		return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`;
	}

	// ── Ubicación ───────────────────────────────────────────────────────────

	function tomarUbicacion() {
		if (!navigator.geolocation) {
			aviso = 'Este teléfono no permite compartir la ubicación.';

			return;
		}

		ubicando = true;

		navigator.geolocation.getCurrentPosition(
			(p) => {
				datos.latitud = Number(p.coords.latitude.toFixed(7));
				datos.longitud = Number(p.coords.longitude.toFixed(7));
				datos.precision_m = Math.round(p.coords.accuracy);
				ubicando = false;
			},
			() => {
				ubicando = false;
				aviso = 'No se pudo tomar la ubicación. Puede continuar sin ella.';
			},
			{ enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
		);
	}

	// ── Navegación ──────────────────────────────────────────────────────────

	function siguiente() {
		const fallos = validarPaso(paso.id, datos);
		errores = fallos;
		if (Object.keys(fallos).length > 0) return;

		const bloqueo = bloqueoDeAvance({ optimizandoFotos: capturando, videosSubiendo: videosSubiendo.length });
		if (bloqueo) {
			aviso = bloqueo;

			return;
		}

		aviso = '';
		indice = Math.min(indice + 1, pasos.length - 1);
		window.scrollTo({ top: 0 });
	}

	function anterior() {
		errores = {};
		aviso = '';
		indice = Math.max(indice - 1, 0);
		window.scrollTo({ top: 0 });
	}

	// ── Guardar ─────────────────────────────────────────────────────────────

	/**
	 * Guarda en el teléfono. NO envía.
	 *
	 * Aquí no hay red de por medio, así que no hay reintentos ni errores de
	 * servidor: o se escribe en SQLite o no. Lo que puede fallar es el disco
	 * lleno, y eso hay que decirlo tal cual.
	 */
	async function guardarTodo() {
		const fallos = validarPaso('envio', datos);
		errores = fallos;
		if (Object.keys(fallos).length > 0) return;

		const bloqueo = bloqueoDeAvance({ optimizandoFotos: capturando, videosSubiendo: videosSubiendo.length });
		if (bloqueo) {
			aviso = bloqueo;

			return;
		}

		// La zona se valida en el paso 1, pero TypeScript no lo sabe y —más
		// importante— si alguna vez se pudiera llegar aquí sin ella, guardar una
		// solicitud con zona vacía sería guardar algo que el servidor va a
		// rechazar de todas formas. Se devuelve al paso donde está el campo.
		if (datos.zona !== 'URBANA' && datos.zona !== 'RURAL') {
			indice = 0;
			errores = { zona: 'Indique si la vivienda está en zona urbana o rural.' };
			window.scrollTo({ top: 0 });

			return;
		}

		guardando = true;
		aviso = '';

		try {
			await guardar(registroId, {
				...datos,
				zona: datos.zona,
				aviso_version: catalogo!.aviso_version
			});
			listo = true;
			window.scrollTo({ top: 0 });
		} catch (e) {
			aviso =
				'No se pudo guardar en este teléfono. Puede que no quede espacio. ' +
				'Libere algo e intente de nuevo: no ha perdido lo que escribió.';
		} finally {
			guardando = false;
		}
	}

	async function otraVivienda() {
		registroId = await empezar();
		datos = datosVacios();
		adjuntos = [];
		videosSubiendo = [];
		errores = {};
		indice = 0;
		listo = false;
	}
</script>

<svelte:head><title>Inspección de Vivienda · Jamundí</title></svelte:head>

<main>
	{#if !catalogo}
		<p class="tenue"><LoaderCircle size={16} class="girando" aria-hidden="true" /> Abriendo…</p>
	{:else if listo}
		<!--
			No hay radicado que dar: todavía no se ha enviado nada. Inventar un
			número aquí sería darle a alguien una constancia que no existe.
		-->
		<div class="fin">
			<Check size={40} aria-hidden="true" />
			<h1>Registro guardado</h1>
			<p>
				Se enviará <strong>solo</strong>, en cuanto este teléfono tenga internet. No hace falta
				que abra la aplicación ni que haga nada más.
			</p>
			<p class="fin__nota">
				Cuando salga, en <strong>Mis registros</strong> aparecerá su número de radicado. Ese es el
				que debe dar si llama a preguntar.
			</p>
			<p class="fin__aviso">
				<TriangleAlert size={15} aria-hidden="true" />
				No desinstale la aplicación hasta que aparezca como enviado.
			</p>

			<a class="boton boton--lleno" href="/mis-registros">Ver mis registros</a>
			<button type="button" class="boton" onclick={otraVivienda}>Registrar otra vivienda</button>
		</div>
	{:else}
		<header>
			<p class="entidad">Alcaldía de Jamundí</p>
			<h1>{paso.titulo}</h1>
			<p class="pasos">Paso {indice + 1} de {pasos.length}</p>
			<div class="barra"><div style="width: {((indice + 1) / pasos.length) * 100}%"></div></div>
			<p class="ayuda">{paso.ayuda}</p>
		</header>

		{#if aviso}
			<p class="alerta" role="alert"><TriangleAlert size={15} aria-hidden="true" /> {aviso}</p>
		{/if}

		<!-- ── 1 · Sus datos ─────────────────────────────────────────────── -->
		{#if paso.id === 'datos'}
			<label class="campo">
				<span>Nombre y apellidos *</span>
				<input bind:value={datos.nombre_completo} autocomplete="name" />
				{#if errores.nombre_completo}<em>{errores.nombre_completo}</em>{/if}
			</label>

			<label class="campo">
				<span>Cédula *</span>
				<input bind:value={datos.documento} inputmode="numeric" placeholder="Sin puntos" />
				{#if errores.documento}<em>{errores.documento}</em>{/if}
			</label>

			<label class="campo">
				<span>Teléfono *</span>
				<input bind:value={datos.telefono} type="tel" inputmode="tel" />
				<small>A este número lo llamaremos para coordinar la visita.</small>
				{#if errores.telefono}<em>{errores.telefono}</em>{/if}
			</label>

			<label class="campo">
				<span>Correo electrónico</span>
				<input bind:value={datos.correo} type="email" />
				<small>Opcional. Déjelo en blanco si no tiene.</small>
				{#if errores.correo}<em>{errores.correo}</em>{/if}
			</label>

			<fieldset class="campo">
				<legend>¿La vivienda está en zona urbana o rural? *</legend>
				<div class="opciones">
					{#each [['URBANA', 'Urbana', 'En la cabecera'], ['RURAL', 'Rural', 'Corregimiento o vereda']] as [valor, titulo, nota] (valor)}
						<label class="opcion" class:opcion--si={datos.zona === valor}>
							<input
								type="radio"
								name="zona"
								checked={datos.zona === valor}
								onchange={() => (datos.zona = valor as 'URBANA' | 'RURAL')}
							/>
							<span>{titulo}<small>{nota}</small></span>
						</label>
					{/each}
				</div>
				{#if errores.zona}<em>{errores.zona}</em>{/if}
			</fieldset>

			<label class="campo">
				<span>Dirección o cómo llegar *</span>
				<textarea rows="2" maxlength="200" bind:value={datos.direccion}
					placeholder="La casa azul pasando el puente, al lado de la tienda"></textarea>
				<small>Si no tiene nomenclatura, sirven las referencias.</small>
				{#if errores.direccion}<em>{errores.direccion}</em>{/if}
			</label>

			{#if datos.zona === 'RURAL'}
				<label class="campo">
					<span>Corregimiento</span>
					<select bind:value={datos.corregimiento}>
						<option value="">No lo sé</option>
						{#each catalogo.corregimientos as c (c)}<option value={c}>{c}</option>{/each}
					</select>
				</label>
			{/if}

			<label class="campo">
				<span>{datos.zona === 'RURAL' ? 'Vereda' : 'Barrio'}</span>
				<input bind:value={datos.vereda} />
			</label>

			<div class="campo">
				<span class="etiqueta">Ubicación (opcional)</span>
				{#if datos.latitud !== null}
					<p class="ok"><MapPin size={14} aria-hidden="true" /> Ubicación tomada
						{#if datos.precision_m}(±{datos.precision_m} m){/if}</p>
				{:else}
					<button type="button" class="boton" onclick={tomarUbicacion} disabled={ubicando}>
						<MapPin size={15} aria-hidden="true" />
						{ubicando ? 'Obteniendo…' : 'Tomar la ubicación aquí'}
					</button>
				{/if}
			</div>

			<div class="campo">
				<span class="etiqueta">Foto de su cédula</span>
				<small>Del lado de los datos, sin reflejos. Se reduce en el teléfono.</small>
				{#each cedulas as a (a.id)}
					<p class="adjunto">Cédula · {peso(a.bytes)}
						<button type="button" onclick={() => quitar(a.id)}><Trash2 size={13} /> Quitar</button>
					</p>
				{/each}
				{#if cedulas.length === 0}
					<div class="botones">
						<button type="button" class="boton" onclick={() => capturar('PRE_CEDULA', 'camara')} disabled={capturando}>
							<Camera size={15} aria-hidden="true" /> Tomar foto
						</button>
						<button type="button" class="boton" onclick={() => capturar('PRE_CEDULA', 'galeria')} disabled={capturando}>
							<Image size={15} aria-hidden="true" /> Desde la galería
						</button>
					</div>
				{/if}
			</div>

		<!-- ── 2 · Cómo quedó ────────────────────────────────────────────── -->
		{:else if paso.id === 'vivienda'}
			<SelectorSenales senales={catalogo.senales} bind:marcadas={datos.senales} />

			<label class="campo">
				<span>¿Quiere contarnos algo más?</span>
				<textarea rows="4" maxlength="1000" bind:value={datos.descripcion_dano}
					placeholder="Se agrietaron los muros de la sala y se cayó parte del techo."></textarea>
				<small>Opcional. No hace falta que sea técnico.</small>
			</label>

			<div class="campo">
				<span class="etiqueta">Fotos del daño</span>
				<small>Hasta 4. Ayudan a priorizar la visita.</small>
				{#each fotosDano as a (a.id)}
					<p class="adjunto">Foto · {peso(a.bytes)}
						<button type="button" onclick={() => quitar(a.id)}><Trash2 size={13} /> Quitar</button>
					</p>
				{/each}
				{#if fotosDano.length < 4}
					<div class="botones">
						<button type="button" class="boton" onclick={() => capturar('PRE_DANO', 'camara')} disabled={capturando}>
							<Camera size={15} aria-hidden="true" /> Tomar foto
						</button>
						<button type="button" class="boton" onclick={() => capturar('PRE_DANO', 'galeria')} disabled={capturando}>
							<Image size={15} aria-hidden="true" /> Desde la galería
						</button>
					</div>
				{/if}
			</div>

		<!-- ── 3 · Videos ────────────────────────────────────────────────── -->
		{:else if paso.id === 'video'}
			<p class="ayuda">
				Grabe cada uno siguiendo la indicación. <strong>Si no puede grabar alguno, continúe
				igual</strong>: no perderá su turno por eso.
			</p>
			{#each catalogo.categorias_video as c (c.id)}
				<GrabadorVideo
					categoria={c}
					{registroId}
					alSubir={() => (adjuntos = [...adjuntos, { id: crypto.randomUUID(), tipo: 'VIDEO', bytes: 0 }])}
					alSubiendo={(id, activo) => {
						videosSubiendo = activo
							? [...videosSubiendo.filter((v) => v !== id), id]
							: videosSubiendo.filter((v) => v !== id);
					}}
				/>
			{/each}

		<!-- ── 4 · Autorización ──────────────────────────────────────────── -->
		{:else if paso.id === 'envio'}
			<div class="resumen">
				<p><b>{datos.nombre_completo || '—'}</b></p>
				<p>C.C. {datos.documento} · {datos.telefono}</p>
				<p>{datos.direccion}</p>
				<p class="tenue">
					{adjuntos.length}
					{adjuntos.length === 1 ? 'archivo adjunto' : 'archivos adjuntos'} ·
					{datos.senales.length}
					{datos.senales.length === 1 ? 'daño marcado' : 'daños marcados'}
				</p>
			</div>

			<AutorizacionDatos bind:aceptado={datos.autoriza_datos} error={errores.autoriza_datos ?? ''} />
		{/if}

		<nav>
			{#if indice > 0}
				<button type="button" class="boton" onclick={anterior} disabled={guardando}>
					<ArrowLeft size={16} aria-hidden="true" /> Atrás
				</button>
			{/if}

			{#if esUltimo}
				<!-- «Guardar» y no «Enviar»: todavía no sale nada del teléfono. -->
				<button type="button" class="boton boton--lleno" onclick={guardarTodo} disabled={guardando}>
					{#if guardando}
						<LoaderCircle size={16} class="girando" aria-hidden="true" /> Guardando…
					{:else}
						<Save size={16} aria-hidden="true" /> Guardar mi registro
					{/if}
				</button>
			{:else}
				<button type="button" class="boton boton--lleno" onclick={siguiente}>
					Siguiente <ArrowRight size={16} aria-hidden="true" />
				</button>
			{/if}
		</nav>
	{/if}
</main>

<style>
	main { padding: 1.2rem 1rem 3rem; color: #16243f; max-width: 34rem; margin: 0 auto; }
	.entidad { margin: 0; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: #647189; }
	h1 { margin: 0.15rem 0 0.3rem; font-size: 1.2rem; }
	.pasos { margin: 0; font-size: 0.78rem; color: #647189; }
	.barra { height: 4px; border-radius: 2px; background: #e1e8f2; margin: 0.5rem 0 0.8rem; overflow: hidden; }
	.barra div { height: 100%; background: #1577d6; transition: width 0.2s; }
	.ayuda { margin: 0 0 1.2rem; font-size: 0.86rem; line-height: 1.5; color: #647189; }
	.tenue { color: #647189; font-size: 0.85rem; }

	.alerta {
		display: flex; align-items: flex-start; gap: 0.4rem;
		padding: 0.7rem 0.8rem; border-radius: 9px;
		background: #fbe7e4; border: 1px solid #d23b2c;
		font-size: 0.84rem; line-height: 1.45;
	}

	.campo { display: block; margin-bottom: 1rem; border: 0; padding: 0; }
	.campo > span, .campo legend, .etiqueta { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; }
	.campo input, .campo textarea, .campo select {
		width: 100%; padding: 0.65rem 0.7rem; font: inherit; font-size: 1rem;
		border: 1px solid #c9d5ea; border-radius: 9px; background: #fff; color: inherit;
	}
	.campo small { display: block; margin-top: 0.25rem; font-size: 0.76rem; color: #647189; line-height: 1.4; }
	.campo em { display: block; margin-top: 0.25rem; font-size: 0.78rem; color: #d23b2c; font-style: normal; }

	.opciones { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
	.opcion { display: flex; gap: 0.5rem; padding: 0.7rem; border: 2px solid #e1e8f2; border-radius: 10px; background: #fff; }
	.opcion--si { border-color: #1577d6; background: #e5f0fc; }
	.opcion span { font-size: 0.88rem; font-weight: 600; }
	.opcion small { display: block; font-weight: 400; font-size: 0.72rem; color: #647189; }

	.botones { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
	.boton {
		display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem;
		min-height: 44px; padding: 0.5rem 0.9rem; font: inherit; font-size: 0.88rem;
		border: 1px solid #c9d5ea; border-radius: 9px; background: #fff; color: inherit;
		text-decoration: none;
	}
	.boton--lleno { background: #1577d6; border-color: #1577d6; color: #fff; }

	.adjunto {
		display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
		margin: 0.4rem 0 0; padding: 0.5rem 0.7rem; font-size: 0.82rem;
		border: 1px solid #e1e8f2; border-radius: 9px; background: #f7fafd;
	}
	.adjunto button {
		display: inline-flex; align-items: center; gap: 0.25rem;
		border: 0; background: none; font: inherit; font-size: 0.78rem; color: #d23b2c;
	}

	.ok { display: flex; align-items: center; gap: 0.35rem; margin: 0; font-size: 0.85rem; color: #1e8c5e; }

	.resumen { padding: 0.9rem; border: 1px solid #e1e8f2; border-radius: 11px; background: #f7fafd; margin-bottom: 1.2rem; }
	.resumen p { margin: 0 0 0.2rem; font-size: 0.87rem; line-height: 1.4; }

	nav { display: flex; gap: 0.6rem; margin-top: 1.6rem; padding-top: 1.1rem; border-top: 1px solid #e1e8f2; }
	nav .boton { flex: 1; min-height: 48px; font-size: 0.95rem; }

	.fin { text-align: center; padding: 2rem 0.5rem; color: #1e8c5e; }
	.fin h1 { color: #16243f; margin: 0.6rem 0 0.5rem; }
	.fin p { color: #16243f; font-size: 0.9rem; line-height: 1.55; margin: 0 0 0.8rem; }
	.fin__nota { color: #647189 !important; font-size: 0.84rem !important; }
	.fin__aviso {
		display: flex; align-items: flex-start; gap: 0.4rem; text-align: left;
		padding: 0.7rem 0.8rem; border-radius: 9px; background: #fcefd9; border: 1px solid #e3b455;
		font-size: 0.83rem !important; margin-bottom: 1.2rem !important;
	}
	.fin .boton { width: 100%; margin-bottom: 0.5rem; }
</style>
