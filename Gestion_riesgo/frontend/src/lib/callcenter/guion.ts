// Cómo se lee el guión que la operadora tiene delante todo el turno.
//
// El guión se guarda como texto plano y lo edita el administrador desde un
// cuadro de texto corriente. Nada de markdown ni de un editor con botones: las
// tres personas que lo van a usar tienen que poder corregir una frase entre dos
// llamadas, y quien lo corrija no tiene por qué aprender un formato.
//
// De ahí las cinco marcas, que son las cinco cosas distintas que pasan en una
// llamada:
//
//   `## `  una sección
//   `» `   lo que se LEE en voz alta
//   `- `   una indicación para la operadora, que no se dice
//   `! `   lo que NO se debe decir ni prometer
//   `? `   una pregunta frecuente y su respuesta, separadas por «»»
//
// Una línea sin marca es texto suelto y se muestra igual. Eso es deliberado:
// un guión con una marca mal escrita se sigue leyendo entero. Rechazarlo por
// una tontería dejaría a la campaña sin guión a mitad de turno.

export type TipoLinea = 'decir' | 'hacer' | 'nunca' | 'pregunta' | 'texto';

export type LineaGuion = {
	tipo: TipoLinea;
	texto: string;
	/** Solo en las preguntas frecuentes: qué se responde. */
	respuesta?: string;
};

export type SeccionGuion = {
	titulo: string;
	lineas: LineaGuion[];
};

/** La marca que se dice en voz alta. Se exporta porque la usan el parser y las pruebas. */
const DECIR = '»';

/**
 * Parte el guión en secciones legibles.
 *
 * Todo lo que venga antes del primer `##` no se tira: cae en una sección sin
 * título. Perder texto porque alguien olvidó un encabezado sería perder algo
 * que se le dice a una familia por teléfono.
 */
export function leerGuion(cuerpo: string): SeccionGuion[] {
	const secciones: SeccionGuion[] = [];
	let actual: SeccionGuion | null = null;

	const asegurar = (): SeccionGuion => {
		if (actual === null) {
			actual = { titulo: '', lineas: [] };
			secciones.push(actual);
		}

		return actual;
	};

	for (const cruda of cuerpo.split('\n')) {
		const linea = cruda.trim();

		if (linea === '') continue;

		if (linea.startsWith('## ')) {
			actual = { titulo: linea.slice(3).trim(), lineas: [] };
			secciones.push(actual);
			continue;
		}

		if (linea.startsWith(`${DECIR} `)) {
			asegurar().lineas.push({ tipo: 'decir', texto: linea.slice(2).trim() });
			continue;
		}

		if (linea.startsWith('! ')) {
			asegurar().lineas.push({ tipo: 'nunca', texto: linea.slice(2).trim() });
			continue;
		}

		if (linea.startsWith('? ')) {
			// «pregunta » respuesta». Sin la marca de decir queda solo la
			// pregunta, que sigue siendo útil: dice qué le preguntan a uno.
			const resto = linea.slice(2).trim();
			const corte = resto.indexOf(DECIR);

			asegurar().lineas.push(
				corte === -1
					? { tipo: 'pregunta', texto: resto }
					: {
							tipo: 'pregunta',
							texto: resto.slice(0, corte).trim(),
							respuesta: resto.slice(corte + 1).trim()
						}
			);
			continue;
		}

		if (linea.startsWith('- ')) {
			asegurar().lineas.push({ tipo: 'hacer', texto: linea.slice(2).trim() });
			continue;
		}

		asegurar().lineas.push({ tipo: 'texto', texto: linea });
	}

	return secciones.filter((s) => s.titulo !== '' || s.lineas.length > 0);
}

/**
 * Cuántas frases del guión se leen en voz alta.
 *
 * Sirve para avisar en la pantalla de edición cuando alguien deja un guión sin
 * una sola frase para decir: eso no es un guión, son notas.
 */
export function frasesQueSeDicen(cuerpo: string): number {
	return leerGuion(cuerpo)
		.flatMap((s) => s.lineas)
		.filter((l) => l.tipo === 'decir' || (l.tipo === 'pregunta' && l.respuesta)).length;
}
