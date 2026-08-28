// Lo que la familia lleva escrito, para que salirse no lo borre.
//
// ── Qué estaba pasando ───────────────────────────────────────────────────────
//
// El formulario ciudadano guardaba sus fotos en IndexedDB —eso funcionaba— pero
// bajo una clave `preinscripcion-${envioId}` con un `envioId` que se generaba
// con `crypto.randomUUID()` en CADA carga de la página. Así que al volver, la
// clave era otra: las fotos seguían en el aparato y no había forma de
// encontrarlas. Y nadie llamaba a `restaurar()`, que era lo único que las
// habría buscado.
//
// El token de la carga tampoco se guardaba. Es el que ata las fotos y los
// videos ya subidos al servidor con la solicitud que se envía al final: sin él,
// al volver se abría una carga nueva y todo lo subido antes quedaba huérfano
// hasta que la purga se lo llevaba.
//
// Resultado: una familia que tomaba diez fotos, dos de la cédula y varios
// videos, y cerraba la pestaña sin querer, lo perdía todo. Es exactamente lo
// que pasó.
//
// ── Qué se guarda y dónde ────────────────────────────────────────────────────
//
// Aquí, en `localStorage`, va lo LIGERO: lo escrito, el paso, el identificador
// del envío y el token de la carga. Las fotos siguen en IndexedDB, atadas a la
// clave que ahora sí es estable (`GestorEvidencias` + `restaurar()`).
//
// ── Y por qué se borra ───────────────────────────────────────────────────────
//
// Esto lleva cédula, nombres, dirección y quiénes viven en la casa. Se borra al
// enviar, se puede borrar a mano desde el formulario, y caduca solo a los siete
// días. Un teléfono se presta; un borrador de hace un mes con los datos de una
// familia damnificada no tiene por qué seguir ahí.

import { browser } from '$app/environment';
import type { DatosPre } from './pasos';
import type { HogarCenso, PersonaHogar } from './hogar';

const CLAVE = 'sgr_preinscripcion_borrador_v1';

/** Pasados estos días, el borrador se descarta solo. */
export const DIAS_VIGENCIA = 7;

export type BorradorPre = {
	/**
	 * El identificador del envío.
	 *
	 * Estable entre visitas a propósito: es lo que hace que reintentar tras una
	 * respuesta perdida devuelva el mismo radicado en vez de inscribir dos veces
	 * a la misma familia, y también la raíz de la clave con la que las fotos
	 * viven en IndexedDB.
	 */
	envioId: string;
	/** El token de la carga del servidor, donde ya viven las fotos y videos subidos. */
	carga: string | null;
	datos: DatosPre;
	personas: PersonaHogar[];
	hogar: HogarCenso | null;
	/** En qué paso iba. */
	indice: number;
	/** Qué videos ya subió, para no volver a pedírselos. */
	videosListos: number[];
	actualizado_en: string;
};

function ahora(): string {
	return new Date().toISOString();
}

/** Un borrador nuevo, vacío, con su identificador de envío recién hecho. */
export function nuevoEnvioId(): string {
	// `randomUUID` no existe en contextos no seguros ni en navegadores viejos.
	// Un identificador peor es infinitamente mejor que una excepción que deja el
	// formulario sin dibujar.
	try {
		return crypto.randomUUID();
	} catch {
		return `pre-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
	}
}

export function guardar(b: Omit<BorradorPre, 'actualizado_en'>): void {
	if (!browser) return;

	try {
		localStorage.setItem(CLAVE, JSON.stringify({ ...b, actualizado_en: ahora() }));
	} catch {
		// Cuota llena o almacenamiento bloqueado (modo privado de algunos
		// navegadores). El formulario tiene que seguir funcionando sin guardar,
		// no romperse: lo que se pierde es la red de seguridad, no el trámite.
	}
}

/**
 * Lo que quedó de la visita anterior, si sigue siendo válido.
 *
 * Devuelve `null` cuando no hay nada, cuando está caducado o cuando lo guardado
 * no tiene la forma esperada — una versión anterior del formulario, o algo que
 * alguien tocó a mano. Ante la duda se descarta: arrancar el formulario con un
 * objeto a medias produce errores que la persona no puede entender ni arreglar.
 */
export function leer(): BorradorPre | null {
	if (!browser) return null;

	try {
		const crudo = localStorage.getItem(CLAVE);
		if (!crudo) return null;

		const b = JSON.parse(crudo) as Partial<BorradorPre>;

		if (typeof b?.envioId !== 'string' || typeof b?.datos !== 'object' || b.datos === null) {
			return null;
		}

		if (estaCaducado(b.actualizado_en)) {
			borrar();

			return null;
		}

		return {
			envioId: b.envioId,
			carga: typeof b.carga === 'string' ? b.carga : null,
			datos: b.datos as DatosPre,
			personas: Array.isArray(b.personas) ? b.personas : [],
			hogar: (b.hogar as HogarCenso) ?? null,
			indice: Number.isInteger(b.indice) ? (b.indice as number) : 0,
			videosListos: Array.isArray(b.videosListos) ? b.videosListos : [],
			actualizado_en: b.actualizado_en ?? ahora()
		};
	} catch {
		return null;
	}
}

export function estaCaducado(actualizado?: string): boolean {
	if (!actualizado) return false;

	const cuando = Date.parse(actualizado);
	if (Number.isNaN(cuando)) return true;

	return Date.now() - cuando > DIAS_VIGENCIA * 24 * 3600 * 1000;
}

export function borrar(): void {
	if (!browser) return;

	try {
		localStorage.removeItem(CLAVE);
	} catch {
		// Igual que al guardar: no puede tumbar el formulario.
	}
}

/**
 * ¿Vale la pena ofrecerle a la persona que continúe?
 *
 * Un borrador donde solo está la cédula —lo que la puerta escribe sola— no es
 * trabajo que nadie eche de menos, y preguntar por él es una pantalla de más
 * antes de empezar. Se ofrece cuando hay algo escrito de verdad, o fotos.
 */
export function valeLaPena(b: BorradorPre, hayFotos: boolean): boolean {
	if (hayFotos) return true;
	if (b.videosListos.length > 0) return true;
	if (b.personas.length > 0) return true;

	const d = b.datos;

	return (
		d.nombre_completo.trim() !== '' ||
		d.telefono.trim() !== '' ||
		d.direccion.trim() !== '' ||
		d.senales.length > 0 ||
		d.descripcion_dano.trim() !== ''
	);
}

/** «hace 3 minutos», para que la persona sepa qué le estamos ofreciendo. */
export function cuandoFue(iso: string): string {
	const minutos = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 60000));

	if (minutos < 1) return 'hace un momento';
	if (minutos < 60) return `hace ${minutos} ${minutos === 1 ? 'minuto' : 'minutos'}`;

	const horas = Math.round(minutos / 60);
	if (horas < 24) return `hace ${horas} ${horas === 1 ? 'hora' : 'horas'}`;

	const dias = Math.round(horas / 24);

	return `hace ${dias} ${dias === 1 ? 'día' : 'días'}`;
}
