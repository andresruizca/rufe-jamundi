// Lo que el call center necesita saber de un hogar del censo.
//
// Es deliberadamente poco. La campaña consiste en marcar un número y explicar
// un formulario: el nombre de quien contesta, su teléfono y el barrio para
// confirmar que se habla con quien se cree. Nada del expediente —evidencias,
// observaciones, las cédulas del resto del hogar— llega hasta aquí, y el
// servidor tampoco lo manda.

export type ResumenCallCenter = {
	/** Hogares en el censo. El universo de la campaña. */
	total: number;
	/** Ya diligenciaron la preinscripción. Se sabe por el cruce de cédulas. */
	preinscritos: number;
	/**
	 * El ingeniero les descartó la solicitud por algo que se arregla.
	 *
	 * Es la cola más urgente que existe: son familias que ya hicieron el
	 * esfuerzo de llenar el formulario y se quedaron a un paso.
	 */
	por_subsanar: number;
	/** El ingeniero determinó que el caso no aplica. No se vuelven a llamar. */
	no_aplica: number;
	/** Se les llamó y aún no la diligencian. */
	contactados_sin_preinscribir: number;
	/** Nadie los ha llamado todavía. */
	sin_llamar: number;
	/** Quedaron para volver a llamar hoy o antes. */
	para_hoy: number;
	/** Sin teléfono en la ficha: por ahí no se les puede llegar. */
	sin_telefono: number;
};

export type GestionLlamada = {
	id: number;
	resultado: string;
	nota: string | null;
	proxima_llamada: string | null;
	enlace_enviado: number;
	usuario_email: string | null;
	creado_en: string;
};

export type HogarParaLlamar = {
	id: number;
	radicado: string;
	/** `null` cuando la ficha no registró jefe de hogar. Se dice, no se inventa. */
	nombre: string | null;
	telefono: string | null;
	zona: string;
	lugar: string;
	fecha_evento: string;
	/** Su solicitud está viva. Una descartada NO cuenta como preinscrita. */
	preinscrita: boolean;
	preinscripcion: { radicado: string; creado_en: string; estado: string } | null;
	/**
	 * Lo que decidió el ingeniero, si la descartó.
	 *
	 * `decirle` está escrito para leerse en voz alta: la operadora no tiene por
	 * qué traducir un código a una frase mientras la persona espera al teléfono.
	 */
	descarte: {
		motivo: string | null;
		etiqueta: string;
		llamar: boolean;
		decirle: string;
	} | null;
	/** La única razón para NO marcar este número. La decide el servidor. */
	no_llamar: boolean;
	/** Otra operadora lo tiene abierto ahora mismo. Es un aviso, no una reserva. */
	atendida: { quien: string; usuario_id: number | null; desde: string } | null;
	intentos: number;
	/** Ya se intentó tantas veces que conviene buscar otra vía. */
	agotado: boolean;
	ultima: {
		resultado: string;
		etiqueta: string;
		creado_en: string;
		nota: string | null;
		por: string | null;
	} | null;
	proxima_llamada: string | null;
};

/** Una fila de «quién está llamando a quién», tal como llega del servidor. */
export type AtencionEnCurso = {
	reporte_id: number;
	usuario_id: number | null;
	usuario_nombre: string | null;
	actualizado_en: string;
};

export type GuionVigente = {
	cuerpo: string;
	/** Nadie lo ha reescrito: es el que trae el sistema. */
	es_predeterminado: boolean;
	actualizado_en: string | null;
	por: string | null;
};

export type FiltroEstado =
	| 'pendiente'
	| 'subsanar'
	| 'reintentar'
	| 'contactado'
	| 'preinscrito'
	| 'no_aplica'
	| 'todos';

/**
 * Las pestañas, en el orden en que se trabaja una campaña.
 *
 * «Falta llamar» primero porque es el trabajo del día, y es donde abre la
 * pantalla. «Ya se preinscribieron» al final: es el resultado, no una tarea.
 */
export const PESTANAS: { valor: FiltroEstado; etiqueta: string; urgente?: boolean }[] = [
	{ valor: 'pendiente', etiqueta: 'Falta llamar' },
	// Antes que «volver a llamar hoy» a propósito: aquí hay familias que ya
	// llenaron el formulario entero y están a una foto de entrar. Perderlas por
	// no llamarlas sería perder el trabajo que ya hicieron ellas.
	{ valor: 'subsanar', etiqueta: 'Les faltó algo', urgente: true },
	{ valor: 'reintentar', etiqueta: 'Volver a llamar hoy' },
	{ valor: 'contactado', etiqueta: 'Ya se les llamó' },
	{ valor: 'preinscrito', etiqueta: 'Ya se preinscribieron' },
	{ valor: 'no_aplica', etiqueta: 'No aplica' },
	{ valor: 'todos', etiqueta: 'Todos' }
];

/** Cómo se pinta el estado de un hogar en la lista. */
export function estadoDe(h: HogarParaLlamar): {
	texto: string;
	clase: 'ok' | 'espera' | 'pendiente' | 'problema';
} {
	if (h.preinscrita) return { texto: 'Ya se preinscribió', clase: 'ok' };

	// El descarte manda sobre todo lo demás. Un hogar cuya solicitud rechazaron
	// puede tener diez llamadas anotadas y da igual: lo que la operadora tiene
	// que ver primero es qué decidió el ingeniero.
	if (h.descarte) {
		return h.descarte.llamar
			? { texto: h.descarte.etiqueta, clase: 'espera' }
			: { texto: 'No aplica · no llamar', clase: 'problema' };
	}

	if (h.ultima === null) return { texto: 'Sin llamar', clase: 'pendiente' };

	if (h.ultima.resultado === 'NUMERO_ERRADO') {
		return { texto: 'Número errado', clase: 'problema' };
	}

	if (h.ultima.resultado === 'NO_INTERESA') {
		return { texto: 'No quiere continuar', clase: 'problema' };
	}

	if (h.ultima.resultado === 'YA_DILIGENCIO') {
		// Lo DICE la persona, y el cruce por cédula no lo confirma. Casi siempre
		// significa que cerró el navegador a mitad: hay que volver a llamar.
		return { texto: 'Dice que ya, sin constancia', clase: 'espera' };
	}

	if (h.proxima_llamada) return { texto: 'Para volver a llamar', clase: 'espera' };

	return { texto: h.ultima.etiqueta, clase: 'espera' };
}

/**
 * El porcentaje de avance de la campaña.
 *
 * Sin censo no es 0%, es que no hay nada que medir: dibujar «0%» sobre cero
 * hogares parece un fracaso donde solo hay una base vacía.
 */
export function porcentaje(parte: number, total: number): string {
	if (total <= 0) return '—';

	return `${Math.round((parte / total) * 100)}%`;
}
