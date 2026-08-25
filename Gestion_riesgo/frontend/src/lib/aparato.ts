// En qué se está usando el sistema: un teléfono, una tableta o un computador.
//
// Existe por un error que se vio en producción: desde un Mac, el menú lateral
// ofrecía «Instalar en este teléfono». Chrome de escritorio también emite
// `beforeinstallprompt` —instalar una aplicación web es cosa suya desde hace
// años—, así que el botón aparecía correctamente; lo que estaba mal era la
// palabra, escrita a mano dando por hecho que quien entra viene de campo.
//
// No es una errata sin consecuencias. Media aplicación le explica a la persona
// qué pasa con lo que guarda —«se guarda en el teléfono», «el teléfono puede
// borrarlo»— y esas frases son justamente las que deciden si confía en salir a
// una vereda sin señal. Leídas en un computador de la Alcaldía suenan a que el
// sistema no sabe dónde está corriendo, que es exactamente lo que pasaba.
//
// Se detecta con el agente de usuario a propósito, y no con el ancho de la
// pantalla: una ventana estrecha en un Mac sigue siendo un Mac, y una tableta
// apaisada es más ancha que muchos portátiles.

export type ClaveAparato = "telefono" | "tableta" | "equipo";

/**
 * Cómo se nombra el aparato en una frase.
 *
 * Las tres formas van juntas porque el género cambia —«este teléfono» pero
 * «esta tableta»—, y dejar que cada pantalla arme la suya es cómo aparecen los
 * «esta teléfono» que nadie revisa.
 */
export type Aparato = {
	clave: ClaveAparato;
	/** «teléfono» */
	nombre: string;
	/** «este teléfono» */
	este: string;
	/** «el teléfono» */
	el: string;
};

const APARATOS: Record<ClaveAparato, Aparato> = {
	telefono: {
		clave: "telefono",
		nombre: "teléfono",
		este: "este teléfono",
		el: "el teléfono",
	},
	tableta: {
		clave: "tableta",
		nombre: "tableta",
		este: "esta tableta",
		el: "la tableta",
	},
	equipo: {
		clave: "equipo",
		nombre: "equipo",
		este: "este equipo",
		el: "el equipo",
	},
};

/**
 * La decisión, aislada de `navigator` para poder probarla.
 *
 * @param ua           `navigator.userAgent`
 * @param tactil       `navigator.maxTouchPoints`
 * @param movilDeclarado `navigator.userAgentData?.mobile`, cuando el navegador
 *                     lo trae. Es el dato más fiable que existe, pero solo
 *                     distingue móvil de no-móvil: una tableta Android responde
 *                     `false`. Por eso se consulta DESPUÉS de las reglas que sí
 *                     saben distinguir tabletas, no antes.
 */
export function reconocerAparato(
	ua: string,
	tactil = 0,
	movilDeclarado?: boolean,
): Aparato {
	if (/iPhone|iPod/.test(ua)) return APARATOS.telefono;
	if (/iPad/.test(ua)) return APARATOS.tableta;

	// El iPad se anuncia como Mac desde iPadOS 13 y no hay nada en el agente que
	// lo delate salvo que tenga pantalla táctil. Un Mac de verdad responde 0.
	if (/Macintosh/.test(ua) && tactil > 1) return APARATOS.tableta;

	// En Android la palabra «Mobile» es la que separa teléfono de tableta; el
	// agente de una tableta trae «Android» y no la trae.
	if (/Android/.test(ua))
		return /Mobile/.test(ua) ? APARATOS.telefono : APARATOS.tableta;

	if (/Tablet|PlayBook|Silk|Kindle/.test(ua)) return APARATOS.tableta;
	if (/Mobi|Windows Phone|IEMobile|Opera Mini|BlackBerry/.test(ua))
		return APARATOS.telefono;

	if (movilDeclarado === true) return APARATOS.telefono;

	return APARATOS.equipo;
}

/** Lo mismo, leyendo del navegador. En el servidor no hay aparato: se asume equipo. */
export function aparato(): Aparato {
	if (typeof navigator === "undefined") return APARATOS.equipo;

	const datos = (
		navigator as Navigator & { userAgentData?: { mobile?: boolean } }
	).userAgentData;

	return reconocerAparato(
		navigator.userAgent,
		navigator.maxTouchPoints ?? 0,
		datos?.mobile,
	);
}

/**
 * El nombre comercial cuando hace falta decirlo, que es solo en iOS: las
 * instrucciones de «Compartir → Añadir a inicio» son de Safari y quien las lee
 * reconoce «iPhone» o «iPad» antes que «este aparato».
 */
export function nombreApple(a: Aparato = aparato()): string {
	return a.clave === "tableta" ? "iPad" : "iPhone";
}
