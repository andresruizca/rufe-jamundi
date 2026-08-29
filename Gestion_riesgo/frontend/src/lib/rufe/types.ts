export type Zona = 'Urbana' | 'Rural';

export interface Barrio {
	name: string;
	total: number;
	M: number;
	F: number;
	Ninos: number;
	Jovenes: number;
	Adultos: number;
	AdultosMayores: number;
	zona: Zona;
}

/** Una fila por hogar (no por persona) — estado/tipo de bien, visita y
 * observación son datos de la vivienda, no de cada integrante, así que
 * contarlos por persona los infla según el tamaño del hogar. */
export interface Hogar {
	hogar: string;
	barrio: string;
	zona: Zona;
	/** Cuántas personas de `barrios[].total` pertenecen a este hogar — para
	 * poder responder "cuántas PERSONAS fueron evacuadas", no solo cuántos
	 * hogares. */
	/**
	 * Dirección del predio tal como está escrita en el censo.
	 *
	 * La consume la sección Mapas, que lee el censo de esta misma respuesta: es
	 * lo único con lo que puede ubicar un predio. El tablero no la usa.
	 */
	direccion: string;
	/** Cuántas personas del barrio pertenecen a este hogar. */
	personas: number;
	estadoBien: string;
	tipoBien: string;
	tenencia: string;
	visita: 'SI' | 'NO' | 'Sin dato';
	quienVisita: string;
	observacion: string;
	evacuada: 'SI' | 'NO' | 'Sin dato';
}

/**
 * Una etapa del camino que recorre una familia damnificada.
 *
 * El servidor manda el nombre y el pie junto con la cifra, y no solo el
 * número: lo que significa cada etapa lo decide la regla de negocio que vive
 * en `Recorrido`, no el orden en que alguien las escribió en la pantalla.
 */
export interface EtapaRecorrido {
	clave: string;
	nombre: string;
	pie: string;
	hogares: number;
}

/** Trabajo pendiente con nombre, cifra y la pantalla donde se resuelve. */
export interface Atasco {
	clave: string;
	nombre: string;
	pie: string;
	valor: number;
	/** A dónde lleva. Un atasco sin ruta es una alarma que no dice dónde ir. */
	ruta: string;
	nivel: 'critico' | 'aviso';
}

export interface Dataset {
	total: number;
	asOf: string;
	barrios: Barrio[];
	hogares: Hogar[];
	/** Las cinco etapas, en el orden en que se recorren. */
	recorrido?: EtapaRecorrido[];
	atascos?: Atasco[];
	/** Inconsistencias de zona detectadas al agregar (no detienen el parseo,
	 * pero conviene revisarlas en el CSV fuente cuando haya tiempo). */
	warnings?: string[];
}
