// Qué se ha intentado y cuándo.
//
// Lo escribe `SyncWorker.kt` en cada intento; esto solo lo lee.
//
// Existe porque «se enviará en cuanto haya internet» es cierto pero no dice
// nada: quien lo lee tres horas después no sabe si el teléfono lo ha intentado
// siquiera. Y quien atiende el teléfono en la Alcaldía tampoco puede
// responderle.

import { abrir } from './base';

export type Anotacion = {
	cuando: string;
	resultado: 'INTENTO' | 'SIN_CONEXION' | 'ERROR' | 'ENVIADO';
	detalle: string | null;
};

/**
 * Los intentos de una solicitud, del más reciente al más antiguo.
 *
 * Con tope: una solicitud que lleva días fallando puede acumular decenas, y
 * enseñarlas todas convierte la pantalla en un muro de texto que nadie lee.
 */
export async function intentosDe(registroId: string, maximo = 12): Promise<Anotacion[]> {
	const db = await abrir();

	const r = await db.query(
		`SELECT cuando, resultado, detalle FROM bitacora
		  WHERE registro_id = ? ORDER BY cuando DESC LIMIT ?`,
		[registroId, maximo]
	);

	return (r.values ?? []) as Anotacion[];
}

/** Lo mismo para todo lo que sigue pendiente, para el panel del paso final. */
export async function ultimosIntentos(maximo = 8): Promise<(Anotacion & { radicado: string | null })[]> {
	const db = await abrir();

	const r = await db.query(
		`SELECT b.cuando, b.resultado, b.detalle, r.radicado
		   FROM bitacora b
		   JOIN registros r ON r.id = b.registro_id
		  WHERE r.estado <> 'BORRADOR'
		  ORDER BY b.cuando DESC
		  LIMIT ?`,
		[maximo]
	);

	return (r.values ?? []) as (Anotacion & { radicado: string | null })[];
}

/**
 * Cómo se le cuenta un intento a quien no sabe qué es sincronizar.
 *
 * «Sin conexión» no se dice como error: es lo normal en una vereda y no hay nada
 * que la persona tenga que hacer al respecto.
 */
export function comoSeLee(a: Anotacion): { texto: string; clase: 'bien' | 'espera' | 'mal' } {
	if (a.resultado === 'ENVIADO') {
		return {
			texto: a.detalle ? `Enviado · radicado ${a.detalle}` : 'Enviado',
			clase: 'bien'
		};
	}

	if (a.resultado === 'SIN_CONEXION') {
		return { texto: 'No había internet', clase: 'espera' };
	}

	if (a.resultado === 'ERROR') {
		return { texto: a.detalle ?? 'No se pudo enviar', clase: 'mal' };
	}

	return { texto: 'Intentando enviar…', clase: 'espera' };
}

/**
 * Fecha y hora como se dicen en Colombia.
 *
 * Con hora, siempre. Una solicitud puede intentarse varias veces el mismo día y
 * sin la hora las anotaciones se vuelven indistinguibles.
 */
export function cuandoSeLee(iso: string): string {
	// SQLite escribe `datetime('now')` en UTC y sin la Z. Sin añadirla, el
	// navegador lo interpreta como hora local y las horas salen corridas cinco
	// horas hacia atrás — un envío de las 8 de la noche se leería como de las 3
	// de la tarde.
	const utc = iso.includes('T') || iso.endsWith('Z') ? iso : `${iso.replace(' ', 'T')}Z`;

	return new Date(utc).toLocaleString('es-CO', {
		day: '2-digit',
		month: 'short',
		hour: 'numeric',
		minute: '2-digit'
	});
}
