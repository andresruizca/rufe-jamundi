import { api } from './client';
import type { Actualizaciones, InfoSistema, RolCatalogo, Usuario } from './tipos';

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
