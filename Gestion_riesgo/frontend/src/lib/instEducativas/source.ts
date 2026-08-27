import { SHEET_ID } from '../hojaExterna';

/**
 * Pestaña "INST EDUCATIVAS" de la misma hoja del RUFE. El export CSV
 * genérico (`/export?format=csv`, sin `gid`) siempre trae la primera
 * pestaña — para leer una pestaña distinta hace falta su `gid`, que Google
 * no expone en ningún endpoint público: se obtuvo mirando el HTML de la
 * hoja (`docs-sheet-tab-caption`) el 2026-08-15. Igual que el export por
 * defecto, este redirige a `…googleusercontent.com` con
 * `access-control-allow-origin: *` (confirmado con curl), así que también
 * se puede leer con `fetch()` directo desde el navegador.
 */
export const GID_INST_EDUCATIVAS = '355460979';
export const INST_EDUCATIVAS_CSV_URL = `https://docs.google.com/spreadsheets/d/${SHEET_ID}/export?format=csv&gid=${GID_INST_EDUCATIVAS}`;
