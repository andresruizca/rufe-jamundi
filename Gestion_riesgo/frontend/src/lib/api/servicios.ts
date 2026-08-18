import { api, API_BASE, leerToken } from './client';
import type { Actualizaciones, InfoSistema, RolCatalogo, Usuario } from './tipos';
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
import type { Ubicacion } from '$lib/mapa/datos';

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
};

/** Administración → Gestión de usuarios del sistema. */
export const usuariosApi = {
	listar: () => api.get<{ usuarios: Usuario[]; roles: RolCatalogo[] }>('/usuarios'),
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
	}
};

/**
 * Ubicaciones para la sección Mapas.
 *
 * El navegador nunca llama a un geocodificador: le pide al servidor las
 * direcciones que ya están resueltas. Geocodificar tiene cupo por segundo,
 * puede costar dinero y necesita una clave que no debe viajar hasta aquí.
 */
export const mapaApi = {
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
			lote: number;
			google_activo: boolean;
			segundos_por_direccion: number;
		}>('/mapa/estado'),

	geocodificar: () =>
		api.post<{ procesadas: number; ubicadas: number; sin_ubicar: number; pendientes: number }>(
			'/mapa/geocodificar',
			{}
		),

	corregir: (clave: string, latitud: number, longitud: number) =>
		api.put<{ clave: string }>(`/mapa/ubicaciones/${clave}`, { latitud, longitud })
};
