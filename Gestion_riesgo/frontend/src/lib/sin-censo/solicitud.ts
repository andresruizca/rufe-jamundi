// La solicitud de quien la puerta de la cédula rechazó.
//
// Mismo espíritu que `preinscripcion/puerta.ts`: la validación vive aquí, no
// dentro del componente, para poder probarla sola. Y aquí también manda el
// servidor (`App\SinCenso\Validador`) — esto solo evita que alguien llene el
// formulario para enterarse del error un paso después.

export type ZonaSinCenso = 'URBANO' | 'RURAL';

export const ZONAS: ZonaSinCenso[] = ['URBANO', 'RURAL'];

const SOLO_LETRAS = /^[\p{L}\p{M}\s'.-]+$/u;

export type DatosSolicitudSinCenso = {
	// Separados y no en un solo «nombre completo»: son los mismos dos campos
	// de `Persona` en `rufe-form/tipos.ts`, para que si la solicitud se
	// convierte, el jefe de hogar se precargue tal cual, sin adivinar dónde
	// termina el nombre y empieza el apellido.
	nombres: string;
	apellidos: string;
	telefono: string;
	zona: ZonaSinCenso | '';
	corregimiento: string;
	vereda_sector_barrio: string;
	direccion: string;
	descripcion: string;
};

export function solicitudVacia(): DatosSolicitudSinCenso {
	return {
		nombres: '',
		apellidos: '',
		telefono: '',
		zona: '',
		corregimiento: '',
		vereda_sector_barrio: '',
		direccion: '',
		descripcion: ''
	};
}

const MAX_DESCRIPCION = 500;

/**
 * Qué le falta a la solicitud, si algo. Mismas reglas que
 * `App\SinCenso\Validador`: nombres y apellidos (como en `rufe_personas`),
 * teléfono, la zona, al menos una pista de dónde vive, y un tope en la
 * descripción.
 */
export function erroresSolicitud(d: DatosSolicitudSinCenso): Record<string, string> {
	const e: Record<string, string> = {};

	for (const [campo, valor, mensaje] of [
		['nombres', d.nombres, 'Escriba el nombre.'],
		['apellidos', d.apellidos, 'Escriba los apellidos.']
	] as const) {
		const v = valor.trim();
		if (v.length < 2 || v.length > 100) {
			e[campo] = mensaje;
		} else if (!SOLO_LETRAS.test(v)) {
			e[campo] = 'Use solo letras, espacios, apóstrofos, puntos o guiones.';
		}
	}

	const telefono = d.telefono.replace(/\D+/g, '');
	if (telefono.length < 7 || telefono.length > 15) {
		e.telefono = 'Escriba un teléfono donde podamos llamar.';
	}

	if (d.zona === '') {
		e.zona = 'Indique si vive en zona urbana o rural.';
	}

	if (!d.direccion.trim() && !d.vereda_sector_barrio.trim() && !d.corregimiento.trim()) {
		e.direccion = 'Díganos aunque sea de forma aproximada dónde vive.';
	}

	if (d.descripcion.length > MAX_DESCRIPCION) {
		e.descripcion = `Resuma en menos de ${MAX_DESCRIPCION} caracteres.`;
	}

	return e;
}

export function esValida(d: DatosSolicitudSinCenso): boolean {
	return Object.keys(erroresSolicitud(d)).length === 0;
}
