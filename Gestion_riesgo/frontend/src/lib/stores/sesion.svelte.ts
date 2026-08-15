import { api, borrarToken, guardarToken, leerToken } from '$lib/api/client';
import type { UsuarioSesion } from '$lib/api/tipos';
import type { Rol } from '$lib/navigation';

/**
 * Estado de sesión compartido por toda la aplicación.
 *
 * Se usa una clase con runes en vez de un store de Svelte 4 porque el resto del
 * proyecto ya está en modo runes; mezclar los dos modelos obliga a recordar cuál
 * necesita `$` y cuál no.
 */
class Sesion {
	usuario = $state<UsuarioSesion | null>(null);
	/** true mientras se comprueba el token guardado al abrir la aplicación. */
	cargando = $state(true);

	get autenticado(): boolean {
		return this.usuario !== null;
	}

	get rol(): Rol | null {
		return this.usuario?.rol ?? null;
	}

	puede(capacidad: string): boolean {
		return this.usuario?.capacidades.includes(capacidad) ?? false;
	}

	/**
	 * Recupera la sesión desde el token guardado. Se llama una vez al arrancar:
	 * el token vive en localStorage, pero quién es y qué puede hacer lo decide
	 * siempre el servidor, nunca el navegador.
	 */
	async restaurar(): Promise<void> {
		this.cargando = true;

		if (!leerToken()) {
			this.usuario = null;
			this.cargando = false;

			return;
		}

		try {
			const { usuario } = await api.get<{ usuario: UsuarioSesion }>('/auth/me');
			this.usuario = usuario;
		} catch {
			// Token inválido o expirado: el cliente ya lo borró en el 401.
			this.usuario = null;
		} finally {
			this.cargando = false;
		}
	}

	async iniciar(email: string, password: string): Promise<void> {
		const datos = await api.post<{ token: string; usuario: UsuarioSesion }>(
			'/auth/login',
			{ email, password },
			false
		);

		guardarToken(datos.token);
		this.usuario = datos.usuario;
	}

	async cerrar(): Promise<void> {
		try {
			await api.post('/auth/logout');
		} catch {
			// Si el servidor no responde, la sesión se cierra igual en el
			// navegador: dejar al usuario dentro sería peor.
		} finally {
			borrarToken();
			this.usuario = null;
		}
	}
}

export const sesion = new Sesion();
