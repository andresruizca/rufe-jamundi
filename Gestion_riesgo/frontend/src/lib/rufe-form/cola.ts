// Cola de fichas pendientes de enviar, compartida entre la página y el Service
// Worker.
//
// Es el único punto donde los dos se hablan, y por eso no importa nada de
// SvelteKit: el Service Worker corre fuera de la aplicación, sin DOM, sin
// `$app/environment` y sin acceso a localStorage. IndexedDB es lo único que
// ambos ven.
//
// Qué guarda cada ficha: el CUERPO del reporte, no una petición ya firmada. La
// diferencia importa. Si guardáramos la petición con su cabecera Authorization,
// una ficha que pasa la noche sin señal se reintentaría con un token vencido y
// fallaría con un 401 silencioso dentro del Service Worker. Guardando el cuerpo,
// el token se toma en el momento de enviar; si no hay sesión válida, la ficha
// espera y la aplicación avisa.

const BASE = 'sgr_rufe_cola';
const VERSION = 1;

/** Fichas pendientes de enviar. */
const FICHAS = 'fichas';

/** Fotos pendientes, atadas a la ficha que las lleva. */
const FOTOS = 'fotos';

/** Espejo del token de sesión, para que el Service Worker pueda leerlo. */
const SESION = 'sesion';

/** Etiqueta del evento de Background Sync. Debe coincidir en el Service Worker. */
export const ETIQUETA_SYNC = 'sgr-enviar-fichas';

export type EstadoFicha = 'pendiente' | 'enviando' | 'enviada' | 'error';

export type FichaEnCola = {
	/** Identificador de envío. Es lo que hace seguro reintentar. */
	envioId: string;
	cuerpo: Record<string, unknown>;
	estado: EstadoFicha;
	intentos: number;
	creadoEn: number;
	actualizadoEn: number;
	/** Radicado devuelto por el servidor, cuando ya se envió. */
	radicado?: string;
	/** Último error, para poder explicarlo sin adivinar. */
	error?: string;
	/** Resumen mínimo para poder listarla sin abrir el cuerpo entero. */
	resumen: { evento: string; direccion: string; personas: number };
};

export type FotoEnCola = {
	uid: string;
	envioId: string;
	tipo: 'DOCUMENTO' | 'DANO';
	nombre: string;
	mime: string;
	blob: Blob;
	subida: boolean;
};

// ── Apertura ─────────────────────────────────────────────────────────────────

function abrir(): Promise<IDBDatabase | null> {
	return new Promise((resolver) => {
		if (typeof indexedDB === 'undefined') {
			resolver(null);

			return;
		}

		let solicitud: IDBOpenDBRequest;
		try {
			solicitud = indexedDB.open(BASE, VERSION);
		} catch {
			resolver(null);

			return;
		}

		solicitud.onupgradeneeded = () => {
			const db = solicitud.result;

			if (!db.objectStoreNames.contains(FICHAS)) {
				const almacen = db.createObjectStore(FICHAS, { keyPath: 'envioId' });
				almacen.createIndex('estado', 'estado', { unique: false });
			}

			if (!db.objectStoreNames.contains(FOTOS)) {
				const almacen = db.createObjectStore(FOTOS, { keyPath: 'uid' });
				almacen.createIndex('envioId', 'envioId', { unique: false });
			}

			if (!db.objectStoreNames.contains(SESION)) {
				db.createObjectStore(SESION);
			}
		};

		solicitud.onsuccess = () => resolver(solicitud.result);
		solicitud.onerror = () => resolver(null);
		solicitud.onblocked = () => resolver(null);
	});
}

/**
 * Envuelve una operación sobre un almacén. Todo falla en silencio devolviendo
 * el valor por omisión: si el navegador tiene el almacenamiento bloqueado, el
 * formulario debe seguir usable aunque no pueda encolar.
 */
async function conAlmacen<T>(
	nombre: string,
	modo: IDBTransactionMode,
	fn: (almacen: IDBObjectStore) => IDBRequest,
	porDefecto: T
): Promise<T> {
	const db = await abrir();
	if (!db) return porDefecto;

	return new Promise<T>((resolver) => {
		try {
			const tx = db.transaction(nombre, modo);
			const solicitud = fn(tx.objectStore(nombre));
			solicitud.onsuccess = () => resolver((solicitud.result as T) ?? porDefecto);
			solicitud.onerror = () => resolver(porDefecto);
			tx.oncomplete = () => db.close();
		} catch {
			resolver(porDefecto);
		}
	});
}

// ── Fichas ───────────────────────────────────────────────────────────────────

export async function guardarFicha(ficha: FichaEnCola): Promise<void> {
	await conAlmacen(FICHAS, 'readwrite', (a) => a.put(ficha), undefined);
}

export async function leerFicha(envioId: string): Promise<FichaEnCola | null> {
	return conAlmacen<FichaEnCola | null>(FICHAS, 'readonly', (a) => a.get(envioId), null);
}

export async function borrarFicha(envioId: string): Promise<void> {
	await conAlmacen(FICHAS, 'readwrite', (a) => a.delete(envioId), undefined);
	await borrarFotosDe(envioId);
}

export async function todasLasFichas(): Promise<FichaEnCola[]> {
	return conAlmacen<FichaEnCola[]>(FICHAS, 'readonly', (a) => a.getAll(), []);
}

/** Las que todavía deben salir. Es lo que recorre el Service Worker. */
export async function fichasPendientes(): Promise<FichaEnCola[]> {
	const todas = await todasLasFichas();

	return todas
		.filter((f) => f.estado === 'pendiente' || f.estado === 'error')
		.sort((a, b) => a.creadoEn - b.creadoEn);
}

// ── Fotos ────────────────────────────────────────────────────────────────────

export async function guardarFoto(foto: FotoEnCola): Promise<void> {
	await conAlmacen(FOTOS, 'readwrite', (a) => a.put(foto), undefined);
}

export async function fotosDe(envioId: string): Promise<FotoEnCola[]> {
	const db = await abrir();
	if (!db) return [];

	return new Promise((resolver) => {
		try {
			const tx = db.transaction(FOTOS, 'readonly');
			const solicitud = tx.objectStore(FOTOS).index('envioId').getAll(envioId);
			solicitud.onsuccess = () => resolver((solicitud.result as FotoEnCola[]) ?? []);
			solicitud.onerror = () => resolver([]);
			tx.oncomplete = () => db.close();
		} catch {
			resolver([]);
		}
	});
}

export async function borrarFoto(uid: string): Promise<void> {
	await conAlmacen(FOTOS, 'readwrite', (a) => a.delete(uid), undefined);
}

export async function borrarFotosDe(envioId: string): Promise<void> {
	const fotos = await fotosDe(envioId);
	await Promise.all(fotos.map((f) => borrarFoto(f.uid)));
}

// ── Sesión ───────────────────────────────────────────────────────────────────

/**
 * El token vive en localStorage, que el Service Worker no puede leer. Se espeja
 * aquí en cada arranque de la aplicación y al iniciar o cerrar sesión.
 *
 * No es una copia con menos protección: localStorage e IndexedDB tienen el mismo
 * alcance de origen y la misma exposición ante un XSS. Lo que cambia es quién
 * puede leerlo dentro del propio navegador.
 */
export async function espejarToken(token: string | null): Promise<void> {
	await conAlmacen(
		SESION,
		'readwrite',
		(a) => (token === null ? a.delete('token') : a.put(token, 'token')),
		undefined
	);
}

export async function tokenEspejado(): Promise<string | null> {
	return conAlmacen<string | null>(SESION, 'readonly', (a) => a.get('token'), null);
}

// ── Almacenamiento persistente ───────────────────────────────────────────────

/**
 * Pide que el navegador no borre esta cola cuando el teléfono se quede sin
 * espacio.
 *
 * Sin esto, IndexedDB se desaloja por «usado menos recientemente» y se borra el
 * origen ENTERO de golpe: se perderían todas las fichas levantadas y sin enviar.
 * Chrome concede o niega solo, según el historial de uso del sitio, sin
 * preguntar; instalar la aplicación mejora mucho las probabilidades.
 */
export async function pedirAlmacenamientoPersistente(): Promise<boolean> {
	if (typeof navigator === 'undefined' || !navigator.storage?.persist) return false;

	try {
		if (await navigator.storage.persisted()) return true;

		return await navigator.storage.persist();
	} catch {
		return false;
	}
}

/** Cuánto espacio hay, para avisar antes de que se acabe. */
export async function espacioDisponible(): Promise<{ usado: number; total: number } | null> {
	if (typeof navigator === 'undefined' || !navigator.storage?.estimate) return null;

	try {
		const { usage, quota } = await navigator.storage.estimate();

		return { usado: usage ?? 0, total: quota ?? 0 };
	} catch {
		return null;
	}
}

// ── Registro del envío en segundo plano ──────────────────────────────────────

/**
 * Le pide al navegador que entregue el evento `sync` cuando vuelva la
 * conectividad, aunque para entonces el censador ya haya cerrado la aplicación.
 *
 * Devuelve false donde no hay soporte —Firefox y Safari no implementan
 * Background Sync—; ahí la aplicación se queda con el reintento en primer plano,
 * que ya funciona mientras la pestaña esté abierta.
 */
export async function pedirEnvioEnSegundoPlano(): Promise<boolean> {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return false;

	try {
		const registro = await navigator.serviceWorker.ready;

		if (!('sync' in registro)) return false;

		await (registro as ServiceWorkerRegistration & {
			sync: { register: (etiqueta: string) => Promise<void> };
		}).sync.register(ETIQUETA_SYNC);

		return true;
	} catch {
		return false;
	}
}
