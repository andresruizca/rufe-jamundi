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

export interface Dataset {
	total: number;
	asOf: string;
	barrios: Barrio[];
	/** Inconsistencias de zona detectadas al agregar (no detienen el parseo,
	 * pero conviene revisarlas en el CSV fuente cuando haya tiempo). */
	warnings?: string[];
}
