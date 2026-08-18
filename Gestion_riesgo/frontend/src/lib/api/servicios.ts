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
	// ── Público, sin token ──────────────────────────────────────────────
	// En los POST se pasa `autenticada = false` para no enviar la cabecera
	// Authorization a un endpoint público: un funcionario que abra el formulario
	// ciudadano no tiene por qué exponer ahí su token de sesión.
	catalogos: () => api.get<Catalogos>('/rufe/catalogos'),
	abrirCarga: () => api.post<RespuestaCarga>('/rufe/cargas', {}, false),
	enviarReporte: (cuerpo: Record<string, unknown>) =>
		api.post<RespuestaEnvio>('/rufe/reportes', cuerpo, false),

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
