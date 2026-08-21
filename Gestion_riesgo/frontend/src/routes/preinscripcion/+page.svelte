<script lang="ts">
	// Pre-inscripción ciudadana para la inspección de viviendas afectadas.
	//
	// La abre una persona que perdió parte de su casa, desde su celular, sola y
	// probablemente alterada. No tiene cuenta ni va a tenerla. Eso manda sobre
	// todas las decisiones de esta pantalla:
	//
	//  • Se pide lo mínimo para llegar a la casa y poder llamar. Cada campo de
	//    más es un motivo para abandonar el formulario a la mitad.
	//  • Nada de datos sensibles. Género, pertenencia étnica y composición del
	//    hogar los levanta el funcionario en la visita, explicando el aviso de
	//    viva voz, como manda la Ley 1581.
	//  • Una sola página con secciones, no ocho pasos: quien llena esto lo hace
	//    una vez en su vida y necesita ver de un vistazo qué le van a preguntar.
	//
	// Y lo que esto NO es: una inspección. Es una solicitud de turno. La
	// evaluación del daño y el combo de materiales siguen siendo del profesional
	// con tarjeta.

	import { onMount } from 'svelte';
	import { browser } from '$app/environment';
	import {
		CheckCircle2, LoaderCircle, MapPin, Send, ShieldCheck, TriangleAlert
	} from '@lucide/svelte';
	import { ApiError } from '$lib/api/client';
	import { preinscripcionApi } from '$lib/api/servicios';
	import logo from '$lib/assets/logo-jamundi.svg';
	import SubidaEvidencias from '$lib/rufe-form/componentes/SubidaEvidencias.svelte';
	import { GestorEvidencias, RUTAS_PUBLICAS_CARGA } from '$lib/rufe-form/evidencias.svelte';

	type Catalogos = Awaited<ReturnType<typeof preinscripcionApi.catalogos>>;

	let catalogos = $state<Catalogos | null>(null);
	let cargando = $state(true);
	let errorCarga = $state('');

	let enviando = $state(false);
	let errorEnvio = $state('');
	let errores = $state<Record<string, string>>({});
	let resultado = $state<{ radicado: string; duplicada?: boolean } | null>(null);

	// Las fotos comparten toda la maquinaria del censo —compresión en el
	// teléfono, cola, reintento— apuntando a las rutas públicas. La original
	// nunca sale del aparato: lo que sube es siempre la versión optimizada.
	let evidencias = $state<GestorEvidencias | null>(null);
	let detenerEvidencias: (() => void) | null = null;

	let ubicando = $state(false);
	let avisoUbicacion = $state<string | null>(null);

	// Identificador estable de este envío: si la solicitud entra pero la
	// respuesta se pierde por mala señal, reintentar devuelve el mismo radicado
	// en vez de inscribir dos veces a la misma familia.
	const envioId = crypto.randomUUID();

	let datos = $state({
		nombre_completo: '',
		documento: '',
		telefono: '',
		correo: '',
		direccion: '',
		corregimiento: '',
		vereda: '',
		descripcion_dano: '',
		autoriza_datos: false,
		latitud: null as number | null,
		longitud: null as number | null,
		precision_m: null as number | null,
		// Trampa para robots: oculta por CSS, una persona nunca la ve.
		sitio_web: ''
	});

	onMount(() => {
		void (async () => {
			try {
				catalogos = await preinscripcionApi.catalogos();

				evidencias = new GestorEvidencias(
					{
						PRE_CEDULA: catalogos.limites.fotos_cedula,
						PRE_DANO: catalogos.limites.fotos_dano
					},
					// La clave del borrador es este envío: las fotos viven atadas a
					// él y no se mezclan con las de otra solicitud del mismo aparato.
					`preinscripcion-${envioId}`,
					RUTAS_PUBLICAS_CARGA
				);
				detenerEvidencias = evidencias.iniciar();
			} catch {
				errorCarga = 'No se pudo cargar el formulario. Revise su conexión e intente de nuevo.';
			} finally {
				cargando = false;
			}
		})();

		return () => detenerEvidencias?.();
	});

	function usarMiUbicacion() {
		if (!browser || !navigator.geolocation) {
			avisoUbicacion = 'Su navegador no permite compartir la ubicación.';

			return;
		}

		ubicando = true;
		avisoUbicacion = null;

		navigator.geolocation.getCurrentPosition(
			(posicion) => {
				datos.latitud = Number(posicion.coords.latitude.toFixed(7));
				datos.longitud = Number(posicion.coords.longitude.toFixed(7));
				datos.precision_m = Math.round(posicion.coords.accuracy);
				ubicando = false;
				avisoUbicacion = 'Ubicación agregada. Así podremos encontrar su vivienda.';
			},
			() => {
				ubicando = false;
				avisoUbicacion =
					'No se pudo obtener la ubicación. Puede continuar: con la dirección escrita es suficiente.';
			},
			{ enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
		);
	}

	function quitarUbicacion() {
		datos.latitud = null;
		datos.longitud = null;
		datos.precision_m = null;
		avisoUbicacion = 'Ubicación retirada.';
	}

	async function enviar(evento: SubmitEvent) {
		evento.preventDefault();
		if (!catalogos || enviando) return;

		enviando = true;
		errorEnvio = '';
		errores = {};

		try {
			const r = await preinscripcionApi.enviar({
				...datos,
				envio_id: envioId,
				aviso_version: catalogos.aviso_version,
				// El servidor adopta las fotos de esta carga al recibir la
				// solicitud; sin el token quedarían huérfanas hasta caducar.
				...(evidencias?.carga ? { carga: evidencias.carga } : {})
			});

			resultado = { radicado: r.radicado, duplicada: r.duplicada };
			if (browser) window.scrollTo({ top: 0, behavior: 'smooth' });
		} catch (e) {
			if (e instanceof ApiError) {
				errorEnvio = e.message;
				errores = e.errors;
			} else {
				errorEnvio = 'No se pudo enviar su solicitud. Intente de nuevo en unos minutos.';
			}
		} finally {
			enviando = false;
		}
	}
</script>

<svelte:head>
	<title>Pre-inscripción · Inspección de viviendas afectadas · Jamundí</title>
	<meta
		name="description"
		content="Registre su vivienda afectada para que la Alcaldía de Jamundí programe una inspección."
	/>
</svelte:head>

<div class="pagina">
	<header class="marca">
		<img src={logo} alt="" aria-hidden="true" />
		<div>
			<p class="marca__entidad">Alcaldía de Jamundí</p>
			<h1 class="marca__titulo">Pre-inscripción para inspección de vivienda</h1>
		</div>
	</header>

	{#if cargando}
		<p class="cargando"><LoaderCircle size={18} class="girando" aria-hidden="true" /> Cargando…</p>
	{:else if errorCarga}
		<p class="aviso aviso--error" role="alert">{errorCarga}</p>
	{:else if resultado}
		<!-- El radicado es lo único que la familia se lleva. Se muestra grande y
		     se pide anotarlo: no hay consulta en línea por radicado, a propósito. -->
		<div class="tarjeta cierre">
			<CheckCircle2 size={40} aria-hidden="true" />
			<h2>
				{resultado.duplicada ? 'Su vivienda ya estaba registrada' : 'Solicitud registrada'}
			</h2>
			<p class="cierre__radicado">{resultado.radicado}</p>
			<p>
				{#if resultado.duplicada}
					Ya teníamos una solicitud para esta vivienda y esta cédula, así que conserva el mismo
					número. No hace falta volver a registrarse.
				{:else}
					Anote este número. Es el que debe dar si llama a preguntar por su solicitud.
				{/if}
			</p>
			<p class="cierre__nota">
				Un profesional de la Alcaldía revisará su caso y lo contactará al teléfono que registró para
				programar la visita. <strong>Registrarse no garantiza por sí solo la entrega de
				materiales</strong>: eso lo decide la inspección técnica de la vivienda.
			</p>
		</div>
	{:else}
		<p class="intro">
			Si su vivienda resultó afectada, regístrela aquí para que la Alcaldía programe una visita
			técnica. Le tomará unos tres minutos y solo necesita sus datos de contacto y la dirección.
		</p>

		{#if errorEnvio}
			<p class="aviso aviso--error" role="alert">
				<TriangleAlert size={15} aria-hidden="true" />
				{errorEnvio}
			</p>
		{/if}

		<form onsubmit={enviar} novalidate>
			<section class="tarjeta">
				<h2 class="tarjeta__titulo">Sus datos</h2>

				<label class="campo">
					<span class="campo__etiqueta">Nombre y apellidos *</span>
					<input class="campo__control" bind:value={datos.nombre_completo} autocomplete="name" />
					{#if errores.nombre_completo}
						<span class="campo__error">{errores.nombre_completo}</span>
					{/if}
				</label>

				<label class="campo">
					<span class="campo__etiqueta">Cédula *</span>
					<input
						class="campo__control"
						inputmode="numeric"
						bind:value={datos.documento}
						placeholder="Sin puntos ni espacios"
					/>
					{#if errores.documento}<span class="campo__error">{errores.documento}</span>{/if}
				</label>

				<label class="campo">
					<span class="campo__etiqueta">Teléfono *</span>
					<input
						class="campo__control"
						type="tel"
						inputmode="tel"
						bind:value={datos.telefono}
						autocomplete="tel"
					/>
					<span class="campo__ayuda">A este número lo llamaremos para coordinar la visita.</span>
					{#if errores.telefono}<span class="campo__error">{errores.telefono}</span>{/if}
				</label>

				<label class="campo">
					<span class="campo__etiqueta">Correo electrónico</span>
					<input
						class="campo__control"
						type="email"
						bind:value={datos.correo}
						autocomplete="email"
					/>
					<span class="campo__ayuda">Opcional.</span>
					{#if errores.correo}<span class="campo__error">{errores.correo}</span>{/if}
				</label>
			</section>

			<section class="tarjeta">
				<h2 class="tarjeta__titulo">Dónde queda la vivienda</h2>

				<label class="campo">
					<span class="campo__etiqueta">Dirección *</span>
					<input
						class="campo__control"
						bind:value={datos.direccion}
						placeholder="Calle 10 # 5-32, casa de dos pisos"
					/>
					<span class="campo__ayuda">Escríbala como se la daría a alguien que va a buscarla.</span>
					{#if errores.direccion}<span class="campo__error">{errores.direccion}</span>{/if}
				</label>

				<label class="campo">
					<span class="campo__etiqueta">Corregimiento</span>
					<select class="campo__control" bind:value={datos.corregimiento}>
						<option value="">Ninguno (zona urbana)</option>
						{#each catalogos?.corregimientos ?? [] as c (c)}
							<option value={c}>{c}</option>
						{/each}
					</select>
					{#if errores.corregimiento}<span class="campo__error">{errores.corregimiento}</span>{/if}
				</label>

				<label class="campo">
					<span class="campo__etiqueta">Vereda o barrio</span>
					<input class="campo__control" bind:value={datos.vereda} />
				</label>

				<div class="ubicacion">
					<p class="ubicacion__titulo">Ubicación en el mapa (opcional)</p>
					<p class="ubicacion__ayuda">
						Si está en la vivienda ahora, tomar la ubicación nos ayuda mucho a encontrarla. Puede
						continuar sin ella.
					</p>

					{#if datos.latitud !== null}
						<p class="ubicacion__valor">
							<MapPin size={15} aria-hidden="true" />
							Ubicación tomada
							{#if datos.precision_m}(precisión de unos {datos.precision_m} m){/if}
						</p>
						<button type="button" class="boton boton--suave" onclick={quitarUbicacion}>
							Quitar la ubicación
						</button>
					{:else}
						<button
							type="button"
							class="boton boton--suave"
							onclick={usarMiUbicacion}
							disabled={ubicando}
						>
							{#if ubicando}
								<LoaderCircle size={15} class="girando" aria-hidden="true" />
								Obteniendo…
							{:else}
								<MapPin size={15} aria-hidden="true" />
								Tomar la ubicación aquí
							{/if}
						</button>
					{/if}

					<p class="ubicacion__estado" role="status" aria-live="polite">{avisoUbicacion ?? ''}</p>
				</div>
			</section>

			<section class="tarjeta">
				<h2 class="tarjeta__titulo">Qué le pasó a la vivienda</h2>

				<label class="campo">
					<span class="campo__etiqueta">Cuéntenos brevemente</span>
					<textarea
						class="campo__control"
						rows="4"
						maxlength="1000"
						bind:value={datos.descripcion_dano}
						placeholder="Ej.: se agrietaron los muros de la sala y se cayó parte del techo de la cocina."
					></textarea>
					<span class="campo__ayuda">
						Opcional, pero ayuda a priorizar. No hace falta que sea técnico: descríbalo con sus
						palabras.
					</span>
					{#if errores.descripcion_dano}
						<span class="campo__error">{errores.descripcion_dano}</span>
					{/if}
				</label>
			</section>

			{#if evidencias}
				<section class="tarjeta">
					<h2 class="tarjeta__titulo">Fotos</h2>
					<p class="tarjeta__nota">
						Las fotos se reducen en su celular antes de enviarse, así que gastan pocos datos. Si
						está sin señal, espere un momento y se enviarán solas.
					</p>

					<SubidaEvidencias
						gestor={evidencias}
						tipo="PRE_CEDULA"
						titulo="Foto de su cédula"
						ayuda="Del lado de los datos, sobre una superficie plana y sin reflejos. Nos sirve para confirmar que la solicitud es suya."
						textoCamara="Tomar foto de la cédula"
					/>

					<SubidaEvidencias
						gestor={evidencias}
						tipo="PRE_DANO"
						titulo="Fotos del daño"
						ayuda="Cómo quedó la vivienda. No son obligatorias, pero ayudan a priorizar la visita."
						textoCamara="Tomar foto del daño"
					/>
				</section>
			{/if}

			<section class="tarjeta">
				<h2 class="tarjeta__titulo">Autorización de datos</h2>

				<!--
					La Ley 1581 exige que la autorización sea informada, y aquí no hay un
					funcionario delante que la explique. Por eso el texto es largo y dice
					para qué se usan los datos, quién los trata y qué derechos tiene.
					La versión aceptada se guarda con la solicitud: eso es lo que prueba
					el consentimiento, no lo que hoy diga esta pantalla.
				-->
				<label class="opcion" class:opcion--activa={datos.autoriza_datos}>
					<input type="checkbox" bind:checked={datos.autoriza_datos} />
					<span class="opcion__texto">
						Autorizo de manera libre, previa, expresa e informada a la
						<strong>Alcaldía de Jamundí</strong> a tratar los datos personales que entrego en este
						formulario —nombre, cédula, teléfono, correo, dirección, ubicación de mi vivienda y las
						<strong>fotografías que adjunto, incluida la de mi documento de identidad</strong>— con
						la única finalidad de programar y realizar la inspección técnica de la vivienda
						afectada y adelantar la atención de la emergencia. Sé que puedo conocer, actualizar,
						rectificar y solicitar la supresión de mis datos ante la Alcaldía de Jamundí.
					</span>
				</label>
				{#if errores.autoriza_datos}
					<span class="campo__error">{errores.autoriza_datos}</span>
				{/if}

				<p class="legal">
					<ShieldCheck size={14} aria-hidden="true" />
					<span>
						No le pedimos datos sensibles. Si su caso avanza, el resto de la información la tomará
						un funcionario durante la visita.
					</span>
				</p>
			</section>

			<!-- Trampa antirrobot. Oculta y fuera del orden de tabulación. -->
			<div class="trampa" aria-hidden="true">
				<label for="sitio_web">No llene este campo</label>
				<input id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off" bind:value={datos.sitio_web} />
			</div>

			<button class="boton boton--grande" type="submit" disabled={enviando}>
				{#if enviando}
					<LoaderCircle size={17} class="girando" aria-hidden="true" />
					Enviando…
				{:else}
					<Send size={17} aria-hidden="true" />
					Enviar mi solicitud
				{/if}
			</button>
		</form>
	{/if}
</div>

<style>
	.pagina {
		max-width: 40rem;
		margin: 0 auto;
		padding: 1.2rem 1rem 3rem;
	}

	.marca {
		display: flex;
		align-items: center;
		gap: 0.7rem;
		margin-bottom: 1.2rem;
	}

	.marca img {
		width: 44px;
		height: 44px;
		flex: none;
	}

	.marca__entidad {
		margin: 0;
		font-size: 0.78rem;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: var(--color-muted);
	}

	.marca__titulo {
		margin: 0.1rem 0 0;
		font-size: 1.15rem;
		line-height: 1.25;
	}

	.intro {
		margin: 0 0 1.2rem;
		font-size: 0.9rem;
		line-height: 1.5;
		color: var(--color-muted);
	}

	.cargando {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		color: var(--color-muted);
	}

	section.tarjeta {
		margin-bottom: 1rem;
	}

	.legal {
		display: flex;
		align-items: flex-start;
		gap: 0.4rem;
		margin: 0.8rem 0 0;
		font-size: 0.78rem;
		line-height: 1.45;
		color: var(--color-muted);
	}

	.legal :global(svg) {
		flex: none;
		margin-top: 0.15rem;
	}

	.boton--grande {
		width: 100%;
		justify-content: center;
		min-height: 3rem;
		font-size: 1rem;
	}

	.cierre {
		text-align: center;
		padding: 2rem 1.2rem;
		color: var(--color-success);
	}

	.cierre h2 {
		margin: 0.6rem 0 0.2rem;
		color: var(--color-text);
	}

	.cierre p {
		color: var(--color-text);
		font-size: 0.9rem;
		line-height: 1.5;
	}

	.cierre__radicado {
		margin: 0.8rem 0;
		font-family: ui-monospace, 'SFMono-Regular', monospace;
		font-size: 1.5rem;
		font-weight: 700;
		letter-spacing: 0.04em;
		color: var(--color-text) !important;
	}

	.cierre__nota {
		margin-top: 1rem;
		padding-top: 0.9rem;
		border-top: 1px solid var(--color-border);
		font-size: 0.83rem !important;
		color: var(--color-muted) !important;
	}

	/* Fuera de la pantalla, no `display:none`: algunos robots ignoran lo que no
	   se dibuja, y así el campo sigue existiendo para ellos. */
	.trampa {
		position: absolute;
		left: -9999px;
		width: 1px;
		height: 1px;
		overflow: hidden;
	}
</style>
