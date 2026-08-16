import raw from '../data/inst-educativas-fallback.json';
import type { InstEducativasDataset } from './types';

/**
 * Snapshot de respaldo, generado por `scripts/refresh-inst-educativas-snapshot.ts`.
 * Mismo rol que `$lib/data`'s `FALLBACK_DATA` para el dataset de personas —
 * ver ese archivo.
 */
export const INST_EDUCATIVAS_FALLBACK: InstEducativasDataset = raw as InstEducativasDataset;
