import raw from './data/rufe-sismo-2026-08-10.json';

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
}

/**
 * Snapshot estático generado desde el consolidado RUFE (FR-1703-SMD-69) por
 * scripts/build_data.py. El día que haya acceso de lectura a la hoja de
 * Google en línea, este módulo es el único punto a cambiar por un fetch —
 * el resto de la app consume `DATA` sin saber de dónde vino.
 */
export const DATA: Dataset = raw as Dataset;
