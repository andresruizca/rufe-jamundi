// La caída entre una etapa del recorrido y la siguiente.
//
// ── Por qué esto vive aparte y con pruebas ───────────────────────────────────
//
// Porque es una división, y el denominador es un conteo que puede ser cero: un
// municipio que estrena el sistema, una base recién restaurada, la mañana
// siguiente a una emergencia nueva. Un `NaN%` o un `Infinity%` dibujado sobre
// el tablero de la Alcaldía el primer día de una emergencia es exactamente el
// momento en que nadie tiene tiempo de averiguar qué pasó.
//
// Y hay un segundo caso menos obvio: una etapa puede tener MÁS hogares que la
// anterior. Pasa de verdad —alguien se preinscribe por su cuenta, por el enlace
// que le pasó un vecino, sin que nadie lo haya llamado— y entonces «la caída»
// no es una caída. Decirlo así, y no pintar un menos delante de un número
// positivo.

import type { EtapaRecorrido } from '$lib/rufe/types';

export type Paso = {
	etapa: EtapaRecorrido;
	/**
	 * Qué pasó entre la etapa anterior y esta.
	 *
	 * `null` en la primera, que no tiene anterior, y también cuando la anterior
	 * está en cero: de cero no se cae a ninguna parte, y un «−100 %» ahí sería
	 * inventarse una fuga donde solo hay una base vacía.
	 */
	caida: number | null;
	/** Hay más aquí que en la etapa anterior. No es una fuga. */
	crece: boolean;
	/** Qué parte del censo llega hasta aquí. `null` si el censo está vacío. */
	delCenso: number | null;
};

/**
 * El recorrido con sus caídas ya calculadas.
 *
 * Se le pasa la lista tal como llega del servidor: el orden es suyo, y es el
 * orden en que se recorre. Reordenarla aquí convertiría un avance en una fuga.
 */
export function pasos(etapas: EtapaRecorrido[]): Paso[] {
	const censo = etapas[0]?.hogares ?? 0;

	return etapas.map((etapa, i) => {
		const anterior = i > 0 ? etapas[i - 1].hogares : null;

		return {
			etapa,
			caida: caidaEntre(anterior, etapa.hogares),
			crece: anterior !== null && etapa.hogares > anterior,
			delCenso: censo > 0 ? Math.round((etapa.hogares / censo) * 100) : null
		};
	});
}

/**
 * Qué porcentaje se pierde entre dos etapas.
 *
 * Devuelve un número positivo: es «cuánto se cae», no una variación con signo.
 * `null` cuando no hay nada de qué caerse.
 */
export function caidaEntre(anterior: number | null, actual: number): number | null {
	if (anterior === null || anterior <= 0) return null;
	if (actual >= anterior) return null;

	return Math.round(((anterior - actual) / anterior) * 100);
}
