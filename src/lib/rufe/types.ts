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
	personas: number;
	estadoBien: string;
	tipoBien: string;
	tenencia: string;
	visita: 'SI' | 'NO' | 'Sin dato';
	quienVisita: string;
	observacion: string;
	evacuada: 'SI' | 'NO' | 'Sin dato';
}

export interface Dataset {
	total: number;
	asOf: string;
	barrios: Barrio[];
	hogares: Hogar[];
	/** Inconsistencias de zona detectadas al agregar (no detienen el parseo,
	 * pero conviene revisarlas en el CSV fuente cuando haya tiempo). */
	warnings?: string[];
}
