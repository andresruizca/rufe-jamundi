// Los pasos de la pre-inscripción ciudadana y qué se valida en cada uno.
//
// Antes esto era una sola página larga, y la razón que se escribió entonces era
// que «quien llena esto lo hace una vez en su vida y necesita ver de un vistazo
// qué le van a preguntar». Resultó al revés: en la pantalla de un celular esa
// página de un vistazo es un rollo de siete secciones donde no se sabe cuánto
// falta, y donde un error de validación al final obliga a subir a buscarlo. El
// censo lleva meses en producción con pasos y ese es el patrón que la gente de
// aquí ya reconoce.
//
// Como en el RUFE (`$lib/rufe-form/esquema.ts`) los pasos están como DATOS en un
// solo archivo, no repartidos por el componente: así una prueba puede comprobar
// que ningún campo se quedó sin paso y que cada condición está escrita una vez.
//
// Espejo de `backend/src/Preinscripcion/Validador.php`, no sustituto: esto
// existe para que el error salga junto al campo sin esperar una petición. Quien
// decide es PHP — la ruta es pública y cualquiera puede saltarse el navegador.

export type IdPasoPre = 'datos' | 'vivienda' | 'video' | 'envio';

export type PasoPre = {
	id: IdPasoPre;
	titulo: string;
	ayuda: string;
};

/**
 * Los cuatro pasos, en orden.
 *
 * El video es el único que puede no existir: mientras nadie haya definido
 * categorías, no hay nada que grabar. Ver `pasosVigentes`.
 */
export const PASOS_PRE: PasoPre[] = [
	{
		id: 'datos',
		titulo: 'Sus datos',
		ayuda: 'Quién es y dónde queda la vivienda afectada.'
	},
	{
		id: 'vivienda',
		titulo: 'Cómo quedó la vivienda',
		ayuda: 'Marque todo lo que reconozca. No hace falta saber de construcción.'
	},
	{
		id: 'video',
		titulo: 'Videos de la vivienda',
		ayuda: 'Un video por cada daño que marcó. Máximo dos minutos cada uno.'
	},
	{
		id: 'envio',
		titulo: 'Autorización y envío',
		ayuda: 'Un último paso y queda registrada su solicitud.'
	}
];

/**
 * Los pasos que se recorren de verdad.
 *
 * Sin categorías de video configuradas, el paso 3 sería una pantalla vacía con
 * un botón de «Siguiente». Y hoy en producción no hay ninguna: el catálogo lo
 * define quien tiene el criterio estructural para decidir qué debe grabarse, y
 * hasta entonces el módulo está inerte a propósito.
 */
export function pasosVigentes(hayCategoriasVideo: boolean): PasoPre[] {
	return PASOS_PRE.filter((p) => p.id !== 'video' || hayCategoriasVideo);
}

// ── Validación ───────────────────────────────────────────────────────────────

export type Errores = Record<string, string>;

/** Lo que el formulario recoge. Un espejo de lo que acepta el validador de PHP. */
export type DatosPre = {
	nombre_completo: string;
	documento: string;
	telefono: string;
	correo: string;
	direccion: string;
	zona: 'URBANA' | 'RURAL' | '';
	corregimiento: string;
	vereda: string;
	senales: string[];
	descripcion_dano: string;
	autoriza_datos: boolean;
	latitud: number | null;
	longitud: number | null;
	precision_m: number | null;
	sitio_web: string;
};

export function datosVacios(): DatosPre {
	return {
		nombre_completo: '',
		documento: '',
		telefono: '',
		correo: '',
		direccion: '',
		zona: '',
		corregimiento: '',
		vereda: '',
		senales: [],
		descripcion_dano: '',
		autoriza_datos: false,
		latitud: null,
		longitud: null,
		precision_m: null,
		// Trampa para robots: oculta por CSS, una persona nunca la ve.
		sitio_web: ''
	};
}

const SOLO_DIGITOS = /\D+/g;

// Deliberadamente laxo. Un correo se valida de verdad mandándole un mensaje, y
// aquí es opcional: la única función de esta comprobación es cazar la errata
// obvia, no decidir quién puede pedir una inspección.
const RE_CORREO = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function validarPaso(paso: IdPasoPre, d: DatosPre): Errores {
	const e: Errores = {};

	if (paso === 'datos') {
		const nombre = d.nombre_completo.trim();
		if (nombre.length < 5 || nombre.length > 200) {
			e.nombre_completo = 'Escriba su nombre y sus apellidos.';
		}

		// La gente escribe la cédula como la lee en su documento, con puntos.
		// Se cuentan los dígitos, no los caracteres.
		const documento = d.documento.replace(SOLO_DIGITOS, '');
		if (documento.length < 5 || documento.length > 15) {
			e.documento = 'Escriba su número de cédula, sin puntos ni espacios.';
		}

		const telefono = d.telefono.replace(SOLO_DIGITOS, '');
		if (telefono.length < 7 || telefono.length > 15) {
			e.telefono = 'Escriba un teléfono donde podamos llamarle.';
		}

		const correo = d.correo.trim();
		if (correo !== '' && (!RE_CORREO.test(correo) || correo.length > 150)) {
			e.correo = 'Ese correo no parece válido. Puede dejarlo en blanco.';
		}

		const direccion = d.direccion.trim();
		if (direccion.length < 5 || direccion.length > 200) {
			e.direccion = 'Escriba dónde queda la vivienda, como se lo explicaría a alguien que va a buscarla.';
		}

		if (d.zona !== 'URBANA' && d.zona !== 'RURAL') {
			e.zona = 'Indique si la vivienda está en zona urbana o rural.';
		}
	}

	// 'vivienda' y 'video' no validan CAMPOS, y eso es una decisión, no un
	// olvido: ninguna señal es obligatoria, porque quien tiene la casa partida
	// por la mitad puede no reconocerse en ninguno de los ocho dibujos.
	//
	// Lo que sí exigen es evidencia, y eso se comprueba aparte —en
	// `faltaEvidencia`— porque depende de archivos y no de lo escrito.

	if (paso === 'envio') {
		if (!d.autoriza_datos) {
			e.autoriza_datos = 'Debe autorizar el tratamiento de sus datos para continuar.';
		}

		if (d.descripcion_dano.length > 1000) {
			e.descripcion_dano = 'Resuma en menos de 1000 caracteres.';
		}
	}

	return e;
}

// ── La evidencia que no se puede saltar ──────────────────────────────────────
//
// Antes las fotos eran todas opcionales. El argumento escrito entonces era que
// nadie debía perder su turno de inspección por un celular viejo o una señal
// mala, y sigue valiendo para los videos —grabar exige `MediaRecorder`, que un
// aparato puede sencillamente no tener—. Pero no vale igual para las fotos:
// tomar una foto lo hace cualquier teléfono, y si hace falta se elige de la
// galería.
//
// Y sin ellas la solicitud no sirve para lo que existe. La cédula es lo único
// que ata la solicitud a una persona; las fotos del daño son lo que permite
// preparar la visita y priorizar entre cientos de familias. Una solicitud sin
// eso obliga a llamar para pedirlas, que es justo lo que el call center está
// haciendo a mano.

/**
 * Cuántas fotos del daño se exigen como mínimo.
 *
 * Cinco, que es lo que pidió la Alcaldía y lo que cubre lo mínimo de una casa:
 * la fachada, dos muros, el techo y el piso. No es un tope —el cupo son diez y
 * la pantalla insiste en que entre más, mejor—: es el suelo por debajo del cual
 * la visita se prepara a ciegas.
 */
export const MIN_FOTOS_DANO = 5;

/**
 * Cuántas de estas fotos cuentan.
 *
 * Cuenta la que está en el teléfono aunque todavía no haya llegado al servidor:
 * quien llena esto desde una vereda sin cobertura ya tomó su foto y la subida
 * ocurrirá sola cuando haya señal. Exigir que estuviera «guardada» sería
 * convertir la falta de cobertura en un muro, que es lo contrario de lo que
 * este formulario hace en todas partes.
 *
 * Lo que no cuenta es la que falló: esa no existe en ningún sitio.
 */
export function fotosUtiles(archivos: { estado: string }[]): number {
	return archivos.filter((a) => a.estado !== 'error').length;
}

export type EstadoEvidencia = {
	cedulaFrente: number;
	cedulaReverso: number;
	fotosDano: number;
};

/** El orden real de los pasos, para saber qué queda ya comprobado. */
const ORDEN: IdPasoPre[] = ['datos', 'vivienda', 'video', 'envio'];

/**
 * Qué evidencia falta para salir de este paso. Cadena vacía si no falta nada.
 *
 * Comprueba lo de este paso Y lo de los anteriores, por dos motivos: el paso de
 * video no siempre existe —depende de que la persona haya marcado daños con
 * categoría—, y al enviar hay que volver a mirarlo todo, porque desde el
 * resumen se puede volver atrás y quitar una foto.
 */
export function faltaEvidencia(paso: IdPasoPre, e: EstadoEvidencia): string {
	const hasta = ORDEN.indexOf(paso);

	if (hasta >= ORDEN.indexOf('datos')) {
		if (e.cedulaFrente === 0 && e.cedulaReverso === 0) {
			return 'Falta la foto de su cédula. Se necesitan las dos caras para confirmar que la solicitud es suya.';
		}

		if (e.cedulaFrente === 0) {
			return 'Falta la foto de la cara de adelante de su cédula, la que lleva su foto y sus nombres.';
		}

		if (e.cedulaReverso === 0) {
			return 'Falta la foto de la cara de atrás de su cédula, la del código de barras.';
		}
	}

	if (hasta >= ORDEN.indexOf('vivienda') && e.fotosDano < MIN_FOTOS_DANO) {
		const faltan = MIN_FOTOS_DANO - e.fotosDano;

		return e.fotosDano === 0
			? `Faltan las fotos del daño. Se necesitan ${MIN_FOTOS_DANO} como mínimo: la fachada, los muros afectados, el techo y el piso.`
			: faltan === 1
				? `Falta una foto del daño. Se necesitan ${MIN_FOTOS_DANO} como mínimo, y entre más tome, mejor se prepara la visita.`
				: `Faltan ${faltan} fotos del daño. Se necesitan ${MIN_FOTOS_DANO} como mínimo, y entre más tome, mejor se prepara la visita.`;
	}

	return '';
}

/**
 * En qué paso se resuelve lo que falta. `null` si no falta nada.
 *
 * Existe para no dejar a nadie en un callejón. El día que estas fotos pasaron a
 * ser obligatorias, quien iba por el paso 2 con el formulario a medias se
 * encontró un «Falta la foto de su cédula» mirando la pantalla de los daños,
 * sin nada en ella que lo resolviera. Con esto, el aviso viene acompañado del
 * salto al paso donde sí se puede hacer algo.
 */
export function dondeFalta(e: EstadoEvidencia): IdPasoPre | null {
	if (e.cedulaFrente === 0 || e.cedulaReverso === 0) return 'datos';
	if (e.fotosDano < MIN_FOTOS_DANO) return 'vivienda';

	return null;
}

// ── Los videos que se le piden a cada persona ────────────────────────────────

/** Una categoría de video como la manda el servidor. */
export type CategoriaVideo = {
	id: number;
	nombre: string;
	instruccion: string | null;
	/** El daño al que responde. Sin él, la categoría no se le pide a nadie. */
	senal: string | null;
	obligatoria: boolean;
	segundos_min: number;
	segundos_max: number;
};

/**
 * Qué videos se le piden a quien marcó estos daños.
 *
 * Antes se pedían todos a todo el mundo, y salía mal por dos lados a la vez:
 * un video largo de la casa entera que la conexión de una vereda no sube, y
 * alguien grabando un baño intacto porque el formulario se lo pidió.
 *
 * Ahora cada video responde a un daño concreto. Quien marcó dos graba dos.
 *
 * Vive aquí y no en el componente para poder probarlo: de esta función depende
 * que a alguien no se le pida un video que no tiene cómo grabar, y que no se le
 * deje de pedir el del daño que importaba.
 */
export function videosQueSePiden(
	categorias: CategoriaVideo[],
	senales: string[]
): CategoriaVideo[] {
	return categorias.filter((c) => c.senal !== null && senales.includes(c.senal));
}

/**
 * Cuáles de esos videos siguen sin grabarse.
 *
 * @param  listos  los identificadores de categoría que ya subieron su video
 */
export function videosQueFaltan(pedidos: CategoriaVideo[], listos: number[]): CategoriaVideo[] {
	return pedidos.filter((c) => !listos.includes(c.id));
}

/**
 * Lo que se manda al servidor.
 *
 * El corregimiento se descarta en zona urbana, igual que hace PHP: si alguien
 * eligió uno y después corrigió la zona, ese dato sobrante no debe viajar.
 */
export function paraEnviar(d: DatosPre): Record<string, unknown> {
	return {
		nombre_completo: d.nombre_completo.trim(),
		documento: d.documento.replace(SOLO_DIGITOS, ''),
		telefono: d.telefono.replace(SOLO_DIGITOS, ''),
		correo: d.correo.trim(),
		direccion: d.direccion.trim(),
		zona: d.zona,
		corregimiento: d.zona === 'RURAL' ? d.corregimiento : '',
		vereda: d.vereda.trim(),
		senales: d.senales,
		descripcion_dano: d.descripcion_dano.trim(),
		autoriza_datos: d.autoriza_datos,
		latitud: d.latitud,
		longitud: d.longitud,
		precision_m: d.precision_m,
		sitio_web: d.sitio_web
	};
}

/**
 * Qué impide seguir adelante ahora mismo, aparte de los campos.
 *
 * Devuelve el aviso que hay que enseñar, o cadena vacía si se puede.
 *
 * Vive aquí y no dentro del componente porque es una REGLA, no un detalle de
 * pantalla, y porque enviar con un video a medias no se nota: el servidor
 * recibe un archivo incompleto, lo descarta —no se puede reproducir— y la
 * persona ve «Solicitud registrada» creyendo que su video llegó. Es la clase de
 * fallo que solo se descubre cuando alguien pregunta dónde quedó su video.
 */
export function bloqueoDeAvance(estado: {
	optimizandoFotos: boolean;
	videosSubiendo: number;
	/** Cuántos de los videos pedidos siguen sin grabarse. */
	videosFaltantes?: number;
	/**
	 * Si este aparato sabe grabar video.
	 *
	 * Cuando no sabe, los videos NO bloquean. La diferencia entre «obligatorio»
	 * e «imposible» es la que separa exigir evidencia de dejar a una familia sin
	 * turno por el teléfono que le tocó: un celular viejo sin MediaRecorder no
	 * puede grabar por mucho que el formulario insista.
	 */
	puedeGrabar?: boolean;
}): string {
	if (estado.optimizandoFotos) {
		return 'Espere a que terminen de prepararse las fotos.';
	}

	if (estado.videosSubiendo > 0) {
		return 'Espere unos segundos: todavía se está subiendo un video. Si sale ahora, ese video se perderá.';
	}

	const faltan = estado.videosFaltantes ?? 0;

	if (faltan > 0 && estado.puedeGrabar !== false) {
		return faltan === 1
			? 'Falta grabar un video. Le pedimos uno por cada daño que marcó en su vivienda.'
			: `Faltan ${faltan} videos. Le pedimos uno por cada daño que marcó en su vivienda.`;
	}

	return '';
}
