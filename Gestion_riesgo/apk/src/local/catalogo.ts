// El catálogo que el formulario necesita para dibujarse.
//
// Va EMBEBIDO porque el APK tiene que funcionar sin haber visto internet nunca:
// alguien recibe el archivo por Bluetooth en una vereda, lo instala y llena el
// formulario. Pedirle el catálogo al servidor en ese momento sería pedirle lo
// único que no tiene.
//
// Cuando hay señal se refresca y se guarda en SQLite, así que un APK que lleva
// meses instalado se pone al día solo la primera vez que alcanza cobertura.
//
// ⚠ Este archivo es una FOTO de `/preinscripcion/catalogos` del 26 de agosto de 2026. Si el
// servidor cambia y el APK no, un teléfono viejo puede mandar códigos que ya no
// existan — y el validador rechaza el envío ENTERO, no el campo. Por eso
// `docs/servidor-requerido.md` pide que de `Senales::CATALOGO` solo se añada y
// nunca se quite.

import { abrir, guardarAjuste, leerAjuste } from './base';

export type Senal = { codigo: string; etiqueta: string; ayuda: string; icono: string };

export type CategoriaVideo = {
	id: number;
	nombre: string;
	instruccion: string | null;
	/** El daño al que responde: solo se le pide a quien lo marcó. */
	senal: string | null;
	obligatoria: boolean;
	segundos_min: number;
	segundos_max: number;
};

export type Catalogo = {
	zonas: string[];
	corregimientos: string[];
	senales: Senal[];
	categorias_video: CategoriaVideo[];
	aviso_version: string;
};

/** La foto embebida. Lo que se usa mientras no haya habido señal nunca. */
export const CATALOGO_EMBEBIDO: Catalogo = {
	zonas: [
		"URBANA",
		"RURAL"
	],
	corregimientos: [
		"Ampudia",
		"Bocas del Palo",
		"Chagres",
		"Guachinte",
		"La Liberia",
		"La Meseta",
		"La Ventura",
		"Paso de la Bolsa",
		"Potrerito",
		"Puente Vélez",
		"Quinamayó",
		"Robles",
		"San Antonio",
		"San Vicente",
		"Timba",
		"Villa Colombia",
		"Villapaz"
	],
	senales: [
		{
			"codigo": "PARED_AGRIETADA",
			"etiqueta": "Paredes agrietadas",
			"ayuda": "Grietas o rajaduras en los muros, aunque sigan en pie.",
			"icono": "pared-agrietada"
		},
		{
			"codigo": "PARED_CAIDA",
			"etiqueta": "Paredes caídas o inclinadas",
			"ayuda": "Un muro se vino abajo, se desaplomó o quedó torcido.",
			"icono": "pared-caida"
		},
		{
			"codigo": "COLUMNA_DANADA",
			"etiqueta": "Columnas o vigas partidas",
			"ayuda": "Las columnas o vigas que sostienen la casa están rotas, dobladas o con los fierros a la vista.",
			"icono": "columna-danada"
		},
		{
			"codigo": "TECHO_TEJAS",
			"etiqueta": "Tejas rotas o corridas",
			"ayuda": "Se perdieron tejas, se rompieron o se movieron de su sitio después del terremoto.",
			"icono": "techo-tejas"
		},
		{
			"codigo": "TECHO_CAIDO",
			"etiqueta": "Techo caído",
			"ayuda": "El techo se vino abajo, entero o por partes.",
			"icono": "techo-caido"
		},
		{
			"codigo": "PISO_DANADO",
			"etiqueta": "Piso agrietado o hundido",
			"ayuda": "El piso se rajó, se hundió o quedó desnivelado.",
			"icono": "piso-danado"
		},
		{
			"codigo": "AGUA_DANADA",
			"etiqueta": "Tubería rota o fugas de agua",
			"ayuda": "Se rompió la tubería, hay fugas, o el tanque o el pozo quedaron dañados.",
			"icono": "agua-danada"
		},
		{
			"codigo": "LUZ_DANADA",
			"etiqueta": "Instalación eléctrica dañada",
			"ayuda": "Cables sueltos o rotos, o la casa quedó sin luz por daño propio.",
			"icono": "luz-danada"
		}
	],
	categorias_video: [
		{
			"id": 1,
			"nombre": "Paredes agrietadas",
			"instruccion": "Grabe la grieta de cerca y después aléjese para que se vea la pared entera. Pase despacio, de un extremo de la grieta al otro.",
			"senal": "PARED_AGRIETADA",
			"obligatoria": true,
			"segundos_min": 5,
			"segundos_max": 120
		},
		{
			"id": 2,
			"nombre": "Paredes caídas o inclinadas",
			"instruccion": "Grabe el muro caído o torcido desde donde sea seguro pararse. No se acerque si puede venirse abajo más.",
			"senal": "PARED_CAIDA",
			"obligatoria": true,
			"segundos_min": 5,
			"segundos_max": 120
		},
		{
			"id": 3,
			"nombre": "Columnas o vigas partidas",
			"instruccion": "Grabe la columna o la viga dañada de arriba abajo. Si se ven los fierros, deténgase unos segundos en ese punto.",
			"senal": "COLUMNA_DANADA",
			"obligatoria": true,
			"segundos_min": 5,
			"segundos_max": 120
		},
		{
			"id": 4,
			"nombre": "Tejas rotas o corridas",
			"instruccion": "Grabe el techo desde el patio y, si se puede entrar sin riesgo, también desde adentro. No se suba al techo.",
			"senal": "TECHO_TEJAS",
			"obligatoria": true,
			"segundos_min": 5,
			"segundos_max": 120
		},
		{
			"id": 5,
			"nombre": "Techo caído",
			"instruccion": "Grabe la parte del techo que se vino abajo, desde afuera. Entre a grabar solo si es seguro hacerlo.",
			"senal": "TECHO_CAIDO",
			"obligatoria": true,
			"segundos_min": 5,
			"segundos_max": 120
		},
		{
			"id": 6,
			"nombre": "Piso agrietado o hundido",
			"instruccion": "Grabe el piso caminando despacio por encima de la parte rajada o hundida, para que se note el desnivel.",
			"senal": "PISO_DANADO",
			"obligatoria": true,
			"segundos_min": 5,
			"segundos_max": 120
		},
		{
			"id": 7,
			"nombre": "Tubería rota o fugas de agua",
			"instruccion": "Grabe por dónde sale el agua o dónde se rompió la tubería. Si el daño es del tanque o del pozo, grabe ese punto.",
			"senal": "AGUA_DANADA",
			"obligatoria": true,
			"segundos_min": 5,
			"segundos_max": 120
		},
		{
			"id": 8,
			"nombre": "Instalación eléctrica dañada",
			"instruccion": "Grabe los cables sueltos o rotos desde lejos. No los toque ni se acerque, aunque parezcan apagados.",
			"senal": "LUZ_DANADA",
			"obligatoria": true,
			"segundos_min": 5,
			"segundos_max": 120
		}
	],
	aviso_version: "habeas-data-v2"
};

const CLAVE = 'catalogo';

/**
 * El catálogo vigente: el guardado si existe, si no el embebido.
 *
 * Nunca falla ni devuelve vacío. Un formulario sin señales que marcar no es un
 * formulario degradado: es una pantalla rota delante de alguien que perdió su
 * casa.
 */
export async function catalogoVigente(): Promise<Catalogo> {
	try {
		const guardado = await leerAjuste(CLAVE);
		if (guardado) return { ...CATALOGO_EMBEBIDO, ...JSON.parse(guardado) };
	} catch {
		// Un JSON corrupto no puede dejar sin formulario a nadie.
	}

	return CATALOGO_EMBEBIDO;
}

/**
 * Se pone al día si hay señal. Silencioso a propósito.
 *
 * Si falla, no se le dice nada a la persona: el catálogo embebido sirve, y
 * avisar de un problema que no tiene que resolver solo la asusta.
 */
export async function refrescarCatalogo(base: string): Promise<boolean> {
	try {
		const r = await fetch(`${base}/preinscripcion/catalogos`);
		if (!r.ok) return false;

		const cuerpo = await r.json();
		const datos = cuerpo?.data;

		// Se exige que traiga señales: un catálogo vacío por un fallo del
		// servidor no puede pisar al embebido y dejar el formulario sin nada que
		// marcar.
		if (!datos || !Array.isArray(datos.senales) || datos.senales.length === 0) return false;

		await guardarAjuste(CLAVE, JSON.stringify(datos));
		await guardarAjuste('catalogo_en', new Date().toISOString());

		return true;
	} catch {
		return false;
	}
}

/** Cuándo se puso al día por última vez, para poder decirlo si hace falta. */
export async function catalogoActualizadoEn(): Promise<string | null> {
	return leerAjuste('catalogo_en');
}
