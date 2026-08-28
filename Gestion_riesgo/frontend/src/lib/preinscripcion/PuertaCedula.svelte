<script lang="ts">
	// La primera pantalla: la cédula, antes que nada.
	//
	// La pre-inscripción dejó de ser un formulario abierto. Es la CONTINUACIÓN
	// del proceso de quien ya fue censado en campo, así que aquí se comprueba
	// que la cédula esté en el RUFE y solo entonces se abre el formulario.
	//
	// ── Lo que manda sobre esta pantalla ─────────────────────────────────────
	//
	// Es la única del sistema que puede decirle que NO a una familia
	// damnificada. Eso obliga a tres cosas:
	//
	//  1. Que el «no» nunca sea un callejón. Siempre sale el teléfono, marcable
	//     de un toque, porque quien lo lee está en un celular.
	//  2. Que se distinga «su cédula no aparece» de «no pudimos consultar».
	//     Confundirlos manda a la línea de atención a alguien que solo se quedó
	//     sin señal, y llena el conmutador de llamadas que no eran.
	//  3. Que se pueda reintentar sin recargar. La causa más probable de un «no»
	//     es un dígito mal tecleado, y la segunda, que la casa quedara censada a
	//     nombre de otra persona del hogar.

	import {
		ArrowLeft,
		CircleAlert,
		IdCard,
		LoaderCircle,
		Phone,
		SendHorizontal,
		ShieldCheck
	} from '@lucide/svelte';
	import { ApiError } from '$lib/api/client';
	import { preinscripcionApi, sinCensoApi } from '$lib/api/servicios';
	import SubidaEvidencias from '$lib/rufe-form/componentes/SubidaEvidencias.svelte';
	import type { GestorEvidencias } from '$lib/rufe-form/evidencias.svelte';
	import type { HogarCenso } from './hogar';
	import { erroresSolicitud, solicitudVacia, ZONAS, type ZonaSinCenso } from '$lib/sin-censo/solicitud';
	import { LINEA_ATENCION, normalizar, revisarCedula } from './puerta';

	const POLITICA_DATOS =
		'https://portal.gestiondelriesgo.gov.co/Documents/Ley_Transparencia/Politica-de-Tratamiento-de-Datos-Personales.pdf';

	type CatalogosPuerta = { corregimientos: string[]; aviso_version: string } | null;

	type Props = {
		/**
		 * Se llama con la cédula ya normalizada cuando el censo la reconoce.
		 *
		 * `hogar` llega con lo que el censo sabe de esa casa cuando la persona
		 * subió la foto de su cédula, y en `null` cuando decidió seguir sin
		 * traer sus datos o no había señal para subirla.
		 */
		onEntrar: (documento: string, hogar?: HogarCenso | null) => void;
		/** Se enciende cuando se entró sin haber podido verificar, por no haber red. */
		entroSinVerificar?: (sinVerificar: boolean) => void;
		/**
		 * Corregimientos y versión del aviso, para la solicitud de quien no
		 * aparece en el censo. Puede llegar en `null` mientras la página los
		 * carga: mientras tanto esa vía se muestra deshabilitada en vez de dejar
		 * mandar un corregimiento inventado o un consentimiento sin versión.
		 */
		catalogos?: CatalogosPuerta;
		/**
		 * El gestor de archivos de la página, para la foto de la cédula.
		 *
		 * Es el MISMO que usa el resto del formulario: la foto que se sube aquí
		 * es la que la solicitud llevaba de todos modos, solo que antes. A la
		 * persona no se le pide nada de más.
		 */
		evidencias?: GestorEvidencias | null;
	};

	let { onEntrar, entroSinVerificar, catalogos = null, evidencias = null }: Props = $props();

	/**
	 * En qué pantalla de la puerta se está.
	 *
	 * `foto` existe porque la de al lado —la que devuelve nombre, teléfono,
	 * dirección y quién vive en la casa— no puede ser gratuita. Preguntar por
	 * una cédula ajena tiene que costar subir una imagen que queda guardada
	 * atada al intento.
	 */
	let fase = $state<'cedula' | 'foto'>('cedula');
	let verificada = $state('');
	let trayendo = $state(false);
	let errorFoto = $state('');

	/** La foto ya está EN EL SERVIDOR, no solo elegida en el teléfono. */
	const fotoSubida = $derived(
		(evidencias?.archivosDe('PRE_CEDULA') ?? []).some((a) => a.estado === 'listo')
	);

	$effect(() => {
		entroSinVerificar?.(sinRed);
	});

	let cedula = $state('');
	/** Se entró sin poder verificar: hay que decirlo dentro del formulario. */
	let sinRed = $state(false);
	let consultando = $state(false);
	let error = $state('');
	/** El «no» del censo, que no es un error del formulario y se ve distinto. */
	let negado = $state(false);

	async function consultar(evento: SubmitEvent) {
		evento.preventDefault();

		const aviso = revisarCedula(cedula);
		if (aviso !== '') {
			error = aviso;
			negado = false;

			return;
		}

		consultando = true;
		error = '';
		negado = false;

		const documento = normalizar(cedula);

		try {
			const r = await preinscripcionApi.verificar(documento);

			if (r.habilitado) {
				// Sin gestor de archivos —la página todavía carga, o no hubo red
				// para abrir la carga— no se puede pedir la foto. Se entra sin
				// precargar: el formulario en blanco funciona igual y nadie se
				// queda fuera por una pantalla que es una comodidad.
				if (evidencias === null) {
					onEntrar(documento, null);

					return;
				}

				verificada = documento;
				fase = 'foto';

				return;
			}

			negado = true;
		} catch (e) {
			// Sin conexión NO es «su cédula no aparece», y tampoco puede ser un
			// muro. Quien abre esto desde su casa, con la señal que le queda,
			// tiene que poder llenar el formulario: se sigue adelante y se avisa.
			//
			// No es un agujero. Quien decide es el servidor al recibir el envío, y
			// esa comprobación no se puede saltar desde el navegador. Lo que se
			// pierde es avisar antes; lo que se gana es que una familia sin señal
			// pueda dejar su solicitud lista para cuando la haya.
			if (e instanceof ApiError && e.status === 0) {
				sinRed = true;
				onEntrar(documento);

				return;
			}

			if (e instanceof ApiError) {
				error = e.message;
			} else {
				error = 'No se pudo verificar su cédula. Inténtelo de nuevo en unos minutos.';
			}
		} finally {
			consultando = false;
		}
	}

	async function traerDatos() {
		if (trayendo) return;

		trayendo = true;
		errorFoto = '';

		try {
			const { hogar } = await preinscripcionApi.datosCenso(verificada, evidencias?.carga ?? '');
			onEntrar(verificada, hogar);
		} catch (e) {
			// Que no se puedan traer los datos NO puede dejar a nadie fuera de su
			// propio formulario: se entra igual, en blanco. Lo que se pierde es
			// una comodidad, no el trámite.
			if (e instanceof ApiError && e.status === 0) {
				onEntrar(verificada, null);

				return;
			}

			errorFoto =
				e instanceof ApiError
					? e.message
					: 'No pudimos traer sus datos. Puede continuar y escribirlos usted.';
		} finally {
			trayendo = false;
		}
	}

	function volverAIntentar() {
		negado = false;
		error = '';
		cedula = '';
		mostrarSinCenso = false;
		radicadoSinCenso = '';
	}

	// ── Quien no aparece en el censo, pero puede necesitar ayuda igual ────────
	//
	// Antes esta pantalla terminaba en el teléfono de la línea de atención y
	// nada más: si el caso era real, todo rastro de esa visita se perdía. Esto
	// deja lo mínimo —nombre, teléfono y una ubicación aproximada— para que un
	// funcionario decida si de ahí nace una ficha RUFE. El teléfono se queda
	// igual, como salida principal; esto es un camino más, no un reemplazo.

	let mostrarSinCenso = $state(false);
	let sc = $state(solicitudVacia());
	let erroresSC = $state<Record<string, string>>({});
	let aceptaSC = $state(false);
	let enviandoSC = $state(false);
	let errorEnvioSC = $state('');
	let radicadoSinCenso = $state('');
	const envioIdSC = crypto.randomUUID();

	/** El formulario, para poder desplazarlo a la vista al abrirlo. */
	let formularioSinCensoEl = $state<HTMLFormElement | undefined>();

	/**
	 * Este enlace suele quedar pegado al borde inferior de la pantalla —es lo
	 * último de la tarjeta del «no»—, así que el formulario que abre aparece
	 * por debajo del área visible. Sin el scroll, tocar el enlace parece no
	 * hacer nada.
	 */
	function abrirFormularioSinCenso() {
		mostrarSinCenso = true;
		requestAnimationFrame(() => {
			formularioSinCensoEl?.scrollIntoView({ behavior: 'smooth', block: 'start' });
		});
	}

	async function enviarSinCenso(evento: SubmitEvent) {
		evento.preventDefault();
		if (!catalogos) return;

		const fallos = erroresSolicitud(sc);
		if (!aceptaSC) fallos.autoriza_datos = 'Debe autorizar el tratamiento de sus datos para continuar.';
		erroresSC = fallos;
		if (Object.keys(fallos).length > 0) return;

		enviandoSC = true;
		errorEnvioSC = '';

		try {
			const r = await sinCensoApi.crear({
				...sc,
				documento: normalizar(cedula),
				autoriza_datos: aceptaSC,
				aviso_version: catalogos.aviso_version,
				envio_id: envioIdSC
			});

			radicadoSinCenso = r.radicado;
		} catch (e) {
			erroresSC = e instanceof ApiError ? e.errors : {};
			errorEnvioSC =
				e instanceof ApiError
					? e.message
					: 'No se pudo enviar. Inténtelo de nuevo en unos minutos.';
		} finally {
			enviandoSC = false;
		}
	}
</script>

{#if negado}
	<!-- El «no». Ocupa la pantalla entera porque es lo único que esta persona
	     tiene que leer, y termina en un número de teléfono, nunca en un punto. -->
	<section class="tarjeta puerta puerta--negada">
		<CircleAlert size={38} aria-hidden="true" />
		<h2 class="puerta__titulo">Su cédula no aparece en el censo</h2>

		<p class="puerta__texto">
			Este formulario es para las familias que ya fueron registradas en el censo de afectados
			(RUFE). No encontramos esa cédula, y por eso no podemos continuar por aquí.
		</p>

		<a class="linea" href="tel:{LINEA_ATENCION.marcar}">
			<Phone size={20} aria-hidden="true" />
			<span>
				<strong class="linea__numero">{LINEA_ATENCION.legible}</strong>
				<span class="linea__extension">Extensión {LINEA_ATENCION.extension}</span>
			</span>
		</a>

		<p class="puerta__texto">
			Llame a la línea de atención de {LINEA_ATENCION.entidad}. Allí revisan su caso y le dicen qué
			sigue. <strong>Que no aparezca aquí no significa que su caso esté cerrado.</strong>
		</p>

		<p class="puerta__pista">
			Antes de llamar, vale la pena probar otra vez. Lo más común es un dígito equivocado, y lo
			segundo, que cuando el funcionario visitó la casa la registrara a nombre de otra persona del
			hogar: pruebe con la cédula de esa persona.
		</p>

		<button type="button" class="boton boton--suave" onclick={volverAIntentar}>
			Probar con otra cédula
		</button>

		<!-- La vía corta para quien de verdad necesita ayuda y no puede esperar a
		     que le contesten el teléfono. Colapsada por defecto: el teléfono sigue
		     siendo la salida principal, y no todo el mundo la necesita. -->
		{#if radicadoSinCenso}
			<div class="tarjeta-sc tarjeta-sc--listo">
				<p class="puerta__texto">Quedó registrado. Un funcionario revisará su caso.</p>
				<p class="sc__radicado">{radicadoSinCenso}</p>
				<p class="puerta__pista">Anote este número, por si necesita mencionarlo al llamar.</p>
			</div>
		{:else if !mostrarSinCenso}
			<button
				type="button"
				class="boton boton--principal boton-sc"
				onclick={abrirFormularioSinCenso}
			>
				¿Quiere dejarnos sus datos para que lo contactemos?
			</button>
		{:else}
			<form class="tarjeta-sc" bind:this={formularioSinCensoEl} onsubmit={enviarSinCenso}>
				<h3 class="sc__titulo">Déjenos sus datos</h3>
				<p class="puerta__pista">
					Nombre, teléfono y más o menos dónde vive. Con eso un funcionario puede llamarlo y ver si
					su caso necesita una ficha del censo.
				</p>

				<label class="campo">
					<span class="campo__etiqueta">Nombres</span>
					<input class="campo__control" bind:value={sc.nombres} disabled={enviandoSC} />
					{#if erroresSC.nombres}
						<span class="campo__error" role="alert">{erroresSC.nombres}</span>
					{/if}
				</label>

				<label class="campo">
					<span class="campo__etiqueta">Apellidos</span>
					<input class="campo__control" bind:value={sc.apellidos} disabled={enviandoSC} />
					{#if erroresSC.apellidos}
						<span class="campo__error" role="alert">{erroresSC.apellidos}</span>
					{/if}
				</label>

				<label class="campo">
					<span class="campo__etiqueta">Teléfono</span>
					<input
						class="campo__control"
						inputmode="numeric"
						bind:value={sc.telefono}
						disabled={enviandoSC}
					/>
					{#if erroresSC.telefono}
						<span class="campo__error" role="alert">{erroresSC.telefono}</span>
					{/if}
				</label>

				<fieldset class="campo campo--zona">
					<legend class="campo__etiqueta">Su vivienda está en zona</legend>
					<div class="opciones-zona">
						{#each ZONAS as z (z)}
							<label class="opcion-zona" class:opcion-zona--activa={sc.zona === z}>
								<input
									type="radio"
									name="zona-sc"
									value={z}
									checked={sc.zona === z}
									disabled={enviandoSC}
									onchange={() => (sc.zona = z as ZonaSinCenso)}
								/>
								{z === 'URBANO' ? 'Urbana' : 'Rural'}
							</label>
						{/each}
					</div>
					{#if erroresSC.zona}<span class="campo__error" role="alert">{erroresSC.zona}</span>{/if}
				</fieldset>

				{#if sc.zona === 'RURAL' && catalogos}
					<label class="campo">
						<span class="campo__etiqueta">Corregimiento (si lo sabe)</span>
						<select class="campo__control" bind:value={sc.corregimiento} disabled={enviandoSC}>
							<option value="">No lo sé</option>
							{#each catalogos.corregimientos as c (c)}
								<option value={c}>{c}</option>
							{/each}
						</select>
					</label>
				{/if}

				<label class="campo">
					<span class="campo__etiqueta">Vereda, sector o barrio</span>
					<input class="campo__control" bind:value={sc.vereda_sector_barrio} disabled={enviandoSC} />
				</label>

				<label class="campo">
					<span class="campo__etiqueta">Dirección aproximada</span>
					<input
						class="campo__control"
						bind:value={sc.direccion}
						placeholder="Como se lo explicaría a alguien que va a buscarla"
						disabled={enviandoSC}
					/>
					{#if erroresSC.direccion}
						<span class="campo__error" role="alert">{erroresSC.direccion}</span>
					{/if}
				</label>

				<label class="campo">
					<span class="campo__etiqueta">¿Qué le pasó?</span>
					<textarea
						class="campo__control"
						rows="3"
						bind:value={sc.descripcion}
						disabled={enviandoSC}
					></textarea>
					{#if erroresSC.descripcion}
						<span class="campo__error" role="alert">{erroresSC.descripcion}</span>
					{/if}
				</label>

				<label class="opcion-sc" class:opcion-sc--activa={aceptaSC}>
					<input type="checkbox" bind:checked={aceptaSC} disabled={enviandoSC} />
					<span>
						Autorizo a la <strong>Alcaldía de Jamundí</strong> a tratar el nombre, el teléfono y la
						ubicación que dejo aquí, para que un funcionario revise mi caso y me contacte. Es
						voluntario, y no incluye fotos ni datos sensibles.
					</span>
				</label>
				{#if erroresSC.autoriza_datos}
					<span class="campo__error" role="alert">{erroresSC.autoriza_datos}</span>
				{/if}
				<a class="enlace-politica" href={POLITICA_DATOS} target="_blank" rel="noopener noreferrer">
					Política de tratamiento de datos personales (se abre en otra pestaña)
				</a>

				{#if errorEnvioSC}<p class="campo__error" role="alert">{errorEnvioSC}</p>{/if}

				<button
					type="submit"
					class="boton puerta__continuar"
					disabled={enviandoSC || !catalogos}
				>
					{#if enviandoSC}
						<LoaderCircle size={16} class="girando" aria-hidden="true" /> Enviando…
					{:else if !catalogos}
						<LoaderCircle size={16} class="girando" aria-hidden="true" /> Cargando…
					{:else}
						<SendHorizontal size={16} aria-hidden="true" /> Enviar mis datos
					{/if}
				</button>
			</form>
		{/if}
	</section>
{:else if fase === 'foto' && evidencias}
	<!--
		Segunda puerta: la foto de la cédula.

		No es un trámite de más. La pantalla siguiente enseña el nombre, el
		teléfono, la dirección y quiénes viven en esa casa —datos de una familia
		damnificada—, y una pantalla pública no puede repartir eso a quien acierte
		un número. Subir una imagen que queda guardada junto al intento convierte
		recorrer el censo en algo caro y con rastro.

		Y no se le pide nada de más a la familia: es la misma foto que el
		formulario pedía en el paso 1, solo que antes.
	-->
	<section class="tarjeta puerta">
		<IdCard size={30} aria-hidden="true" />
		<h2 class="puerta__titulo">Su cédula está registrada</h2>

		<p class="puerta__texto">
			Tome una foto de su cédula y le mostramos los datos que ya tenemos de su vivienda, para que
			usted los revise y corrija lo que haga falta.
		</p>

		<div class="puerta__subida">
			<SubidaEvidencias
				gestor={evidencias}
				tipo="PRE_CEDULA"
				titulo="Foto de su cédula"
				ayuda="Del lado de los datos, sobre una superficie plana y sin reflejos. La foto se reduce en su celular antes de enviarse, y queda con su solicitud."
				textoCamara="Tomar foto de la cédula"
			/>
		</div>

		{#if errorFoto}<p class="campo__error" role="alert">{errorFoto}</p>{/if}

		<button
			type="button"
			class="boton puerta__continuar"
			disabled={!fotoSubida || trayendo}
			onclick={traerDatos}
		>
			{#if trayendo}
				<LoaderCircle size={16} class="girando" aria-hidden="true" /> Trayendo sus datos…
			{:else}
				Ver mis datos
			{/if}
		</button>

		<!--
			La salida. Sin señal la foto no llega al servidor y el botón de arriba
			nunca se habilita: sin esto, la puerta que se abrió para ayudar sería
			un muro para quien peor conexión tiene.
		-->
		<button type="button" class="puerta__saltar" onclick={() => onEntrar(verificada, null)}>
			Continuar sin traer mis datos
		</button>

		<button type="button" class="puerta__saltar" onclick={() => { fase = 'cedula'; verificada = ''; }}>
			<ArrowLeft size={13} aria-hidden="true" />
			Escribir otra cédula
		</button>
	</section>
{:else}
	<section class="tarjeta puerta">
		<ShieldCheck size={30} aria-hidden="true" />
		<h2 class="puerta__titulo">Antes de empezar</h2>

		<p class="puerta__texto">
			Este formulario continúa el proceso de las familias que ya fueron registradas en el censo de
			afectados (RUFE), cuando un funcionario visitó la vivienda. Escriba su cédula para verificar
			que está registrada.
		</p>

		<form onsubmit={consultar}>
			<label class="campo campo--grande">
				<span class="campo__etiqueta">Número de cédula</span>
				<input
					class="campo__control"
					inputmode="numeric"
					autocomplete="off"
					bind:value={cedula}
					placeholder="Sin puntos ni espacios"
					disabled={consultando}
				/>
				{#if error}<span class="campo__error" role="alert">{error}</span>{/if}
			</label>

			<button type="submit" class="boton puerta__continuar" disabled={consultando}>
				{#if consultando}
					<LoaderCircle size={16} class="girando" aria-hidden="true" /> Verificando…
				{:else}
					Continuar
				{/if}
			</button>
		</form>

		<p class="puerta__pista">
			Solo se usa para buscarla en el censo. Si no está registrada, le indicaremos a dónde llamar.
		</p>
	</section>
{/if}

<style>
	.puerta {
		display: grid;
		justify-items: center;
		text-align: center;
		gap: 0.6rem;
	}

	.puerta :global(svg) {
		color: var(--color-primary);
	}

	.puerta--negada :global(svg) {
		color: var(--color-danger);
	}

	.puerta__titulo {
		margin: 0;
		font-size: 1.15rem;
	}

	.puerta__texto {
		margin: 0;
		max-width: 34rem;
		line-height: 1.55;
	}

	.puerta__subida {
		width: 100%;
		text-align: left;
	}

	/* Las salidas: visibles, pero por debajo de lo que se espera que haga la
	   mayoría. Quien tiene señal sube la foto; quien no, tiene por dónde salir. */
	.puerta__saltar {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		border: none;
		background: none;
		color: var(--color-muted);
		font-size: 0.83rem;
		text-decoration: underline;
		cursor: pointer;
		padding: 0.35rem;
	}

	.puerta__saltar:hover {
		color: var(--color-text);
	}

	.puerta__pista {
		margin: 0;
		max-width: 34rem;
		font-size: 0.86rem;
		line-height: 1.5;
		color: var(--color-muted);
	}

	form {
		width: 100%;
		max-width: 22rem;
		display: grid;
		gap: 0.75rem;
		margin: 0.6rem 0 0.2rem;
		text-align: left;
	}

	.puerta__continuar {
		justify-content: center;
		width: 100%;
	}

	/*
		El teléfono es un enlace `tel:` y no un texto: quien lee esto lo hace en un
		celular, y copiar un número a mano mientras se está alterado es justo la
		clase de fricción que hace que la llamada no se haga.
	*/
	.linea {
		display: inline-flex;
		align-items: center;
		gap: 0.7rem;
		margin: 0.3rem 0;
		padding: 0.7rem 1.1rem;
		border: 1px solid var(--color-border);
		border-radius: 12px;
		background: var(--color-surface-alt);
		color: inherit;
		text-decoration: none;
	}

	.linea span {
		display: grid;
		text-align: left;
	}

	.linea__numero {
		font-size: 1.25rem;
		letter-spacing: 0.02em;
		font-variant-numeric: tabular-nums;
	}

	.linea__extension {
		font-size: 0.82rem;
		color: var(--color-muted);
	}

	.boton-sc {
		margin-top: 0.4rem;
		width: 100%;
		max-width: 26rem;
		justify-content: center;
	}

	.tarjeta-sc {
		width: 100%;
		max-width: 26rem;
		margin-top: 0.6rem;
		padding: 1rem;
		border: 1px solid var(--color-border);
		border-radius: 12px;
		background: var(--color-surface-alt);
	}

	.tarjeta-sc--listo {
		display: grid;
		gap: 0.4rem;
		justify-items: center;
		text-align: center;
	}

	.sc__titulo {
		margin: 0 0 0.3rem;
		font-size: 1rem;
	}

	.sc__radicado {
		margin: 0;
		font-size: 1.15rem;
		font-weight: 600;
		letter-spacing: 0.02em;
	}

	.campo--zona {
		border: none;
		padding: 0;
		margin: 0;
		display: grid;
		gap: 0.4rem;
	}

	.opciones-zona {
		display: flex;
		gap: 0.6rem;
	}

	.opcion-zona {
		flex: 1;
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 0.4rem;
		padding: 0.5rem;
		border: 1px solid var(--color-border);
		border-radius: 10px;
		cursor: pointer;
	}

	.opcion-zona--activa {
		border-color: var(--color-primary);
		background: var(--color-surface);
	}

	.opcion-sc {
		display: flex;
		align-items: flex-start;
		gap: 0.6rem;
		padding: 0.7rem;
		border: 1px solid var(--color-border);
		border-radius: 10px;
		font-size: 0.86rem;
		line-height: 1.45;
		cursor: pointer;
	}

	.opcion-sc input {
		margin-top: 0.2rem;
	}

	.opcion-sc--activa {
		border-color: var(--color-primary);
	}

	.enlace-politica {
		font-size: 0.8rem;
		color: var(--color-muted);
	}
</style>
