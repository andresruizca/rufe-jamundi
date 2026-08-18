// Qué se pinta en el mapa y con qué color.
//
// Se separa de la página para poder comprobarlo sin navegador: lo delicado no es
// dibujar, es decidir qué punto merece dibujarse. Un mapa que pinta lo que no
// sabe engaña, y este en concreto se usa para decidir a dónde va la ayuda.

import type { Hogar } from '$lib/rufe/types';

/** Una ubicación tal como la devuelve la API. */
export type Ubicacion = {
	lat: number;
	lon: number;
	precision: 'EXACTA' | 'CALLE' | 'BARRIO' | 'MUNICIPIO' | 'FALLIDA';
	fuente: string | null;
};

/** Un hogar ya ubicado, listo para dibujar. */
export type PuntoHogar = {
	hogar: string;
	barrio: string;
	zona: string;
	direccion: string;
	personas: number;
	estadoBien: string;
	lat: number;
	lon: number;
	precision: Ubicacion['precision'];
};

/**
 * Colores del estado del bien, los mismos de los planos que ya imprimió la
 * Alcaldía, para que quien tenga los dos delante lea lo mismo.
 */
export const COLOR_ESTADO: Record<string, string> = {
	Destruido: '#b5322a',
	'No habitable': '#c2258f',
	Averiado: '#e08a1e',
	Habitable: '#2f9e44',
	'No informa': '#5c6b7a'
};

export const COLOR_SIN_DATO = '#5c6b7a';

export function colorDe(estadoBien: string): string {
	return COLOR_ESTADO[estadoBien] ?? COLOR_SIN_DATO;
}

/**
 * Solo estas precisiones se dibujan.
 *
 * `MUNICIPIO` significa que el geocodificador contestó «Jamundí» y no la
 * dirección pedida. Son coordenadas válidas y del todo inútiles: pintarlas
 * amontonaría cientos de hogares sobre el parque principal e inventaría una
 * zona de calor donde no la hay.
 */
export function ubicable(u: Ubicacion | undefined): u is Ubicacion {
	return u !== undefined && (u.precision === 'EXACTA' || u.precision === 'CALLE' || u.precision === 'BARRIO');
}

/** Las direcciones distintas que hay que preguntarle a la API. */
export function direccionesDe(hogares: Hogar[]): string[] {
	const vistas = new Set<string>();

	for (const h of hogares) {
		const d = h.direccion.trim();
		if (d !== '') vistas.add(d);
	}

	return [...vistas];
}

/** Cruza los hogares con las ubicaciones conocidas. */
export function puntosDe(
	hogares: Hogar[],
	ubicaciones: Record<string, Ubicacion>
): { puntos: PuntoHogar[]; sinUbicar: Hogar[] } {
	const puntos: PuntoHogar[] = [];
	const sinUbicar: Hogar[] = [];

	for (const h of hogares) {
		const u = ubicaciones[h.direccion.trim()];

		if (!ubicable(u)) {
			sinUbicar.push(h);
			continue;
		}

		puntos.push({
			hogar: h.hogar,
			barrio: h.barrio,
			zona: h.zona,
			direccion: h.direccion,
			personas: h.personas,
			estadoBien: h.estadoBien || 'No informa',
			lat: u.lat,
			lon: u.lon,
			precision: u.precision
		});
	}

	return { puntos, sinUbicar };
}

/**
 * Los puntos que alimentan la capa de calor, con su intensidad.
 *
 * La intensidad es cuánta gente vive en el hogar, no «uno por marcador»: un
 * hogar de nueve personas pesa más que uno de una para decidir a dónde mandar
 * la ayuda. Se normaliza contra el hogar más numeroso para que la escala no
 * dependa del tamaño absoluto del censo.
 */
export function calorDe(puntos: PuntoHogar[]): [number, number, number][] {
	if (puntos.length === 0) return [];

	const mayor = Math.max(...puntos.map((p) => p.personas), 1);

	return puntos.map((p) => [p.lat, p.lon, Math.max(p.personas / mayor, 0.15)]);
}

/** Centro del casco urbano de Jamundí, para abrir el mapa antes de tener datos. */
export const CENTRO_JAMUNDI: [number, number] = [3.2611, -76.5423];
