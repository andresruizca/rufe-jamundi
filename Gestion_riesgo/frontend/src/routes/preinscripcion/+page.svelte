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
	//  • Por PASOS, como el censo. La versión anterior era una sola página con
	//    siete secciones, y el argumento escrito entonces era que así se veía de
	//    un vistazo todo lo que se iba a preguntar. En un celular ese vistazo no
	//    existe: es un rollo largo donde no se sabe cuánto falta y donde un error
	//    de validación al final obliga a subir a buscarlo. El censo lleva meses
	//    en producción con pasos y es el patrón que la gente de aquí reconoce.
	//
	// Y lo que esto NO es: una inspección. Es una solicitud de turno. La
	// evaluación del daño y el combo de materiales siguen siendo del profesional
	// con tarjeta. Por eso el paso 2 pregunta QUÉ VE la persona y no en qué nivel
	// del Anexo 1 clasificaría su casa.

	import { onMount } from 'svelte';
	import { browser } from '$app/environment';
	import {
		ArrowLeft, ArrowRight, Check, CheckCircle2, LoaderCircle, MapPin, Send, TriangleAlert
	} from '@lucide/svelte';
	import { ApiError } from '$lib/api/client';
	import { preinscripcionApi } from '$lib/api/servicios';
	import logo from '$lib/assets/logo-jamundi.svg';
	import IndicadorProgreso from '$lib/rufe-form/componentes/IndicadorProgreso.svelte';
	import { GestorEvidencias, RUTAS_PUBLICAS_CARGA } from '$lib/rufe-form/evidencias.svelte';
	import GrabadorVideo from '$lib/preinscripcion/GrabadorVideo.svelte';
	import { formatoSoportado } from '$lib/preinscripcion/video';
	import SelectorSenales from '$lib/preinscripcion/SelectorSenales.svelte';
	import AutorizacionDatos from '$lib/preinscripcion/AutorizacionDatos.svelte';
	import PuertaCedula from '$lib/preinscripcion/PuertaCedula.svelte';
	import ListaHogar from '$lib/preinscripcion/ListaHogar.svelte';
	import * as borradorPre from '$lib/preinscripcion/borrador';
	import CedulaDosCaras from '$lib/preinscripcion/CedulaDosCaras.svelte';
	import FotosDano from '$lib/preinscripcion/FotosDano.svelte';
	import {
		desdeCenso,
		personasParaEnviar,
		type HogarCenso,
		type PersonaHogar
	} from '$lib/preinscripcion/hogar';
	import BotonInstalar from '$lib/components/layout/BotonInstalar.svelte';
	import {
		bloqueoDeAvance,
		datosVacios,
		faltaEvidencia,
		fotosUtiles,
		paraEnviar,
		pasosVigentes,
		validarPaso,
		videosQueFaltan,
		videosQueSePiden
	} from '$lib/preinscripcion/pasos';

	type Catalogos = Awaited<ReturnType<typeof preinscripcionApi.catalogos>>;

	let catalogos = $state<Catalogos | null>(null);
	let cargando = $state(true);
	let errorCarga = $state('');

	let enviando = $state(false);
	let errorEnvio = $state('');
	let errores = $state<Record<string, string>>({});
	let resultado = $state<{
		radicado: string;
		duplicada?: boolean;
		archivosAgregados?: number;
	} | null>(null);

	// Las fotos comparten toda la maquinaria del censo —compresión en el
	// teléfono, cola, reintento— apuntando a las rutas públicas. La original
	// nunca sale del aparato: lo que sube es siempre la versión optimizada.
	let evidencias = $state<GestorEvidencias | null>(null);
	let detenerEvidencias: (() => void) | null = null;

	/**
	 * Qué categorías ya tienen su video.
	 *
	 * No bloquea el envío ni siquiera para las obligatorias: quien tiene un
	 * celular viejo o una conexión mala no puede quedarse sin turno por eso. Lo
	 * que falta se marca en la bandeja, para que quien revisa lo sepa.
	 */
	let videosListos = $state<number[]>([]);

	/**
	 * Qué videos están subiendo en este momento.
	 *
	 * Enviar el formulario con uno a medias lo PIERDE: llega incompleto al
	 * servidor y allí se descarta, así que la persona vería «Solicitud
	 * registrada» y su video no existiría en ningún sitio. Es el mismo cuidado
	 * que ya se tenía con las fotos a medio optimizar.
	 */
	let videosSubiendo = $state<number[]>([]);

	function marcarSubiendo(categoriaId: number, subiendo: boolean) {
		videosSubiendo = subiendo
			? [...videosSubiendo.filter((c) => c !== categoriaId), categoriaId]
			: videosSubiendo.filter((c) => c !== categoriaId);
	}

	let ubicando = $state(false);
	let avisoUbicacion = $state<string | null>(null);

	/**
	 * Identificador de este envío.
	 *
	 * Decía «estable» y no lo era: se generaba con `crypto.randomUUID()` en cada
	 * carga de la página. Como es la raíz de la clave con la que las fotos viven
	 * en IndexedDB, al volver la clave era otra — las fotos seguían en el
	 * aparato y no había forma de encontrarlas. Una familia que se salía sin
	 * querer lo perdía todo.
	 *
	 * Ahora se recupera del borrador, y solo se hace uno nuevo cuando no hay
	 * ninguno que continuar.
	 */
	let envioId = $state(borradorPre.nuevoEnvioId());

	/** Cuándo se guardó lo que acabamos de recuperar. Vacío si no había nada. */
	let recuperado = $state<string | null>(null);

	let datos = $state(datosVacios());

	/**
	 * Si el censo ya reconoció la cédula.
	 *
	 * Mientras sea `false` no se dibuja el formulario: la pre-inscripción es la
	 * continuación del proceso de quien ya fue censado en campo, y preguntarle
	 * nombre, dirección y fotos a alguien que no está en el RUFE es hacerle
	 * llenar cuatro pasos para nada.
	 *
	 * Quien decide de verdad es PHP, que vuelve a comprobarlo al recibir el
	 * envío. Esto es la cortesía de avisar antes.
	 */
	let habilitado = $state(false);

	/**
	 * Se entró sin poder verificar la cédula, por no haber señal.
	 *
	 * El formulario sigue sirviendo —quien lo abre desde su casa con la señal
	 * que le queda tiene que poder llenarlo— pero hay que decirlo: si esa cédula
	 * no está en el censo, el envío se rechazará al llegar.
	 */
	let sinVerificar = $state(false);

	/**
	 * Lo que el censo ya sabe de esta casa, si la persona lo trajo.
	 *
	 * Se guarda tal cual para poder decir, junto a cada campo, «esto lo trajimos
	 * del censo» y para marcar en pantalla lo que la persona está cambiando.
	 */
	let hogarCenso = $state<HogarCenso | null>(null);
	let personas = $state<PersonaHogar[]>([]);

	function entrarConCedula(documento: string, hogar: HogarCenso | null = null) {
		datos.documento = documento;

		if (hogar) precargarDesdeCenso(hogar);

		habilitado = true;
	}

	/**
	 * Vuelca en el formulario lo que el censo sabe de esta casa.
	 *
	 * Solo se rellena lo que está VACÍO. Si la persona ya venía escribiendo
	 * —volvió a la puerta a corregir su cédula, o los datos llegaron tarde
	 * porque la foto de la cédula se subió a mitad del paso 1— pisarle lo
	 * escrito sería el peor momento para hacerlo.
	 */
	function precargarDesdeCenso(hogar: HogarCenso) {
		hogarCenso = hogar;

		if (datos.telefono.trim() === '') datos.telefono = hogar.telefono;
		if (datos.direccion.trim() === '') datos.direccion = hogar.direccion;
		if (datos.zona === '') datos.zona = hogar.zona;
		if (datos.corregimiento.trim() === '') datos.corregimiento = hogar.corregimiento;
		if (datos.vereda.trim() === '') datos.vereda = hogar.vereda;

		// El nombre sale de la persona que está escribiendo, no del jefe de
		// hogar: quien hace el trámite puede ser el hijo mayor de edad.
		const yo = hogar.personas.find((p) => p.id === hogar.persona_id);

		if (yo && datos.nombre_completo.trim() === '') {
			datos.nombre_completo = `${yo.nombres} ${yo.apellidos}`.trim();
		}

		// Las personas solo se traen si la familia no ha tocado la lista: si ya
		// corrigió un apellido o agregó a alguien, reemplazarla borraría eso.
		if (personas.length === 0) personas = desdeCenso(hogar.personas);
	}

	/**
	 * Descartar lo recuperado y empezar limpio.
	 *
	 * Con confirmación, y no por gusto: aquí se borran fotos que la persona ya
	 * tomó. Es la única acción de este formulario que destruye trabajo suyo.
	 */
	async function empezarDeCero() {
		if (!confirm('Se borrará lo que llevaba, incluidas las fotos y los videos. ¿Seguro?')) return;

		borradorPre.borrar();
		await evidencias?.limpiar();

		envioId = borradorPre.nuevoEnvioId();
		datos = datosVacios();
		personas = [];
		hogarCenso = null;
		videosListos = [];
		errores = {};
		errorEnvio = '';
		indice = 0;
		recuperado = null;
		habilitado = false;
		pidiendoCenso = false;
	}

	/** Volver a la puerta: la cédula se cambia allí, no en el paso 1. */
	function cambiarCedula() {
		habilitado = false;
		datos.documento = '';
		hogarCenso = null;
		personas = [];
		errores = {};
		errorEnvio = '';
		indice = 0;
		pidiendoCenso = false;
	}

	let indice = $state(0);

	/**
	 * Guardar lo que lleva escrito, cada vez que cambia algo.
	 *
	 * Un `$effect` y no un botón: quien llena esto está de pie en el patio de su
	 * casa y no va a acordarse de pulsar «guardar». Lo que se guarda es ligero
	 * —texto, el paso, el token de la carga—; las fotos van a IndexedDB por su
	 * cuenta, en cuanto se toman.
	 *
	 * Se lee todo lo que se quiere vigilar de forma explícita: un `$effect` solo
	 * se vuelve a disparar por lo que lee, y con los objetos anidados hay que
	 * tocar los campos para que Svelte los siga.
	 */
	$effect(() => {
		// No se guarda antes de que la página termine de cargar: escribiría el
		// formulario vacío encima de lo que acabamos de recuperar.
		if (cargando || resultado !== null) return;

		void [
			datos.nombre_completo,
			datos.documento,
			datos.telefono,
			datos.correo,
			datos.direccion,
			datos.zona,
			datos.corregimiento,
			datos.vereda,
			datos.descripcion_dano,
			datos.senales.length,
			datos.latitud,
			datos.autoriza_datos,
			personas.length,
			videosListos.length,
			indice,
			evidencias?.carga
		];

		borradorPre.guardar({
			envioId,
			carga: evidencias?.carga ?? null,
			datos: $state.snapshot(datos),
			personas: $state.snapshot(personas),
			hogar: $state.snapshot(hogarCenso),
			indice,
			videosListos: $state.snapshot(videosListos)
		});
	});

	/**
	 * Los videos que se le piden a ESTA persona: uno por cada daño que marcó.
	 *
	 * Si no marcó ninguno, el paso de videos no existe. No es un descuido: no
	 * hay nada que grabar de una casa de la que no se señaló ningún daño, y una
	 * pantalla vacía con un botón de «Siguiente» solo alarga el formulario.
	 */
	const videosPedidos = $derived(
		videosQueSePiden(catalogos?.categorias_video ?? [], datos.senales)
	);

	const videosFaltantes = $derived(videosQueFaltan(videosPedidos, videosListos));

	/**
	 * Si este aparato sabe grabar video.
	 *
	 * Los videos son obligatorios, pero «obligatorio» no puede convertirse en
	 * «imposible»: un celular viejo sin MediaRecorder no graba por mucho que el
	 * formulario insista, y dejar a esa familia sin turno de inspección por el
	 * teléfono que le tocó sería el peor final de este formulario.
	 *
	 * Se mira una vez, no en cada dibujado: `formatoSoportado()` pregunta por
	 * `MediaRecorder`, que no existe mientras el componente se prepara en el
	 * servidor.
	 */
	let puedeGrabar = $state(true);

	/**
	 * Cuánta evidencia hay ahora mismo, para saber si se puede pasar de paso.
	 *
	 * Vive aquí y no dentro de cada tarjeta porque la regla la aplica la
	 * navegación —`siguiente()` y `enviar()`—, y la tarjeta puede ni siquiera
	 * estar en pantalla cuando se decide.
	 */
	const estadoEvidencia = $derived({
		cedulaFrente: fotosUtiles(evidencias?.archivosDe('PRE_CEDULA') ?? []),
		cedulaReverso: fotosUtiles(evidencias?.archivosDe('PRE_CEDULA_REVERSO') ?? []),
		fotosDano: fotosUtiles(evidencias?.archivosDe('PRE_DANO') ?? [])
	});

	/**
	 * Traer del censo lo que no se trajo en la puerta.
	 *
	 * Quien pulsó «Continuar sin traer mis datos» —porque no tenía señal, o
	 * porque no quiso pararse a fotografiar la cédula en ese momento— entró al
	 * formulario en blanco y ahí se quedaba: teniendo la Alcaldía su dirección y
	 * su hogar levantados en campo, le tocaba escribirlo todo a mano.
	 *
	 * Ahora, en cuanto la foto de la cédula llega al servidor —y ya es
	 * obligatoria para poder pasar de este paso—, se pide sola. Solo se rellena
	 * lo que esté VACÍO: lo que la persona ya escribió no se le pisa.
	 *
	 * El servidor sigue exigiendo esa foto para contestar, y eso no cambia: la
	 * respuesta lleva nombre, teléfono, dirección y quiénes viven en una casa
	 * damnificada. Que una pantalla pública reparta eso a quien acierte un
	 * número de cédula es lo que la foto impide, y es también lo que hace que
	 * recorrer el censo deje rastro. Lo que se corrige aquí es el hueco de en
	 * medio: la foto ya se toma igual, solo que un momento después.
	 */
	let pidiendoCenso = false;

	$effect(() => {
		if (!evidencias || !habilitado || hogarCenso !== null || pidiendoCenso) return;

		const carga = evidencias.carga;
		const documento = datos.documento;
		const hayFoto = evidencias.archivosDe('PRE_CEDULA').some((a) => a.estado === 'listo');

		if (!carga || !hayFoto || documento.trim() === '') return;

		pidiendoCenso = true;

		void (async () => {
			try {
				const { hogar } = await preinscripcionApi.datosCenso(documento, carga);
				if (hogar) precargarDesdeCenso(hogar);
			} catch {
				// Sin señal, o el censo no contesta. No se avisa: la persona no
				// pidió esto, y lo único que pierde es escribir sus datos a mano.
				// Se deja el candado puesto para no repetir la petición sola.
			}
		})();
	});

	const hayVideos = $derived(videosPedidos.length > 0);
	const pasos = $derived(pasosVigentes(hayVideos));
	const paso = $derived(pasos[Math.min(indice, pasos.length - 1)]);
	const esPrimero = $derived(indice === 0);
	const esUltimo = $derived(indice === pasos.length - 1);

	onMount(() => {
		void (async () => {
			puedeGrabar = formatoSoportado() !== null;

			try {
				catalogos = await preinscripcionApi.catalogos();

				// ANTES de crear el gestor: la clave con la que las fotos viven en
				// IndexedDB se deriva del envío, así que el gestor tiene que nacer
				// ya con el envío recuperado o buscará donde no hay nada.
				const previo = borradorPre.leer();

				if (previo) {
					envioId = previo.envioId;
					datos = previo.datos;
					personas = previo.personas;
					hogarCenso = previo.hogar;
					videosListos = previo.videosListos;
					indice = previo.indice;

					// La puerta ya se pasó en la visita anterior. Volver a pedir la
					// cédula para enseñarle lo que ya había escrito sería castigar
					// a quien se salió sin querer. El servidor la comprueba otra vez
					// al recibir el envío, que es donde de verdad se decide.
					if (previo.datos.documento.trim() !== '') habilitado = true;
				}

				evidencias = new GestorEvidencias(
					{
						PRE_CEDULA: catalogos.limites.fotos_cedula,
						// Sin esta línea el gestor da cupo cero a la cara de atrás y
						// rechaza la foto en silencio: la persona la toma y no aparece.
						PRE_CEDULA_REVERSO: catalogos.limites.fotos_cedula_reverso,
						PRE_DANO: catalogos.limites.fotos_dano
					},
					// La clave del borrador es este envío: las fotos viven atadas a
					// él y no se mezclan con las de otra solicitud del mismo aparato.
					`preinscripcion-${envioId}`,
					RUTAS_PUBLICAS_CARGA
				);
				detenerEvidencias = evidencias.iniciar();

				// El token de la carga anterior va ANTES de restaurar. `restaurar()`
				// termina llamando a `subirPendientes()`, y sin la carga puesta esas
				// fotos se subirían a una carga nueva — dejando en la vieja los
				// videos, que sí están solo en el servidor, hasta que la purga se
				// los llevara.
				if (previo) {
					recuperado = previo.actualizado_en;
					evidencias.carga = previo.carga;
				}

				// Las fotos de la visita anterior vuelven a la lista y se vuelven a
				// subir. Están en el aparato, así que sobreviven aunque la carga del
				// servidor ya hubiera caducado.
				await evidencias.restaurar();

				// Los videos van en la MISMA carga que las fotos, y la carga se abre
				// sola al subir la primera foto. Si alguien solo graba videos, esa
				// carga no existiría y se perderían: se abre aquí.
				if (evidencias.carga === null && (catalogos.categorias_video ?? []).length > 0) {
					try {
						evidencias.carga = (await preinscripcionApi.abrirCarga()).carga;
					} catch {
						// Sin señal no hay carga y no habrá videos. La solicitud sigue
						// pudiendo enviarse, que es lo que importa.
					}
				}
			} catch {
				errorCarga = 'No se pudo cargar el formulario. Revise su conexión e intente de nuevo.';
			} finally {
				cargando = false;
			}
		})();

		return () => detenerEvidencias?.();
	});

	// ── Navegación ──────────────────────────────────────────────────────────

	function siguiente() {
		const fallos = validarPaso(paso.id, datos);
		errores = fallos;
		if (Object.keys(fallos).length > 0) {
			subirAlInicio();

			return;
		}

		// Avanzar con una foto a medio optimizar o un video a medio subir dejaría
		// a la persona creyendo que ya los mandó. La regla vive en `pasos.ts`.
		const bloqueo = bloqueoDeAvance({
			// Solo se exigen los videos en el paso donde se graban y al enviar.
			// Antes de llegar ahí, la persona todavía no ha tenido ocasión.
			videosFaltantes: indice >= pasos.findIndex((x) => x.id === 'video') ? videosFaltantes.length : 0,
			puedeGrabar,
			optimizandoFotos: evidencias?.optimizando ?? false,
			videosSubiendo: videosSubiendo.length
		});

		if (bloqueo) {
			errorEnvio = bloqueo;

			return;
		}

		// La cédula y las fotos del daño. Va después del bloqueo de subidas —«no
		// salga todavía, se está subiendo»— porque ese es un problema de espera y
		// este de algo que la persona todavía tiene que hacer.
		const falta = faltaEvidencia(paso.id, estadoEvidencia);

		if (falta) {
			errorEnvio = falta;
			subirAlInicio();

			return;
		}

		errorEnvio = '';
		indice = Math.min(indice + 1, pasos.length - 1);
		subirAlInicio();
	}

	function anterior() {
		errores = {};
		errorEnvio = '';
		indice = Math.max(indice - 1, 0);
		subirAlInicio();
	}

	function subirAlInicio() {
		if (browser) window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	// ── Ubicación ───────────────────────────────────────────────────────────

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

	// ── Envío ───────────────────────────────────────────────────────────────

	/** Los campos que se corrigen en el paso 1, para saber a dónde devolver. */
	const CAMPOS_PASO_1 = [
		'nombre_completo',
		'documento',
		'telefono',
		'correo',
		'direccion',
		'zona',
		'corregimiento'
	];

	async function enviar() {
		if (!catalogos || enviando) return;

		const fallos = validarPaso('envio', datos);
		errores = fallos;
		if (Object.keys(fallos).length > 0) return;

		// La última barrera, y la que de verdad importa: el paso de video queda
		// atrás y nada impide llegar hasta aquí con una subida todavía en curso.
		const bloqueo = bloqueoDeAvance({
			// Solo se exigen los videos en el paso donde se graban y al enviar.
			// Antes de llegar ahí, la persona todavía no ha tenido ocasión.
			videosFaltantes: indice >= pasos.findIndex((x) => x.id === 'video') ? videosFaltantes.length : 0,
			puedeGrabar,
			optimizandoFotos: evidencias?.optimizando ?? false,
			videosSubiendo: videosSubiendo.length
		});

		if (bloqueo) {
			errorEnvio = bloqueo;
			subirAlInicio();

			return;
		}

		// Otra vez, y no por duplicar: desde este resumen se vuelve a los pasos
		// anteriores a corregir, y de ahí se puede quitar una foto. Lo que se
		// comprobó al pasar de paso puede haber dejado de ser cierto.
		const falta = faltaEvidencia('envio', estadoEvidencia);

		if (falta) {
			errorEnvio = falta;
			subirAlInicio();

			return;
		}

		enviando = true;
		errorEnvio = '';

		try {
			const r = await preinscripcionApi.enviar({
				...paraEnviar(datos),
				envio_id: envioId,
				aviso_version: catalogos.aviso_version,
				...(personas.length > 0 ? { personas: personasParaEnviar(personas) } : {}),
				// El servidor adopta las fotos de esta carga al recibir la
				// solicitud; sin el token quedarían huérfanas hasta caducar.
				...(evidencias?.carga ? { carga: evidencias.carga } : {})
			});

			resultado = {
				radicado: r.radicado,
				duplicada: r.duplicada,
				archivosAgregados: r.archivos_agregados
			};

			// Entró. Se borra todo: lo guardado lleva cédula, nombres, dirección
			// y quiénes viven en la casa, y un teléfono se presta.
			borradorPre.borrar();
			void evidencias?.limpiar();

			subirAlInicio();
		} catch (e) {
			if (e instanceof ApiError) {
				errorEnvio = e.message;
				errores = e.errors;

				// Un error en un campo del paso 1 no se puede corregir desde el
				// paso 4. Se devuelve a la persona a donde está el campo, en vez
				// de dejarla mirando un mensaje sobre algo que no tiene delante.
				if (Object.keys(e.errors).some((c) => CAMPOS_PASO_1.includes(c))) {
					indice = 0;
				}
			} else {
				errorEnvio = 'No se pudo enviar su solicitud. Intente de nuevo en unos minutos.';
			}

			subirAlInicio();
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
					{#if resultado.archivosAgregados}
						<!-- Decirlo importa: quien vuelve a inscribirse suele hacerlo justamente
						     porque esta vez sí pudo tomar las fotos o grabar el video, y si solo
						     lee «ya estaba registrada» se queda creyendo que no sirvió de nada. -->
						<strong>
							{resultado.archivosAgregados === 1
								? 'El archivo que acaba de enviar se agregó a su solicitud.'
								: `Los ${resultado.archivosAgregados} archivos que acaba de enviar se agregaron a su solicitud.`}
						</strong>
					{/if}
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
	{:else if !habilitado}
		<PuertaCedula
			evidencias={evidencias}
			onEntrar={entrarConCedula}
			entroSinVerificar={(v) => (sinVerificar = v)}
			{catalogos}
		/>
	{:else if catalogos}
		<IndicadorProgreso indice={indice + 1} total={pasos.length} titulo={paso.titulo} />

		<p class="ayuda-paso">{paso.ayuda}</p>

		{#if recuperado}
			<!--
				Lo primero que ve al volver. Sin este aviso, quien se salió sin
				querer no sabe si lo que ve es lo suyo de antes o un formulario que
				se llenó solo, y vuelve a tomar las fotos por si acaso.
			-->
			<p class="aviso aviso--ok" role="status">
				<Check size={15} aria-hidden="true" />
				Recuperamos lo que llevaba {borradorPre.cuandoFue(recuperado)}, con sus fotos.
				<button type="button" class="aviso__accion" onclick={empezarDeCero}>
					Empezar de cero
				</button>
			</p>
		{/if}

		{#if sinVerificar}
			<!--
				Se entró sin red. No se calla: si esa cédula no está en el censo, el
				envío se rechazará al llegar, y quien llenó cuatro pasos merece
				saberlo antes y no después.
			-->
			<p class="aviso aviso--alerta" role="status">
				<TriangleAlert size={15} aria-hidden="true" />
				No pudimos comprobar su cédula porque no había conexión. Puede llenar el formulario; al
				enviarlo se comprobará, y si no está en el censo se le indicará a dónde llamar.
			</p>
		{/if}

		{#if errorEnvio}
			<p class="aviso aviso--error" role="alert">
				<TriangleAlert size={15} aria-hidden="true" />
				{errorEnvio}
			</p>
		{/if}

		<!-- ── Paso 1: sus datos ───────────────────────────────────────── -->
		{#if paso.id === 'datos'}
			<section class="tarjeta">
				<h2 class="tarjeta__titulo">Quién es</h2>

				<label class="campo">
					<span class="campo__etiqueta">Nombre y apellidos *</span>
					<input class="campo__control" bind:value={datos.nombre_completo} autocomplete="name" />
					{#if errores.nombre_completo}
						<span class="campo__error">{errores.nombre_completo}</span>
					{/if}
				</label>

				<!--
					La cédula ya no se escribe aquí: se verificó contra el censo en la
					primera pantalla y es la que abrió el formulario. Editable, un
					descuido la cambiaría por una que el servidor rechazaría al final,
					después de las fotos y los videos.
				-->
				<div class="campo">
					<span class="campo__etiqueta">Cédula *</span>
					<p class="cedula">
						<span class="cedula__numero">{datos.documento}</span>
						<button type="button" class="volver" onclick={cambiarCedula}>Cambiar</button>
					</p>
					<span class="campo__ayuda">Verificada en el censo de afectados.</span>
					{#if errores.documento}<span class="campo__error">{errores.documento}</span>{/if}
				</div>

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
					<input class="campo__control" type="email" bind:value={datos.correo} autocomplete="email" />
					<span class="campo__ayuda">
						Opcional. Déjelo en blanco si no tiene o no lo recuerda: no hace falta para su
						solicitud.
					</span>
					{#if errores.correo}<span class="campo__error">{errores.correo}</span>{/if}
				</label>
			</section>

			<section class="tarjeta">
				<h2 class="tarjeta__titulo">Dónde queda la vivienda</h2>

				<!--
					La zona se PREGUNTA, no se deduce del corregimiento. Antes se
					deducía y la deducción era falsa: quien vive en el campo y no sabe
					a qué corregimiento pertenece su vereda entraba como urbano, y la
					visita salía a buscarlo al pueblo.
				-->
				<fieldset class="campo grupo" role="radiogroup" aria-required="true">
					<legend class="campo__etiqueta">¿La vivienda está en zona urbana o rural? *</legend>

					<div class="opciones opciones--dos">
						<label class="opcion" class:opcion--activa={datos.zona === 'URBANA'}>
							<input
								type="radio"
								name="zona"
								value="URBANA"
								checked={datos.zona === 'URBANA'}
								onchange={() => (datos.zona = 'URBANA')}
							/>
							<span class="opcion__texto">
								Urbana
								<span class="opcion__nota">En la cabecera del municipio</span>
							</span>
						</label>

						<label class="opcion" class:opcion--activa={datos.zona === 'RURAL'}>
							<input
								type="radio"
								name="zona"
								value="RURAL"
								checked={datos.zona === 'RURAL'}
								onchange={() => (datos.zona = 'RURAL')}
							/>
							<span class="opcion__texto">
								Rural
								<span class="opcion__nota">En un corregimiento o vereda</span>
							</span>
						</label>
					</div>

					{#if errores.zona}<span class="campo__error">{errores.zona}</span>{/if}
				</fieldset>

				<label class="campo">
					<span class="campo__etiqueta">Dirección o cómo llegar *</span>
					<textarea
						class="campo__control"
						rows="2"
						maxlength="200"
						bind:value={datos.direccion}
						placeholder="Carrera 11 # 8-26 — o bien: la casa azul pasando el puente, al lado de la tienda"
					></textarea>
					<span class="campo__ayuda">
						Escríbala como se la explicaría a alguien que va a buscarla. Si no tiene nomenclatura,
						sirven las referencias.
					</span>
					{#if errores.direccion}<span class="campo__error">{errores.direccion}</span>{/if}
				</label>

				{#if datos.zona === 'RURAL'}
					<label class="campo">
						<span class="campo__etiqueta">Corregimiento</span>
						<select class="campo__control" bind:value={datos.corregimiento}>
							<option value="">No lo sé</option>
							{#each catalogos.corregimientos as c (c)}
								<option value={c}>{c}</option>
							{/each}
						</select>
						<span class="campo__ayuda">Si no sabe cuál es, déjelo así y siga.</span>
						{#if errores.corregimiento}
							<span class="campo__error">{errores.corregimiento}</span>
						{/if}
					</label>
				{/if}

				<label class="campo">
					<span class="campo__etiqueta">{datos.zona === 'RURAL' ? 'Vereda' : 'Barrio'}</span>
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

			<!--
				El hogar, solo cuando el censo lo trajo.

				Quien llegó sin ficha —o sin señal para traerla— no ve esta sección:
				pedirle a mano la composición de su hogar sería justo lo que este
				formulario decidió no hacer. Ver `Preinscripcion\Validador`.
			-->
			{#if hogarCenso && catalogos}
				<ListaHogar bind:personas censo={hogarCenso.personas} {catalogos} />
			{/if}

			{#if evidencias}
				<!-- La cédula va aquí y no al final: es un dato de identidad, y este
				     es el momento en que la persona la tiene a mano. -->
				<section class="tarjeta">
					<h3 class="cedula__titulo">Foto de su cédula</h3>
					<p class="cedula__ayuda">
						<strong>Las dos caras</strong>, sobre una superficie plana y sin reflejos. Es lo único
						que ata esta solicitud a usted, así que sin ellas no podemos continuar. Las fotos se
						reducen en su celular antes de enviarse.
						{#if hogarCenso === null}
							Al tomarlas traeremos los datos que ya tenemos de su vivienda, para que usted los
							revise.
						{/if}
					</p>
					<CedulaDosCaras gestor={evidencias} />
				</section>
			{/if}

		<!-- ── Paso 2: cómo quedó la vivienda ──────────────────────────── -->
		{:else if paso.id === 'vivienda'}
			<section class="tarjeta">
				<SelectorSenales
					senales={catalogos.senales}
					bind:marcadas={datos.senales}
					error={errores.senales ?? ''}
				/>
			</section>

			<section class="tarjeta">
				<h2 class="tarjeta__titulo">¿Quiere contarnos algo más?</h2>

				<label class="campo">
					<span class="campo__etiqueta">Con sus palabras</span>
					<textarea
						class="campo__control"
						rows="4"
						maxlength="1000"
						bind:value={datos.descripcion_dano}
						placeholder="Ej.: se agrietaron los muros de la sala y se cayó parte del techo de la cocina."
					></textarea>
					<span class="campo__ayuda">
						Opcional. No hace falta que sea técnico: es para entender mejor su caso.
					</span>
					{#if errores.descripcion_dano}
						<span class="campo__error">{errores.descripcion_dano}</span>
					{/if}
				</label>
			</section>

			{#if evidencias}
				<section class="tarjeta">
					<FotosDano gestor={evidencias} />
				</section>
			{/if}

		<!-- ── Paso 3: los videos ──────────────────────────────────────── -->
		{:else if paso.id === 'video'}
			<section class="tarjeta">
				<p class="tarjeta__nota">
					Le pedimos <strong>un video por cada daño que marcó</strong>, no uno largo de toda la
					casa: así se sube por partes y, si se cae la señal, solo se repite el que iba a medias.
					Cada uno dura <strong>máximo dos minutos</strong> y se corta solo al llegar. Puede
					repetirlo antes de enviarlo.
				</p>

				{#if !puedeGrabar}
					<p class="aviso aviso--alerta" role="alert">
						<TriangleAlert size={15} aria-hidden="true" />
						Este teléfono no permite grabar video desde el navegador. Continúe sin los videos: no
						perderá su turno por eso, y quien revise su caso lo verá anotado.
					</p>
				{:else if videosFaltantes.length > 0}
					<p class="aviso aviso--info">
						Faltan {videosFaltantes.length} de {videosPedidos.length}.
					</p>
				{/if}

				{#each videosPedidos as c (c.id)}
					<GrabadorVideo
						categoria={c}
						carga={evidencias?.carga ?? null}
						alSubir={(id) => (videosListos = [...videosListos, id])}
						alSubiendo={marcarSubiendo}
					/>
				{/each}
			</section>

		<!-- ── Paso 4: autorización y envío ────────────────────────────── -->
		{:else if paso.id === 'envio'}
			<section class="tarjeta">
				<h2 class="tarjeta__titulo">Lo que va a enviar</h2>

				<dl class="resumen">
					<div><dt>Nombre</dt><dd>{datos.nombre_completo || '—'}</dd></div>
					<div><dt>Cédula</dt><dd>{datos.documento || '—'}</dd></div>
					<div><dt>Teléfono</dt><dd>{datos.telefono || '—'}</dd></div>
					<div>
						<dt>Vivienda</dt>
						<dd>
							{datos.direccion || '—'}
							{#if datos.vereda}· {datos.vereda}{/if}
							{#if datos.zona === 'RURAL' && datos.corregimiento}· {datos.corregimiento}{/if}
							{#if datos.zona}({datos.zona === 'RURAL' ? 'zona rural' : 'zona urbana'}){/if}
						</dd>
					</div>
					<div>
						<dt>Daños marcados</dt>
						<dd>
							{#if datos.senales.length === 0}
								Ninguno
							{:else}
								{catalogos.senales
									.filter((s) => datos.senales.includes(s.codigo))
									.map((s) => s.etiqueta)
									.join(', ')}
							{/if}
						</dd>
					</div>
				</dl>

				<button type="button" class="volver" onclick={() => (indice = 0)}>
					Corregir mis datos
				</button>
			</section>

			<section class="tarjeta">
				<h2 class="tarjeta__titulo">Autorización de datos</h2>
				<AutorizacionDatos
					bind:aceptado={datos.autoriza_datos}
					error={errores.autoriza_datos ?? ''}
				/>
			</section>
		{/if}

		<!-- Trampa antirrobot. Oculta y fuera del orden de tabulación. -->
		<div class="trampa" aria-hidden="true">
			<label for="sitio_web">No llene este campo</label>
			<input
				id="sitio_web"
				name="sitio_web"
				tabindex="-1"
				autocomplete="off"
				bind:value={datos.sitio_web}
			/>
		</div>

		<nav class="navegacion" aria-label="Navegación del formulario">
			{#if !esPrimero}
				<button type="button" class="boton boton--suave" onclick={anterior} disabled={enviando}>
					<ArrowLeft size={16} aria-hidden="true" />
					Atrás
				</button>
			{/if}

			{#if esUltimo}
				<button type="button" class="boton boton--enviar" onclick={enviar} disabled={enviando}>
					{#if enviando}
						<LoaderCircle size={16} class="girando" aria-hidden="true" />
						Enviando…
					{:else}
						<Send size={16} aria-hidden="true" />
						Enviar mi solicitud
					{/if}
				</button>
			{:else}
				<button type="button" class="boton" onclick={siguiente}>
					Siguiente
					<ArrowRight size={16} aria-hidden="true" />
				</button>
			{/if}
		</nav>
	{/if}
	<div class="instalar">
		<!--
			Instalar el formulario en el teléfono.
			El botón existía solo dentro del menú lateral del sistema, y este
			formulario no tiene menú: el ciudadano —el único que lo usa— nunca vio
			la opción. Instalado, el navegador deja de tratarlo como una pestaña
			más que puede desalojar, que es justo lo que se lleva por delante lo
			guardado para trabajar sin señal.
		-->
		<BotonInstalar />
	</div>
</div>

<style>
	.aviso--ok {
		background: var(--color-success-bg);
		color: var(--color-success);
	}

	.aviso__accion {
		border: none;
		background: none;
		color: inherit;
		font-size: 0.82rem;
		font-weight: 600;
		text-decoration: underline;
		cursor: pointer;
		padding: 0;
		margin-left: 0.35rem;
	}

	.cedula__titulo {
		margin: 0 0 0.3rem;
		font-size: 1.05rem;
		font-weight: 700;
	}

	.cedula__ayuda {
		margin: 0 0 0.8rem;
		font-size: 0.88rem;
		line-height: 1.5;
		color: var(--color-muted);
	}

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

	.ayuda-paso {
		margin: 0.6rem 0 1rem;
		font-size: 0.88rem;
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

	.grupo {
		border: 0;
		padding: 0;
		margin: 0 0 0.9rem;
		min-width: 0;
	}

	.grupo legend {
		padding: 0;
	}

	/* Dos columnas solo cuando hay sitio, como en el censo. */
	@media (min-width: 560px) {
		.opciones--dos {
			grid-template-columns: 1fr 1fr;
		}
	}

	.resumen {
		display: grid;
		gap: 0.55rem;
		margin: 0;
		font-size: 0.87rem;
	}

	.resumen div {
		display: grid;
		grid-template-columns: 8.5rem 1fr;
		gap: 0.5rem;
	}

	.resumen dt {
		color: var(--color-muted);
	}

	.resumen dd {
		margin: 0;
		line-height: 1.4;
	}

	.volver {
		margin-top: 0.9rem;
		padding: 0;
		border: 0;
		background: none;
		font: inherit;
		font-size: 0.83rem;
		color: var(--color-primary);
		text-decoration: underline;
		text-underline-offset: 3px;
		cursor: pointer;
	}

	.navegacion {
		display: flex;
		gap: 0.6rem;
		margin-top: 1.6rem;
		padding-top: 1.2rem;
		border-top: 1px solid var(--color-border);
	}

	.navegacion .boton {
		flex: 1;
		justify-content: center;
		min-height: 48px;
		font-size: 0.95rem;
	}

	.boton--enviar {
		background: var(--color-success);
	}

	.boton--enviar:hover:not(:disabled) {
		background: color-mix(in srgb, var(--color-success) 82%, black);
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
	.cedula {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.8rem;
		margin: 0;
		padding: 0.55rem 0.75rem;
		border: 1px solid var(--color-border);
		border-radius: 10px;
		background: var(--color-surface-alt);
	}

	.cedula__numero {
		font-size: 1.05rem;
		font-variant-numeric: tabular-nums;
		letter-spacing: 0.02em;
	}

	.trampa {
		position: absolute;
		left: -9999px;
		width: 1px;
		height: 1px;
		overflow: hidden;
	}
	.instalar {
		margin-top: 1.4rem;
		padding-top: 1rem;
		border-top: 1px solid var(--color-border);
	}

</style>
