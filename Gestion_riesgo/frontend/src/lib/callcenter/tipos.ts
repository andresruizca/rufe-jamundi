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
	/**
	 * Terminaron: tienen la inspección de vivienda APROBADA.
	 *
	 * Es la única cifra que mide el final del camino, y los únicos hogares a
	 * los que ya no hay que llamar. Estar en el RUFE es el requisito para que
	 * le hagan la inspección; llenar el formulario es pedir el turno. Ninguna
	 * de las dos cosas es haber recibido ayuda.
	 */
	terminados: number;
	/**
	 * Pidieron el turno y esperan al ingeniero.
	 *
	 * Se sabe por el cruce de cédulas. NO están fuera de la campaña: a quien
	 * espera la visita todavía le puede faltar evidencia, o pueden no
	 * encontrarlo en la dirección.
	 */
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
	/** Se les llamó y siguen en la campaña. */
	contactados_sin_preinscribir: number;
	/** Nadie los ha llamado todavía y siguen en la campaña. */
	sin_llamar: number;
	/** Quedaron para volver a llamar hoy o antes. */
	para_hoy: number;
	/** Sin teléfono en la ficha: por ahí no se les puede llegar. */
	sin_telefono: number;
};

export type GestionLlamada = {
	id: number;
	/**
	 * Por qué vía se hizo. `LLAMADA` en todo lo anterior al botón de WhatsApp.
	 *
	 * Importa para el historial: un envío no es una llamada, y contarlo como
	 * tal daría por agotado un hogar con el que nadie ha hablado.
	 */
	canal: 'LLAMADA' | 'WHATSAPP';
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
	/**
	 * La cédula del jefe de hogar, para dictársela.
	 *
	 * El formulario ciudadano ABRE pidiéndola: sin ella la persona no pasa de
	 * la primera pantalla. La operadora la estaba buscando en otra pestaña —o
	 * colgando— mientras la tenía al teléfono.
	 *
	 * `null` si la ficha no la trae. Se dice, no se inventa: una cédula
	 * equivocada dictada por teléfono manda a esa familia a un formulario que
	 * la va a rechazar.
	 */
	documento: string | null;
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
	/**
	 * Terminó: el ingeniero le aprobó la inspección de vivienda.
	 *
	 * Es lo único que saca a un hogar de la campaña por haber llegado al final.
	 * Todo lo demás —estar en el RUFE, haber llenado el formulario— son etapas
	 * del camino.
	 */
	inspeccion: { numero: string; fecha: string } | null;
	/** Las razones para NO marcar este número. Las decide el servidor. */
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
	| 'terminado'
	| 'subsanar'
	| 'reintentar'
	| 'contactado'
	| 'preinscrito'
	| 'no_aplica'
	| 'sin_telefono'
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
	// El final del camino, y lo único que saca a un hogar de la campaña por
	// haber llegado hasta el final. Va después de las etapas, no entre ellas.
	{ valor: 'terminado', etiqueta: 'Inspección aprobada' },
	{ valor: 'no_aplica', etiqueta: 'No aplica' },
	// Por teléfono a esta gente no se llega. La cola existe para poder sacarla y
	// buscarla por otra vía —el promotor del barrio, la junta de acción comunal—,
	// que es lo único que queda cuando no hay número que marcar.
	{ valor: 'sin_telefono', etiqueta: 'Sin teléfono' },
	{ valor: 'todos', etiqueta: 'Todos' }
];

/**
 * Qué cola abre cada tarjeta del resumen.
 *
 * ── Por qué es un dato y no nueve `onclick` ──────────────────────────────────
 *
 * Porque así se puede comprobar. La promesa de la pantalla es que la cifra de
 * la tarjeta sea EXACTAMENTE el conteo de la lista que abre; el día que una
 * tarjeta diga 412 y salgan 380, la operadora deja de creerle al tablero y no
 * hay forma de recuperarlo. Escrito como tabla, una prueba recorre las nueve.
 *
 * Las claves son las del `ResumenCallCenter` del servidor, y allí existe la
 * misma tabla (`CallCenterController::COLA_DE_CIFRA`) decidiendo con qué
 * condición se suma cada una. Las dos tienen que decir lo mismo.
 */
export const COLA_DE_CIFRA: Record<keyof ResumenCallCenter, FiltroEstado> = {
	total: 'todos',
	terminados: 'terminado',
	preinscritos: 'preinscrito',
	por_subsanar: 'subsanar',
	no_aplica: 'no_aplica',
	contactados_sin_preinscribir: 'contactado',
	sin_llamar: 'pendiente',
	para_hoy: 'reintentar',
	sin_telefono: 'sin_telefono'
};

/** Cómo se pinta el estado de un hogar en la lista. */
export function estadoDe(h: HogarParaLlamar): {
	texto: string;
	clase: 'ok' | 'espera' | 'pendiente' | 'problema';
} {
	// El final del camino, y lo primero que hay que ver: es el único hogar al
	// que ya no hay que llamar por haber terminado. Va ANTES de «se preinscribió»
	// porque quien tiene la inspección aprobada también se preinscribió, y lo
	// que importa es lo segundo.
	if (h.inspeccion) return { texto: 'Inspección aprobada', clase: 'ok' };

	// Pidió el turno. No es el final: sigue esperando al ingeniero, y hasta que
	// llegue le puede faltar evidencia o pueden no encontrarlo en la dirección.
	if (h.preinscrita) return { texto: 'Espera la inspección', clase: 'espera' };

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


/**
 * Un WhatsApp que se le mandó a un hogar.
 *
 * Se dibuja debajo del número, nada más abrir la llamada. Es lo que sustituyó
 * al bloqueo de 24 horas: con tres operadoras sobre la misma lista, el freno
 * útil no es prohibir el reenvío, es que se vea lo que ya se mandó antes de
 * volver a mandarlo.
 */
export type EnvioWhatsapp = {
	cuando: string;
	/** Salió de verdad. `false` es un intento que el proveedor rechazó. */
	ok: boolean;
	/** Quién lo mandó. Entre tres operadoras, «ya se envió» sin decir quién obliga a preguntar. */
	quien: string | null;
	/** Por qué falló. Un número que no existe en WhatsApp hay que saberlo, no reintentarlo cinco veces. */
	error: string | null;
};
