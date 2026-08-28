// El hogar que el censo ya tiene, y lo que el ciudadano hace con él.
//
// Cuando la cédula está en el RUFE, el formulario deja de pedir a ciegas lo que
// un funcionario ya levantó con la casa delante: le enseña a la familia lo que
// hay y le deja decir qué cambió. Quien nació, quién se fue, qué apellido quedó
// mal escrito.
//
// Nada de esto corrige el censo. Lo que la persona deja es una PROPUESTA que
// mira un funcionario. El servidor vuelve a comparar contra `rufe_personas` al
// recibir el envío, así que lo que se calcule aquí es para la pantalla, no para
// la decisión.

/** Una persona tal como la manda el servidor al precargar. */
export type PersonaCenso = {
	id: number;
	nombres: string;
	apellidos: string;
	tipo_documento: number;
	numero_documento: string;
	parentesco: number;
	genero: number;
	fecha_nacimiento: string;
};

export type HogarCenso = {
	reporte_id: number;
	zona: 'URBANA' | 'RURAL';
	corregimiento: string;
	vereda: string;
	direccion: string;
	telefono: string;
	/** Cuál de las personas es quien está escribiendo. */
	persona_id: number;
	personas: PersonaCenso[];
};

/** Una persona en el formulario, ya editable. */
export type PersonaHogar = {
	/** Estable mientras dura el formulario: es la clave del {#each}. */
	uid: string;
	/** De qué persona del censo salió. `null` si la agregó el ciudadano. */
	rufe_persona_id: number | null;
	nombres: string;
	apellidos: string;
	tipo_documento: number | null;
	numero_documento: string;
	parentesco: number | null;
	genero: number | null;
	fecha_nacimiento: string;
	/**
	 * El ciudadano dice que esta persona ya no vive ahí.
	 *
	 * No se borra la fila: se marca. Quitar de un clic a alguien del censo de
	 * damnificados —y perder que alguna vez estuvo— no debería poder hacerse sin
	 * que un funcionario lo mire.
	 */
	no_vive_aqui: boolean;
};

let siguiente = 0;

function uid(): string {
	siguiente += 1;

	return `p${siguiente}`;
}

/** Convierte lo que trajo el censo en filas editables. */
export function desdeCenso(personas: PersonaCenso[]): PersonaHogar[] {
	return personas.map((p) => ({
		uid: uid(),
		rufe_persona_id: p.id,
		nombres: p.nombres,
		apellidos: p.apellidos,
		tipo_documento: p.tipo_documento || null,
		numero_documento: p.numero_documento,
		parentesco: p.parentesco || null,
		genero: p.genero || null,
		fecha_nacimiento: p.fecha_nacimiento,
		no_vive_aqui: false
	}));
}

export function personaVacia(): PersonaHogar {
	return {
		uid: uid(),
		rufe_persona_id: null,
		nombres: '',
		apellidos: '',
		tipo_documento: null,
		numero_documento: '',
		parentesco: null,
		genero: null,
		fecha_nacimiento: '',
		no_vive_aqui: false
	};
}

/**
 * ¿Se puede quitar esta fila de la lista, sin más?
 *
 * Solo las que agregó el ciudadano en esta misma sesión: nadie ha revisado
 * todavía que existan. Las que vinieron del censo se marcan como «ya no vive
 * aquí», que es una afirmación que queda escrita, no una desaparición.
 */
export function sePuedeQuitar(p: PersonaHogar): boolean {
	return p.rufe_persona_id === null;
}

/**
 * ¿En qué cambió esta fila respecto del censo?
 *
 * Para la pantalla, no para la decisión: quien decide es el servidor, que
 * vuelve a comparar contra la base al recibir el envío. Aquí sirve para que la
 * persona vea cuáles de sus datos está cambiando antes de mandarlos.
 */
export function estaCorregida(p: PersonaHogar, censo: PersonaCenso[]): boolean {
	if (p.rufe_persona_id === null) return false;

	const original = censo.find((c) => c.id === p.rufe_persona_id);
	if (!original) return false;

	const igual = (a: string, b: string) =>
		a.trim().replace(/\s+/g, ' ').toLowerCase() === b.trim().replace(/\s+/g, ' ').toLowerCase();

	return (
		!igual(p.nombres, original.nombres) ||
		!igual(p.apellidos, original.apellidos) ||
		!igual(p.numero_documento, original.numero_documento) ||
		!igual(p.fecha_nacimiento, original.fecha_nacimiento) ||
		(p.tipo_documento ?? 0) !== original.tipo_documento ||
		(p.parentesco ?? 0) !== original.parentesco ||
		(p.genero ?? 0) !== original.genero
	);
}

/** Lo que viaja al servidor. El `uid` se queda aquí: es cosa de la pantalla. */
export function personasParaEnviar(personas: PersonaHogar[]): Record<string, unknown>[] {
	return personas
		.filter((p) => p.nombres.trim() !== '' || p.apellidos.trim() !== '')
		.map((p) => ({
			rufe_persona_id: p.rufe_persona_id,
			nombres: p.nombres,
			apellidos: p.apellidos,
			tipo_documento: p.tipo_documento,
			numero_documento: p.numero_documento,
			parentesco: p.parentesco,
			genero: p.genero,
			fecha_nacimiento: p.fecha_nacimiento,
			no_vive_aqui: p.no_vive_aqui
		}));
}
