import type { PersonRecord } from '../rufe/parse';

export interface MergeResult {
	records: PersonRecord[];
	/** Cuántas personas se re-digitalizaron (aparecen en las dos hojas). */
	reDigitalizadas: number;
	warnings?: string[];
}

/**
 * Cruza los registros de BASE-DATOS RUFE con los del RUFE original, por
 * `documento` — la única llave que sirve entre las dos hojas, porque el
 * número de hogar se numera distinto en cada una (ver `parse.ts`).
 *
 * Regla acordada con la Alcaldía (2026-08-15): el RUFE original lo
 * digitalizaron personas; BASE-DATOS RUFE es la continuación de esa
 * digitalización, ahora con apoyo de IA. Para alguien que aparece en las
 * dos:
 *   - Ganan los datos de BASE-DATOS RUFE (hogar, corregimiento/barrio,
 *     zona, tenencia, estado/tipo de bien, observación) — el hogar viejo
 *     del RUFE original se descarta, ya no es relevante.
 *   - Se conservan Evacuada/Visita técnica/Quién realizó la visita del
 *     RUFE original, porque BASE-DATOS RUFE no las capta — perderlas
 *     sería más grave que dejarlas desactualizadas.
 *
 * Un efecto secundario esperado: si de un hogar del RUFE original solo
 * ALGUNOS integrantes fueron re-digitalizados, ese hogar original queda
 * con menos integrantes de los reales (los re-digitalizados ahora
 * pertenecen a su hogar nuevo). No es un bug — es la consecuencia directa
 * de que el hogar viejo ya no es relevante para esas personas — pero
 * queda registrado en `warnings` para que se pueda revisar.
 */
export function mergeConBaseDatosRufe(
	originalRecords: PersonRecord[],
	nuevosRecords: PersonRecord[]
): MergeResult {
	const originalPorDoc = new Map<string, PersonRecord>();
	for (const r of originalRecords) {
		if (r.documento) originalPorDoc.set(r.documento, r);
	}

	const documentosFusionados = new Set<string>();
	const records: PersonRecord[] = [];

	for (const nuevo of nuevosRecords) {
		const original = nuevo.documento ? originalPorDoc.get(nuevo.documento) : undefined;
		if (original && nuevo.documento) documentosFusionados.add(nuevo.documento);
		records.push(fusionarPersona(original, nuevo));
	}

	for (const original of originalRecords) {
		if (!original.documento || !documentosFusionados.has(original.documento)) {
			records.push(original);
		}
	}

	const warnings: string[] = [];
	if (documentosFusionados.size > 0) {
		warnings.push(
			`${documentosFusionados.size} persona(s) re-digitalizada(s) en BASE-DATOS RUFE: se ` +
				'actualizaron sus datos de bien/tenencia/zona y se reasignaron a un hogar nuevo — el ' +
				'hogar original al que pertenecían puede haber quedado con menos integrantes de los ' +
				'reales si sus demás compañeros de hogar aún no fueron re-digitalizados.'
		);
	}

	return {
		records,
		reDigitalizadas: documentosFusionados.size,
		...(warnings.length ? { warnings } : {})
	};
}

function fusionarPersona(original: PersonRecord | undefined, nuevo: PersonRecord): PersonRecord {
	if (!original) return nuevo;
	return {
		...nuevo,
		// BASE-DATOS RUFE no capta esto: se conserva lo que ya se sabía.
		visita: original.visita,
		quienVisita: original.quienVisita,
		evacuada: original.evacuada,
		// Si BASE-DATOS RUFE no trajo edad/género para esta fila en
		// particular (blanco), no se pierde lo que ya se sabía.
		genero: nuevo.genero ?? original.genero,
		edad: nuevo.edad ?? original.edad
	};
}
