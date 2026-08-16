import type { PersonRecord } from '../rufe/parse';

/** Resultado de parsear todas las pestañas (una por barrio) de la hoja
 * "BASE-DATOS RUFE". `documento` viaja aquí igual que en `PersonRecord` —
 * solo para el cruce con la hoja original, nunca sale hacia el `Dataset`
 * público que consume la UI (ver la nota de privacidad en `rufe/parse.ts`). */
export interface BaseDatosDataset {
	records: PersonRecord[];
	/** Cuántas filas trajo cada pestaña, para mostrar en el snapshot/consola
	 * al refrescar — no para la UI. */
	porBarrio: { barrio: string; personas: number }[];
	warnings?: string[];
}
