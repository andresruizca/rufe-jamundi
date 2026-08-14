import raw from './data/rufe-fallback.json';
import type { Dataset } from './rufe/types';

export type { Zona, Barrio, Hogar, Dataset } from './rufe/types';

/**
 * Snapshot de respaldo, generado por `scripts/refresh-snapshot.ts` a partir
 * de la hoja del RUFE. Se usa como primer render (para no mostrar la
 * pantalla en blanco mientras carga) y como respaldo si el fetch en vivo
 * falla (ver `src/lib/rufe/live.ts`) — no es la fuente de verdad, la hoja de
 * Google en línea sí lo es.
 */
export const FALLBACK_DATA: Dataset = raw as Dataset;
