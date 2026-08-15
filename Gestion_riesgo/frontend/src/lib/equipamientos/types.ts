export type VisitaEstado = 'Sí' | 'No' | 'Por confirmar' | 'Sin dato';
export type InformeEstado = 'Sí' | 'No' | 'Sin dato';

/**
 * Un renglón reportado dentro de una categoría de equipamiento. La mayoría
 * son un equipamiento puntual con nombre propio ("Biblioteca Municipal"),
 * pero algunas filas de la hoja reportan varias unidades juntas sin
 * desglosar nombre (p. ej. "6 EN VERIFICACIÓN" dentro de Centros de
 * Desarrollo) — esas quedan con `sinDetalle: true` y `cantidad` > 1 en vez
 * de inventarles un nombre individual.
 */
export interface EquipamientoItem {
	categoria: string;
	nombre: string;
	estado: string;
	cantidad: number;
	sinDetalle: boolean;
	visita: VisitaEstado;
	informe: InformeEstado;
}

export interface EquipamientoCategoria {
	nombre: string;
	/** Suma de las celdas TOTAL numéricas vistas para esta categoría (puede
	 * incluir varias, p. ej. 2 nombrados + 6 adicionales en verificación). */
	totalReportado: number | null;
	/** Texto libre en vez de un número en la celda TOTAL (p. ej. Geriátricos:
	 * "En verificación por parte de Secretaría de Salud"). */
	nota?: string;
}

export interface EquipamientosDataset {
	asOf: string;
	items: EquipamientoItem[];
	categorias: EquipamientoCategoria[];
	warnings?: string[];
}
