import { SHEET_ID } from '../rufe/source';

/**
 * Pestaña "EQUIPAMIENTOS" de la misma hoja del RUFE — mismo mecanismo que
 * `instEducativas/source.ts` (gid obtenido del HTML de la hoja el
 * 2026-08-15, endpoint CORS-permisivo confirmado con curl).
 */
export const GID_EQUIPAMIENTOS = '136428547';
export const EQUIPAMIENTOS_CSV_URL = `https://docs.google.com/spreadsheets/d/${SHEET_ID}/export?format=csv&gid=${GID_EQUIPAMIENTOS}`;
