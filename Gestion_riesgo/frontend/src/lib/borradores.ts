// La caja donde viven los formularios a medias, para los dos formatos.
//
// Existe porque el mismo problema apareció dos veces. Los dos formularios
// —RUFE e inspección de vivienda— guardaban UN borrador, y la pantalla decía
// «hay una ficha sin terminar» sin decir de quién. Lo que hace una brigada en
// una mañana es dejar una casa a medias porque falta hablar con alguien y
// seguir con la de al lado: con un solo borrador, la segunda pisa la primera, y
// como no tiene nombre, la única forma de saber qué se va a perder es abrirla.
//
// Aquí vive lo que es idéntico en los dos: guardar varios, ordenarlos por lo
// más reciente, purgar lo caducado y adoptar lo que quedara guardado con el
// formato anterior. Lo que NO es idéntico se queda en cada módulo: con qué
// nombre se reconoce una ficha, y qué campos no se persisten nunca.
//
// Es un archivo sin runas a propósito: así se puede probar como una función.

import { browser } from "$app/environment";

export type BorradorBase<Datos> = {
	version: number;
	clave: string;
	actualizado_en: number;
	expira_en: number;
	paso: string;
	datos: Datos;
};

export type OpcionesAlmacen = {
	/** Dónde se guarda la colección. */
	clave: string;
	/**
   * Dónde vivía el borrador único, antes de que cupieran varios.
   *
   * Se adopta y se retira. En el momento del despliegue puede haber alguien
   * con una ficha a medias en el teléfono, y perderla es la visita repetida
   * que todo esto existe para evitar.
   */
	claveAnterior?: string;
	version: number;
	diasVigencia: number;
};

export function uid(): string {
	if (
		typeof crypto !== "undefined" &&
		typeof crypto.randomUUID === "function"
	) {
		return crypto.randomUUID();
	}

	return `id-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * Cuánto hace que se tocó, en palabras.
 *
 * Con la hora exacta no basta: «11:40 a. m.» no dice si fue hoy. Y quien mira
 * la lista está decidiendo cuál retomar, que es una pregunta sobre hace cuánto,
 * no sobre qué hora era.
 */
export function haceCuanto(cuando: number, ahora = Date.now()): string {
	const minutos = Math.round((ahora - cuando) / 60_000);

	if (minutos < 1) return "hace un momento";
	if (minutos < 60) return `hace ${minutos} min`;

	const horas = Math.round(minutos / 60);
	if (horas < 24) return horas === 1 ? "hace 1 hora" : `hace ${horas} horas`;

	const dias = Math.round(horas / 24);
	if (dias === 1) return "ayer";

	return `hace ${dias} días`;
}

/** Cuándo deja de poder retomarse, para poder avisarlo antes de que pase. */
export function diasQueLeQuedan(expiraEn: number, ahora = Date.now()): number {
	return Math.max(0, Math.ceil((expiraEn - ahora) / 86400_000));
}

export type Almacen<D> = {
	/** Los vigentes, del más reciente al más antiguo. Purga lo caducado. */
	leer(ahora?: number): BorradorBase<D>[];
	/** Uno concreto, o `null` si ya no está o caducó. */
	leerUno(clave: string, ahora?: number): BorradorBase<D> | null;
	/** Guarda o reemplaza. `false` si el navegador no dejó escribir. */
	guardar(b: BorradorBase<D>, ahora?: number): boolean;
	/**
   * Descarta uno.
   *
   * NO borra sus fotos: viven en IndexedDB atadas a la misma clave y quien
   * llama tiene que encargarse. Se dice aquí porque olvidarlo deja megabytes
   * de fotos de casas ajenas en un aparato que se presta.
   */
	descartar(clave: string, ahora?: number): void;
	/** Cuánto dura un borrador nuevo, en milisegundos. */
	vigenciaMs: number;
};

export function crearAlmacen<D>(o: OpcionesAlmacen): Almacen<D> {
	const vigenciaMs = o.diasVigencia * 86400_000;

	function leerCrudo(clave: string): string | null {
		if (!browser) return null;

		try {
			return window.localStorage.getItem(clave);
		} catch {
			return null;
		}
	}

	function escribir(lista: BorradorBase<D>[]): boolean {
		if (!browser) return false;

		try {
			window.localStorage.setItem(o.clave, JSON.stringify(lista));

			return true;
		} catch {
			return false;
		}
	}

	function vigente(b: unknown, ahora: number): b is BorradorBase<D> {
		const c = b as BorradorBase<D>;

		return (
			!!c &&
			typeof c === "object" &&
			// Una versión anterior del formato tendría campos que ya no existen;
			// recuperarla dejaría la pantalla a medio pintar sin decir por qué.
			c.version === o.version &&
			typeof c.clave === "string" &&
			c.clave !== "" &&
			!!c.datos &&
			// Caducado: una ficha de hace más de una semana no se retoma, se vuelve
			// a hacer. Los daños de una vivienda cambian.
			typeof c.expira_en === "number" &&
			c.expira_en > ahora
		);
	}

	function adoptarLaVieja(ahora: number): BorradorBase<D>[] {
		if (!o.claveAnterior) return [];

		const crudo = leerCrudo(o.claveAnterior);
		if (!crudo) return [];

		try {
			const b = JSON.parse(crudo) as BorradorBase<D>;

			if (
				typeof b.clave !== "string" ||
				!b.datos ||
				typeof b.expira_en !== "number" ||
				b.expira_en <= ahora
			) {
				return [];
			}

			return [{ ...b, version: o.version }];
		} catch {
			return [];
		} finally {
			try {
				window.localStorage.removeItem(o.claveAnterior);
			} catch {
				/* si no se puede borrar, caducará solo */
			}
		}
	}

	function leer(ahora = Date.now()): BorradorBase<D>[] {
		if (!browser) return [];

		const crudo = leerCrudo(o.clave);
		let lista: BorradorBase<D>[] = [];

		if (crudo) {
			try {
				const leido = JSON.parse(crudo);
				lista = Array.isArray(leido)
					? leido.filter((b) => vigente(b, ahora))
					: [];
			} catch {
				lista = [];
			}
		} else {
			// La purga va en la lectura porque es lo único que ocurre siempre; en
			// una pantalla concreta dependería de que alguien pasara por ella.
			lista = adoptarLaVieja(ahora);
			if (lista.length > 0) escribir(lista);
		}

		return lista.sort((a, b) => b.actualizado_en - a.actualizado_en);
	}

	return {
		leer,
		leerUno: (clave, ahora = Date.now()) =>
			leer(ahora).find((b) => b.clave === clave) ?? null,
		guardar(b, ahora = Date.now()) {
			return escribir([b, ...leer(ahora).filter((x) => x.clave !== b.clave)]);
		},
		descartar(clave, ahora = Date.now()) {
			if (!browser) return;

			// `ahora` como en todos los demás. Era el único método que no lo
			// aceptaba, y eso lo dejaba atado al reloj real: una prueba con fecha
			// fija empezaba a fallar sola el día que sus borradores caducaban de
			// verdad, semanas después de escribirla y sin que nadie hubiera
			// tocado el código. Pasó.
			escribir(leer(ahora).filter((b) => b.clave !== clave));
		},
		vigenciaMs,
	};
}
