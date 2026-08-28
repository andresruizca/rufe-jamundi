import type {
	Catalogos as CatalogosInspeccion,
	ProfesionalInspeccion
} from '$lib/inspeccion-form/tipos';
import type { DetalleInspeccion } from '$lib/inspeccion-form/detalle';
import type { HogarCenso } from '$lib/preinscripcion/hogar';
import { api, API_BASE, leerToken } from './client';
import type { Actualizaciones, InfoSistema, RolCatalogo, Usuario } from './tipos';
import type {
	EnvioWhatsapp,
	GestionLlamada,
	AtencionEnCurso,
	GuionVigente,
	HogarParaLlamar,
	ResumenCallCenter
} from '$lib/callcenter/tipos';
import type {
	Catalogos,
	DetalleCompleto,
	EstadoReporte,
	Paginacion,
	ReporteDetalle,
	ReporteResumen,
	RespuestaCarga,
	RespuestaEnvio
} from '$lib/rufe-form/tipos';
import type { FichaMapa, Ubicacion } from '$lib/mapa/datos';

/** Acerca de — las dos pestañas. */
export const acercaApi = {
	sistema: () => api.get<InfoSistema>('/acerca/sistema'),
	// `refrescar=1` salta la caché del servidor: es lo que pide el botón
	// "Buscar actualizaciones", donde esperar 5 minutos no tendría sentido.
	actualizaciones: (refrescar = false) =>
		api.get<Actualizaciones>(`/acerca/actualizaciones${refrescar ? '?refrescar=1' : ''}`)
};

export type DatosUsuario = {
	nombre: string;
	email: string;
	rol: string;
	activo: boolean;
	password?: string;
	/**
	 * Datos propios del profesional que inspecciona viviendas.
	 *
	 * Son los del numeral 1 del formato. Viven en el usuario porque son suyos y
	 * no de la vivienda: sin esto se reescriben a mano, en un teléfono y de pie,
	 * en cada visita.
	 */
	profesion?: string | null;
	tarjeta_profesional?: string | null;
	documento?: string | null;
	documento_de?: string | null;
	telefono?: string | null;
	direccion?: string | null;
};

/** Administración → Gestión de usuarios del sistema. */
export const usuariosApi = {
	listar: () =>
		api.get<{
			usuarios: Usuario[];
			roles: RolCatalogo[];
			// Código y etiqueta. Con solo la etiqueta, la ficha de usuario no tenía
			// con qué guardar el código que el formato de inspección espera.
			profesiones: { codigo: string; etiqueta: string }[];
		}>('/usuarios'),
	crear: (datos: DatosUsuario) => api.post<{ usuario: Usuario }>('/usuarios', datos),
	actualizar: (id: number, datos: Partial<DatosUsuario>) =>
		api.put<{ usuario: Usuario }>(`/usuarios/${id}`, datos),
	eliminar: (id: number) => api.delete<{ mensaje: string }>(`/usuarios/${id}`),
	restablecerPassword: (id: number, password: string) =>
		api.post<{ mensaje: string }>(`/usuarios/${id}/password`, { password })
};

export type FiltrosReportes = {
	estado?: string;
	zona?: string;
	desde?: string;
	hasta?: string;
	q?: string;
	pagina?: number;
};

/** Formulario RUFE: la parte pública y la bandeja interna. */
export const rufeApi = {
	// ── Captura en campo ────────────────────────────────────────────────
	// Todas van autenticadas. Cuando el formulario era público estas tres se
	// llamaban con `autenticada = false` para no exponer el token en rutas
	// abiertas; al volverse internas se cambió el router de PHP pero no estas
	// llamadas, así que salían sin cabecera Authorization y el servidor las
	// rechazaba con 401 — sin importar cuántas veces se iniciara sesión.
	catalogos: () => api.get<Catalogos>('/rufe/catalogos'),
	abrirCarga: () => api.post<RespuestaCarga>('/rufe/cargas', {}),
	enviarReporte: (cuerpo: Record<string, unknown>) =>
		api.post<RespuestaEnvio>('/rufe/reportes', cuerpo),

	// ── Bandeja interna ─────────────────────────────────────────────────
	listar: (filtros: FiltrosReportes = {}) => {
		const p = new URLSearchParams();
		for (const [clave, valor] of Object.entries(filtros)) {
			if (valor !== undefined && valor !== '') p.set(clave, String(valor));
		}
		const consulta = p.toString();

		return api.get<{ reportes: ReporteResumen[]; paginacion: Paginacion }>(
			`/rufe/reportes${consulta ? `?${consulta}` : ''}`
		);
	},
	ver: (id: number) => api.get<DetalleCompleto>(`/rufe/reportes/${id}`),
	cambiarEstado: (id: number, estado: EstadoReporte, nota: string) =>
		api.put<{ reporte: ReporteDetalle }>(`/rufe/reportes/${id}/estado`, { estado, nota }),
	anonimizar: (id: number) => api.post<{ mensaje: string }>(`/rufe/reportes/${id}/anonimizar`, {}),

	/**
	 * Las evidencias no se enlazan directamente: viven fuera del docroot y solo
	 * salen por este endpoint, que exige token y deja rastro en auditoría. Por eso
	 * hay que descargarlas con fetch y no con un `href`, que no lleva cabeceras.
	 */
	/**
	 * Trae una evidencia para verla en pantalla.
	 *
	 * Devuelve una URL de objeto que hay que revocar al terminar: si no, el
	 * navegador conserva la imagen entera en memoria hasta recargar la página, y
	 * una ficha con varias fotos deja al equipo pesado sin motivo.
	 *
	 * Va por `fetch` y no por un `src` directo porque las evidencias viven fuera
	 * del servidor web y solo salen por este endpoint, que exige el token en una
	 * cabecera — algo que una etiqueta `<img>` no puede enviar.
	 */
	async verEvidencia(reporteId: number, evidenciaId: number): Promise<string> {
		const respuesta = await fetch(
			`${API_BASE}/rufe/reportes/${reporteId}/evidencias/${evidenciaId}`,
			{ headers: { Authorization: `Bearer ${leerToken() ?? ''}` } }
		);

		if (!respuesta.ok) throw new Error('No se pudo abrir la imagen.');

		return URL.createObjectURL(await respuesta.blob());
	},

	async descargarEvidencia(reporteId: number, evidenciaId: number, nombre: string): Promise<void> {
		const respuesta = await fetch(
			`${API_BASE}/rufe/reportes/${reporteId}/evidencias/${evidenciaId}`,
			{ headers: { Authorization: `Bearer ${leerToken() ?? ''}` } }
		);

		if (!respuesta.ok) throw new Error('No se pudo descargar el archivo.');

		const blob = await respuesta.blob();
		const url = URL.createObjectURL(blob);
		const enlace = document.createElement('a');
		enlace.href = url;
		enlace.download = nombre;
		enlace.click();
		URL.revokeObjectURL(url);
	},

	/**
	 * Las cifras del tablero, calculadas en el servidor.
	 *
	 * Antes el tablero las armaba en el navegador leyendo una hoja de Google en
	 * vivo: mostraba un censo distinto del que usa el resto del sistema, bajaba
	 * el censo entero al teléfono para sumarlo allí, y dependía de que esa hoja
	 * siguiera compartida con cualquiera que tuviera el enlace.
	 */
	tablero: () => api.get<import('$lib/rufe/types').Dataset>('/rufe/tablero')
};

/**
 * Ubicaciones para la sección Mapas.
 *
 * El navegador nunca llama a un geocodificador: le pide al servidor las
 * direcciones que ya están resueltas. Geocodificar tiene cupo por segundo,
 * puede costar dinero y necesita una clave que no debe viajar hasta aquí.
 */
/**
 * Inspección de viviendas afectadas (formato NGRD).
 *
 * El censo dice quién quedó afectado; esto evalúa la vivienda y determina qué
 * materiales le corresponden. Van por su propio prefijo y no bajo `/rufe`
 * porque son documentos distintos con permisos y ciclos de vida distintos.
 *
 * Las fotos del numeral 11 reutilizan `rufeApi.abrirCarga`: la maquinaria de
 * subida es la misma y el servidor decide a qué expediente adopta la carga
 * según por dónde llegue el envío.
 */
/**
 * Call center: la campaña que lleva a la gente del censo hasta la
 * preinscripción.
 *
 * Cuelga de `/callcenter` y no de `/rufe` a propósito: es lo único que alcanza
 * el rol OPERADOR, y lo que devuelve es una lista para llamar —nombre, teléfono
 * y barrio—, no las fichas del censo.
 */
export const callCenterApi = {
	resumen: () => api.get<{ resumen: ResumenCallCenter }>('/callcenter/resumen'),

	hogares: (filtros: Record<string, string | number> = {}) => {
		const q = new URLSearchParams(
			Object.entries(filtros)
				.filter(([, v]) => v !== '' && v !== null && v !== undefined)
				.map(([k, v]) => [k, String(v)])
		).toString();

		return api.get<{
			hogares: HogarParaLlamar[];
			paginacion: { pagina: number; por_pagina: number; total: number; paginas: number };
			resultados: Record<string, string>;
			/**
			 * Cuántos hogares encuentra esta búsqueda fuera de la pestaña abierta.
			 *
			 * Cero cuando no se está buscando. Existe para que un «no hay nadie»
			 * no se lea como «esta familia no está en el censo»: ver
			 * `CallCenterController::enOtrasListas`.
			 */
			en_otras_listas: number;
		}>(`/callcenter/hogares${q ? `?${q}` : ''}`);
	},

	historial: (id: number) =>
		api.get<{ gestiones: GestionLlamada[] }>(`/callcenter/hogares/${id}/gestiones`),

	registrar: (id: number, cuerpo: Record<string, unknown>) =>
		api.post<{ gestion: { id: number; resultado: string } }>(
			`/callcenter/hogares/${id}/gestiones`,
			cuerpo
		),

	/**
	 * Quién está llamando a quién, ahora mismo.
	 *
	 * Va aparte de la lista y se pide cada pocos segundos. Recargar la lista
	 * entera para refrescar un aviso borraría lo que la operadora esté
	 * escribiendo en su anotación —y eso pasa justo mientras habla.
	 */
	/**
	 * Le manda a este hogar el enlace del formulario por WhatsApp.
	 *
	 * Un hogar por pulsación. El token del proveedor NO vive aquí: lo guarda el
	 * servidor y es él quien envía. Un token en el navegador es un token público,
	 * y con él cualquiera manda WhatsApp desde el número de la Alcaldía.
	 *
	 * Puede fallar con 409 si ya se le envió hace poco, con 422 si el hogar no
	 * tiene celular y con 502 si el proveedor lo rechaza — en los tres casos el
	 * mensaje del servidor está escrito para mostrarse tal cual.
	 *
	 * `repetir` es la respuesta de la operadora al 409: sí, mándaselo otra vez.
	 * No sirve para saltarse el freno de los dos minutos, que existe contra el
	 * doble clic y no contra una decisión.
	 */
	enviarWhatsapp: (id: number, repetir = false) =>
		api.post<{
			enviado: boolean;
			telefono: string;
			nombre: string;
			envios: EnvioWhatsapp[];
		}>(`/callcenter/hogares/${id}/whatsapp`, repetir ? { repetir: '1' } : {}),

	/** Los WhatsApp que se le han mandado a este hogar, con fecha y hora. */
	enviosWhatsapp: (id: number) =>
		api.get<{ envios: EnvioWhatsapp[] }>(`/callcenter/hogares/${id}/whatsapp`),

	atenciones: () =>
		api.get<{ atenciones: AtencionEnCurso[]; minutos: number }>('/callcenter/atenciones'),

	/** «Estoy llamando a este hogar», o «ya lo solté». */
	atender: (id: number, soltar = false) =>
		api.post<{ atendiendo: boolean }>(`/callcenter/hogares/${id}/atencion`, { soltar }),

	guion: () =>
		api.get<{ guion: GuionVigente; predeterminado: string; whatsapp_oficial: string }>(
			'/callcenter/guion'
		),

	guardarGuion: (cuerpo: string) =>
		api.put<{ guion: GuionVigente }>('/callcenter/guion', { cuerpo })
};

export const inspeccionApi = {
	catalogos: () => api.get<CatalogosInspeccion>('/inspeccion/catalogos'),
	enviar: (cuerpo: Record<string, unknown>) =>
		api.post<{ numero: string; recibido_en: string; combo: string | null; combo_motivo: string | null; reintento?: boolean }>(
			'/inspeccion/fichas',
			cuerpo
		),

	/**
	 * Los profesionales que pueden figurar como responsables del numeral 1.
	 *
	 * NO se guarda para trabajar sin señal, a diferencia de los catálogos: trae
	 * cédulas y teléfonos de funcionarios, y la caché del navegador vive en un
	 * teléfono que se presta y se pierde. Sin conexión el formato sigue
	 * funcionando con el nombre escrito a mano.
	 */
	profesionales: () =>
		api.get<{ profesionales: ProfesionalInspeccion[] }>('/inspeccion/profesionales'),

	/** ¿Ya se inspeccionó esta vivienda? Avisa, no impide: puede ser legítimo. */
	duplicados: (documento: string) =>
		api.get<{ inspecciones: { numero: string; fecha_evaluacion: string; combo: string | null; cumple_requisitos: number }[] }>(
			`/inspeccion/duplicados?documento=${encodeURIComponent(documento)}`
		),

	listar: (filtros: Record<string, string | number> = {}) => {
		const p = new URLSearchParams();
		for (const [clave, valor] of Object.entries(filtros)) {
			if (valor !== undefined && valor !== '') p.set(clave, String(valor));
		}
		const consulta = p.toString();

		return api.get<{
			inspecciones: Record<string, unknown>[];
			total: number;
			pagina: number;
			por_pagina: number;
		}>(`/inspeccion/fichas${consulta ? `?${consulta}` : ''}`);
	},
	ver: (id: number) => api.get<DetalleInspeccion>(`/inspeccion/fichas/${id}`),

	/**
	 * Las fotos del numeral 11, por el mismo camino que las del censo: viven
	 * fuera del docroot y solo salen con el token en una cabecera, algo que una
	 * etiqueta `<img>` no sabe enviar.
	 */
	async verEvidencia(inspeccionId: number, fotoId: number): Promise<string> {
		const respuesta = await fetch(`${API_BASE}/inspeccion/fichas/${inspeccionId}/fotos/${fotoId}`, {
			headers: { Authorization: `Bearer ${leerToken() ?? ''}` }
		});

		if (!respuesta.ok) throw new Error('No se pudo abrir la imagen.');

		return URL.createObjectURL(await respuesta.blob());
	},

	async descargarEvidencia(inspeccionId: number, fotoId: number, nombre: string): Promise<void> {
		const respuesta = await fetch(`${API_BASE}/inspeccion/fichas/${inspeccionId}/fotos/${fotoId}`, {
			headers: { Authorization: `Bearer ${leerToken() ?? ''}` }
		});

		if (!respuesta.ok) throw new Error('No se pudo descargar el archivo.');

		const url = URL.createObjectURL(await respuesta.blob());
		const enlace = document.createElement('a');
		enlace.href = url;
		enlace.download = nombre;
		enlace.click();
		URL.revokeObjectURL(url);
	},
	cambiarEstado: (id: number, estado: string, nota: string) =>
		api.put<{ estado: string }>(`/inspeccion/fichas/${id}/estado`, { estado, nota })
};

/**
 * Pre-inscripción ciudadana.
 *
 * Las dos primeras llamadas van SIN token: las hace alguien que no tiene cuenta.
 * `api.get`/`api.post` aceptan `autenticada = false` justo para esto.
 *
 * No hay ninguna función para consultar una solicitud: no existe esa ruta en el
 * servidor y no debe existir. Un endpoint público que devolviera solicitudes por
 * radicado sería un buscador de damnificados.
 */
export const preinscripcionApi = {
	catalogos: () =>
		api.get<{
			corregimientos: string[];
			/** Los 165 barrios del POT que entregó Planeación. */
			barrios: string[];
			// Listas fijas para el listado del hogar. No son datos de nadie: son
			// las mismas que ve el funcionario en el censo.
			parentescos: Record<string, string>;
			generos: Record<string, string>;
			tipos_documento: Record<string, string>;
			parentesco_jefe: number;
			zonas: string[];
			/** Las señales de daño que el ciudadano puede reconocer a ojo. */
			senales: { codigo: string; etiqueta: string; ayuda: string; icono: string }[];
			aviso_version: string;
			limites: {
				fotos_dano: number;
				fotos_cedula: number;
				fotos_cedula_reverso: number;
				bytes_archivo: number;
				bytes_carga: number;
				objetivo_bytes_foto: number;
				extensiones: string[];
			};
			categorias_video: {
				id: number;
				nombre: string;
				instruccion: string | null;
				/** El daño al que responde: solo se le pide a quien lo marcó. */
				senal: string | null;
				obligatoria: boolean;
				segundos_min: number;
				segundos_max: number;
			}[];
			video: { bytes_trozo: number; max_bytes: number; max_videos: number };
		}>('/preinscripcion/catalogos', false),

	/**
	 * ¿Esta cédula está en el censo? La primera pantalla del formulario.
	 *
	 * Por POST y no por GET a propósito: una cédula en la barra de direcciones
	 * acaba en el registro de accesos del servidor y en el historial del
	 * teléfono. La respuesta es un booleano y nada más — ver
	 * `backend/src/Preinscripcion/Censo.php`.
	 */
	verificar: (documento: string) =>
		api.post<{ habilitado: boolean; linea_atencion: string }>(
			'/preinscripcion/verificacion',
			{ documento },
			false
		),

	/**
	 * Lo que el censo ya sabe de ese hogar.
	 *
	 * Exige `carga`: el servidor comprueba que en esa carga haya una foto de
	 * cédula ya subida antes de contestar. La de arriba responde un booleano
	 * porque preguntarle es gratis; esta enseña nombre, teléfono, dirección y
	 * quién vive en la casa, así que preguntar tiene que costar algo.
	 */
	datosCenso: (documento: string, carga: string) =>
		api.post<{ hogar: HogarCenso | null }>(
			'/preinscripcion/datos-censo',
			{ documento, carga },
			false
		),

	/** Abre una carga para las fotos, sin sesión. */
	abrirCarga: () =>
		api.post<{ carga: string; maximo_archivos: number; maximo_bytes: number }>(
			'/preinscripcion/cargas',
			{},
			false
		),

	enviar: (cuerpo: Record<string, unknown>) =>
		api.post<{
			radicado: string;
			recibido_en: string;
			reintento?: boolean;
			duplicada?: boolean;
			/** Fotos y videos del reenvío que se sumaron a la solicitud que ya existía. */
			archivos_agregados?: number;
		}>(
			'/preinscripcion',
			cuerpo,
			false
		),

	// ── Bandeja interna (con sesión) ──────────────────────────────────────
	listar: (filtros: Record<string, string | number> = {}) => {
		const p = new URLSearchParams();
		for (const [clave, valor] of Object.entries(filtros)) {
			if (valor !== undefined && valor !== '') p.set(clave, String(valor));
		}
		const consulta = p.toString();

		return api.get<{
			preinscripciones: Record<string, unknown>[];
			total: number;
			pagina: number;
			por_pagina: number;
		}>(`/preinscripcion/fichas${consulta ? `?${consulta}` : ''}`);
	},

	/**
	 * Cómo va el proceso, no solo en qué estado está cada solicitud.
	 *
	 * Las pestañas dicen cuántas hay en cada casilla; esto dice si el trabajo
	 * avanza o se acumula, que es otra pregunta.
	 */
	resumen: () =>
		api.get<{
			por_estado: Record<string, number>;
			total: number;
			hoy: number;
			semana: number;
			demoradas: number;
			dias_demora: number;
			mas_antigua_sin_atender: string | null;
		}>('/preinscripcion/resumen'),

	ver: (id: number) => api.get<PreinscripcionDetalle>(`/preinscripcion/fichas/${id}`),

	/** Las fotos viven fuera del docroot y solo salen con el token en la cabecera. */
	async verEvidencia(preinscripcionId: number, fotoId: number): Promise<string> {
		const respuesta = await fetch(
			`${API_BASE}/preinscripcion/fichas/${preinscripcionId}/fotos/${fotoId}`,
			{ headers: { Authorization: `Bearer ${leerToken() ?? ''}` } }
		);

		if (!respuesta.ok) throw new Error('No se pudo abrir la imagen.');

		return URL.createObjectURL(await respuesta.blob());
	},

	async descargarEvidencia(preinscripcionId: number, fotoId: number, nombre: string): Promise<void> {
		const respuesta = await fetch(
			`${API_BASE}/preinscripcion/fichas/${preinscripcionId}/fotos/${fotoId}`,
			{ headers: { Authorization: `Bearer ${leerToken() ?? ''}` } }
		);

		if (!respuesta.ok) throw new Error('No se pudo descargar el archivo.');

		const url = URL.createObjectURL(await respuesta.blob());
		const enlace = document.createElement('a');
		enlace.href = url;
		enlace.download = nombre;
		enlace.click();
		URL.revokeObjectURL(url);
	},

	/**
	 * `motivo` solo viaja al descartar, y entonces es obligatorio.
	 *
	 * Es lo que decide si la familia vuelve o no a la cola del call center. La
	 * nota es texto libre para que la operadora sepa qué decirle; el motivo es
	 * lo que el sistema puede leer sin que nadie tenga que abrir mil trescientas
	 * solicitudes a mano.
	 */
	cambiarEstado: (id: number, estado: string, nota: string, motivo = '') =>
		api.put<{ estado: string; motivo: string | null }>(`/preinscripcion/fichas/${id}/estado`, {
			estado,
			nota,
			motivo
		}),

	/** Irreversible: borra la ficha, sus fotos y sus videos. Solo Administrador. */
	eliminar: (id: number, motivo: string) =>
		api.delete<{ mensaje: string; archivos_borrados: number }>(
			`/preinscripcion/fichas/${id}`,
			{ motivo }
		),

	/**
	 * Lo mismo, con varias a la vez. Irreversible. Solo Administrador.
	 *
	 * Va por POST y no por DELETE porque lleva cuerpo —los identificadores y el
	 * motivo— y hay intermediarios que descartan el cuerpo de un DELETE sin
	 * avisar.
	 *
	 * La respuesta separa lo borrado de lo conservado: una solicitud ya
	 * convertida en inspección no se borra, y el lote sigue con las demás en vez
	 * de fallar entero.
	 */
	eliminarLote: (ids: number[], motivo: string) =>
		api.post<{
			eliminadas: string[];
			conservadas: { id: number; radicado?: string; motivo: string }[];
			archivos_borrados: number;
			mensaje: string;
		}>('/preinscripcion/fichas/eliminar-lote', { ids, motivo }),

	/**
	 * Un video de la solicitud, para verlo en la bandeja.
	 *
	 * Igual que las fotos: vive fuera del docroot y solo sale con el token en la
	 * cabecera, algo que una etiqueta `<video src>` no sabe enviar.
	 */
	async verVideo(preinscripcionId: number, videoId: number): Promise<string> {
		const respuesta = await fetch(
			`${API_BASE}/preinscripcion/fichas/${preinscripcionId}/videos/${videoId}`,
			{ headers: { Authorization: `Bearer ${leerToken() ?? ''}` } }
		);

		if (!respuesta.ok) throw new Error('No se pudo abrir el video.');

		return URL.createObjectURL(await respuesta.blob());
	}
};

/**
 * Quien la puerta de la cédula rechazó, pero puede necesitar ayuda igual.
 *
 * `crear()` es pública, igual que `preinscripcionApi.enviar`: sin sesión,
 * con las mismas defensas del lado del servidor. El resto exige sesión de
 * lectura del censo — es el mismo tipo de dato (nombre, teléfono, ubicación
 * de alguien que puede ser una familia damnificada) aunque todavía no tenga
 * ficha RUFE.
 */
export const sinCensoApi = {
	crear: (cuerpo: Record<string, unknown>) =>
		api.post<{ radicado: string; recibido_en: string; reintento?: boolean }>(
			'/sin-censo',
			cuerpo,
			false
		),

	listar: (estado = '') =>
		api.get<{
			solicitudes: {
				id: number;
				radicado: string;
				nombres: string;
				apellidos: string;
				telefono: string;
				zona: string;
				corregimiento: string | null;
				vereda_sector_barrio: string | null;
				direccion: string | null;
				estado: string;
				rufe_reporte_id: number | null;
				creado_en: string;
			}[];
			estados: Record<string, string>;
		}>(`/sin-censo${estado ? `?estado=${estado}` : ''}`),

	ver: (id: number) =>
		api.get<{
			solicitud: {
				id: number;
				radicado: string;
				documento: string | null;
				nombres: string;
				apellidos: string;
				telefono: string;
				zona: string;
				corregimiento: string | null;
				vereda_sector_barrio: string | null;
				direccion: string | null;
				descripcion: string | null;
				estado: string;
				rufe_reporte_id: number | null;
				/** El radicado de la ficha, ya resuelto: es lo que se enseña en pantalla. */
				rufe_radicado: string | null;
				creado_en: string;
			};
			estados: Record<string, string>;
		}>(`/sin-censo/${id}`),

	/**
	 * `rufeRadicado` y no un id: es lo único que el funcionario tiene delante al
	 * terminar de enviar la ficha nueva, y solo se pide al marcar CONVERTIDA.
	 */
	cambiarEstado: (id: number, estado: string, rufeRadicado?: string) =>
		api.put<{ estado: string; rufe_reporte_id: number | null }>(`/sin-censo/${id}/estado`, {
			estado,
			rufe_radicado: rufeRadicado
		})
};

export type PreinscripcionDetalle = {
	preinscripcion: Record<string, string | number | null>;
	fotos: { id: number; nombre_original: string; extension: string; tamano_bytes: number; mime: string }[];
	/**
	 * Lo que el ciudadano marcó, con la etiqueta que vio en su momento.
	 *
	 * El `icono` NO viene guardado: lo resuelve el servidor contra el catálogo
	 * de hoy. La etiqueta prueba qué se le mostró y queda congelada; el dibujo
	 * es solo la forma de enseñárselo a quien revisa.
	 */
	senales: { codigo: string; etiqueta: string; icono: string }[];
	videos: {
		id: number;
		categoria_nombre: string;
		segundos: number | null;
		tamano_bytes: number;
		extension: string;
		mime: string;
		/** Falso cuando el archivo ya se purgó al decidir la solicitud. */
		disponible: boolean;
	}[];
	/**
	 * El hogar que dejó el ciudadano, con lo que decía el censo al lado.
	 *
	 * Vacío en las solicitudes que no se precargaron: quien llegó sin ficha, o
	 * sin señal para traerla, no manda listado.
	 */
	hogar: {
		id: number;
		estado: 'IGUAL' | 'CORREGIDA' | 'NUEVA' | 'NO_VIVE_AQUI';
		nombres: string;
		apellidos: string;
		numero_documento: string;
		tipo_documento: string;
		parentesco: string;
		genero: string;
		fecha_nacimiento: string;
		/** Cómo lo tenía el censo. `null` cuando la persona no venía de él. */
		censo: {
			nombres: string;
			apellidos: string;
			numero_documento: string;
			tipo_documento: string;
			parentesco: string;
			genero: string;
			fecha_nacimiento: string;
		} | null;
	}[];
	historial: { estado: string; nota: string | null; usuario_email: string | null; creado_en: string }[];
};

export type CategoriaVideo = {
	id: number;
	nombre: string;
	instruccion: string | null;
	orden: number;
	obligatoria: boolean;
	segundos_min: number;
	segundos_max: number;
	activa: boolean;
};

/** Catálogo de categorías de video. Solo administración. */
export const categoriasVideoApi = {
	listar: () =>
		api.get<{ categorias: CategoriaVideo[]; maximo_obligatorias: number }>('/admin/categorias-video'),

	crear: (datos: Partial<CategoriaVideo>) =>
		api.post<{ categoria: CategoriaVideo }>('/admin/categorias-video', datos),

	actualizar: (id: number, datos: Partial<CategoriaVideo>) =>
		api.put<{ categoria: CategoriaVideo }>(`/admin/categorias-video/${id}`, datos),

	cambiarEstado: (id: number, activa: boolean) =>
		api.put<{ categoria: CategoriaVideo }>(`/admin/categorias-video/${id}/estado`, { activa }),

	reordenar: (orden: number[]) =>
		api.put<{ categorias: CategoriaVideo[] }>('/admin/categorias-video/orden', { orden }),

	eliminar: (id: number) => api.delete<void>(`/admin/categorias-video/${id}`)
};

export const mapaApi = {
	fichas: () => api.get<{ fichas: FichaMapa[] }>('/mapa/fichas'),

	ubicaciones: (direcciones: string[]) =>
		api.post<{
			ubicaciones: Record<string, Ubicacion>;
			consultadas: number;
			pendientes: number;
			descartadas: number;
		}>('/mapa/ubicaciones', { direcciones }),

	estado: () =>
		api.get<{
			por_precision: Record<string, number>;
			pendientes: number;
			/** Las pendientes que el censo de hoy va a dibujar de verdad. */
			pendientes_en_uso: number;
			/** Las que quedaron de cuando el mapa leía una hoja de cálculo. */
			obsoletas: number;
			direcciones_del_censo: number;
			lote: number;
			google_activo: boolean;
			segundos_por_direccion: number;
			consultas_por_direccion: number;
		}>('/mapa/estado'),

	geocodificar: () =>
		api.post<{ procesadas: number; ubicadas: number; sin_ubicar: number; pendientes: number }>(
			'/mapa/geocodificar',
			{}
		),

	reubicar: () =>
		api.post<{ reencoladas: number; conservadas: number }>('/mapa/reubicar', {}),

	corregir: (clave: string, latitud: number, longitud: number) =>
		api.put<{ clave: string }>(`/mapa/ubicaciones/${clave}`, { latitud, longitud })
};
