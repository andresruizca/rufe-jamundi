import raw from '../data/equipamientos-fallback.json';
import type { EquipamientosDataset } from './types';

/**
 * Snapshot de respaldo, generado por `scripts/refresh-equipamientos-snapshot.ts`.
 * Mismo rol que `$lib/data`'s `FALLBACK_DATA` — ver ese archivo.
 */
export const EQUIPAMIENTOS_FALLBACK: EquipamientosDataset = raw as EquipamientosDataset;
