import barrioTabsData from './barrioTabs.json';

/**
 * "BASE-DATOS RUFE — Sismo Jamundí": una hoja de cálculo DISTINTA a la del
 * RUFE original (`$lib/rufe/source.ts`), donde cada pestaña es un barrio o
 * vereda con el mismo encabezado. Es la continuación de la digitalización
 * del RUFE, ahora con apoyo de IA — se van agregando pestañas nuevas a
 * medida que se digitaliza cada barrio.
 *
 * Igual que con Instituciones Educativas/Equipamientos, el export CSV con
 * `gid` es CORS-permisivo (confirmado con curl), pero la lista de pestañas
 * en sí NO se puede leer desde el navegador (la página /edit de Google
 * Sheets no tiene CORS abierto), así que esta lista vive en
 * `barrioTabs.json` en vez de acá directamente — así puede reescribirla sola
 * `scripts/check-nuevas-pestanas.ts`, que corre por hora vía
 * `.github/workflows/check-nuevas-pestanas.yml` y comitea + dispara el
 * despliegue cuando aparece una pestaña nueva. No hace falta tocar nada a
 * mano al agregar un barrio — ver ese workflow para el detalle.
 */
export const BASE_DATOS_SHEET_ID = '1kXXZqZow7UgbqW44UMz76FjELBZz1TRpT4hbtFHoby4';

export interface BarrioTab {
	/** Nombre del barrio/vereda tal como aparece en la pestaña de Google Sheets. */
	nombre: string;
	gid: string;
}

export const BARRIO_TABS: BarrioTab[] = barrioTabsData;

export function csvUrlFor(gid: string): string {
	return `https://docs.google.com/spreadsheets/d/${BASE_DATOS_SHEET_ID}/export?format=csv&gid=${gid}`;
}
