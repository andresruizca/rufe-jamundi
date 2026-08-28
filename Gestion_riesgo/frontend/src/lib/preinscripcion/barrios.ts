// Buscar un barrio dentro de la lista de Planeación.
//
// Vive fuera del componente para poder probarlo: de estas dos funciones depende
// que alguien encuentre su propio barrio, y no encontrarlo es motivo suficiente
// para abandonar un formulario a la mitad.

/**
 * Sin tildes, sin mayúsculas y sin espacios de más.
 *
 * Quien escribe desde un celular casi nunca pone la tilde: si «Belalcazar» no
 * encontrara «Belalcázar», la lista sería un estorbo en vez de una ayuda.
 */
export function llano(texto: string): string {
	return texto
		.toLowerCase()
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.replace(/\s+/g, ' ')
		.trim();
}

/**
 * Los barrios que casan con lo escrito, en el orden en que sirven.
 *
 * Los que EMPIEZAN por lo escrito van primero: quien teclea «bel» está
 * buscando «Belalcázar», no «Nuevo Belén». Con la lista sin filtrar —el campo
 * vacío— se devuelve entera, que es lo que se quiere al abrirla de un toque.
 */
export function filtrarBarrios(opciones: string[], escrito: string): string[] {
	const buscado = llano(escrito);

	if (buscado === '') return opciones;

	const empiezan: string[] = [];
	const contienen: string[] = [];

	for (const o of opciones) {
		const plano = llano(o);

		if (plano.startsWith(buscado)) {
			empiezan.push(o);
		} else if (plano.includes(buscado)) {
			contienen.push(o);
		}
	}

	return [...empiezan, ...contienen];
}

/**
 * Lo escrito es exactamente uno de la lista.
 *
 * Decide si se le avisa a la persona de que su barrio quedará para revisión.
 * Es un aviso, no un error: la lista es de 2021 y en un municipio que crece por
 * invasión y por urbanizaciones nuevas siempre va a faltar alguno.
 */
export function estaEnLaLista(opciones: string[], escrito: string): boolean {
	const buscado = llano(escrito);

	return buscado !== '' && opciones.some((o) => llano(o) === buscado);
}
