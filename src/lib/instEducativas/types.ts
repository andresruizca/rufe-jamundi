import type { Zona } from '../rufe/types';

export type SiNo = 'Sí' | 'No' | 'Sin dato';
export type Evacuacion = 'Sí' | 'No' | 'Parcial' | 'Sin dato';
export type ConceptoTecnico = 'Sí' | 'No' | 'En proceso' | 'Sin dato';
export type Prioridad = 'Inmediata' | 'Alta' | 'Media' | 'Baja' | 'Sin dato';

/** Una fila por sede educativa (una institución puede tener varias sedes). */
export interface Sede {
	establecimiento: string;
	sede: string;
	zona: Zona;
	direccion: string;
	barrio: string;
	matricula: number;
	estadoFisico: string;
	estudiantesAfectados: number;
	usadaComoAlbergue: SiNo;
	viasDeAcceso: SiNo;
	suspendieronClases: SiNo;
	danosObservados: string;
	accionesEtc: string;
	conceptoTecnico: ConceptoTecnico;
	requiereEvacuacion: Evacuacion;
	prioridad: Prioridad;
	requiereVisitaTecnica: SiNo;
	observaciones: string;
}

export interface InstEducativasDataset {
	asOf: string;
	sedes: Sede[];
	warnings?: string[];
}
