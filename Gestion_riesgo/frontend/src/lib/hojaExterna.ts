// La hoja de cálculo de la dependencia, para lo que TODAVÍA no está en la base.
//
// Aquí vivía el puente completo del tablero: el RUFE se leía en vivo desde esta
// hoja, en el navegador de cada persona. Eso se acabó — el censo lo sirve ahora
// la API desde MySQL, y con ello se cerró que el censo de damnificados de
// Jamundí fuera descargable por quien tuviera la URL de la hoja.
//
// Lo único que sigue leyéndose de aquí son dos pestañas cuyo censo nunca entró
// al sistema: instituciones educativas y equipamientos públicos afectados. Las
// dos lo dicen en pantalla, para que nadie confunda esa parte con dato oficial.
//
// Cuando ese censo entre a la base, este archivo desaparece.

export const SHEET_ID = '1bBOHvSd9taQohbtF-c6l3NVvV6KHe0gK';

/** El CSV de una pestaña concreta de la hoja. */
export function csvDeLaPestana(gid: string): string {
	return `https://docs.google.com/spreadsheets/d/${SHEET_ID}/export?format=csv&gid=${gid}`;
}
