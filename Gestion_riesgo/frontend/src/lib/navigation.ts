// Navegación — fuente única de verdad para el menú lateral, el título de la
// barra superior y la guardia de rutas.
//
// Está en un solo archivo a propósito: cuando la visibilidad del menú y el
// control de acceso viven en sitios distintos, terminan desincronizados y
// aparece un enlace que lleva a una pantalla de "no autorizado".
//
// Cada elemento hoja lleva:
//   • id        identificador estable
//   • label     texto del menú
//   • title     título que muestra la barra superior
//   • href      ruta canónica
//   • icon      componente de @lucide/svelte
//   • parentId  id del grupo al que pertenece (opcional)
//   • roles     roles que pueden verlo
//   • match     rutas que lo marcan como activo. Las cadenas se comparan
//               exactas para que un hijo más específico gane siempre sobre su
//               padre; usa expresión regular para rutas con parámetros.

import {
	LayoutDashboard,
	Users,
	ShieldCheck,
	Info,
	ClipboardList,
	ClipboardPlus,
	Map as IconoMapa,
	MapPinned,
	FilePlus2,
	FileText,
	HardHat,
	PhoneCall,
	ClipboardCheck,
	Inbox,
	Video,
	UserPlus
} from '@lucide/svelte';
import type { Component } from 'svelte';

/**
 * Rutas que se sirven sin sesión: el login y la pre-inscripción ciudadana.
 *
 * La lista existe para que añadir una ruta pública sea una decisión visible y
 * deliberada, en un solo archivo, y no un `if` escondido en el layout. Cada
 * entrada amplía lo que un desconocido puede abrir.
 *
 * Estas rutas se dibujan sin el armazón: ni menú lateral, ni barra superior.
 *
 * `/preinscripcion` es la excepción deliberada a «todo exige sesión»: la abre un
 * ciudadano que no tiene cuenta ni va a tenerla. Por eso solo ESCRIBE una
 * solicitud —nunca consulta nada— y el servidor la protege con límite por IP,
 * trampa antirrobot e idempotencia.
 */
export const RUTAS_PUBLICAS: string[] = ['/login', '/preinscripcion'];

export function esRutaPublica(ruta: string): boolean {
	return RUTAS_PUBLICAS.includes(ruta);
}

/**
 * Rutas que siguen funcionando sin conexión, con la sesión guardada en el
 * teléfono y sin que el servidor la haya confirmado.
 *
 * Son las del trabajo de campo: levantar una ficha del censo, vigilar las que
 * aún no salieron e inspeccionar una vivienda. Las tres trabajan contra el
 * teléfono —los formularios, sus catálogos guardados y la cola en IndexedDB—,
 * así que sin señal tienen todo lo que necesitan.
 *
 * El resto del sistema lee del servidor: el tablero, la bandeja, el mapa y la
 * administración no tendrían nada que mostrar. Ahí se avisa, en vez de fingir.
 *
 * La lista vive aquí, junto a `RUTAS_PUBLICAS`, por la misma razón: ampliar lo
 * que se abre sin comprobar contra el servidor debe ser una decisión visible en
 * un solo archivo, no un `if` escondido en el layout.
 */
export const RUTAS_SIN_CONEXION: string[] = [
	// Levantar fichas: es el trabajo de campo y siempre funcionó sin señal.
	'/riesgo/reportar',
	// La inspección también se levanta en campo, y su formato viaja entero en
	// los catálogos —criterios del Anexo 1 y materiales del Anexo 2 incluidos—
	// justamente para que no haga falta señal.
	'/riesgo/inspeccionar',

	// ── Consultar, desde que la Alcaldía pidió el sistema entero sin señal ────
	//
	// Estas pantallas solo se dibujan si sus datos están guardados de una visita
	// anterior; no se descarga nada por adelantado. Cuando se sirven de la copia
	// lo dicen, con la fecha, y lo guardado caduca a las 24 h.
	//
	// El coste está asumido y escrito: el censo que alguien consulte vive en su
	// aparato hasta que cierre sesión. Ver `docs/offline.md`.
	'/dashboard',
	'/riesgo/reportes',
	'/riesgo/inspecciones',
	'/riesgo/preinscripciones',
	'/riesgo/mapas',
	'/riesgo/callcenter',
	'/riesgo/sin-censo'
];

export function funcionaSinConexion(ruta: string): boolean {
	return RUTAS_SIN_CONEXION.includes(ruta);
}

export const ROLES = {
	ADMINISTRADOR: 'ADMINISTRADOR',
	GESTOR: 'GESTOR',
	/** El profesional que evalúa las viviendas. Solo alcanza su formato. */
	INSPECTOR: 'INSPECTOR',
	/** Quien llama a los hogares del RUFE. Solo alcanza su lista de llamadas. */
	OPERADOR: 'OPERADOR',
	VISUALIZACION: 'VISUALIZACION'
} as const;

export type Rol = (typeof ROLES)[keyof typeof ROLES];

/**
 * Cualquier persona autenticada.
 *
 * OJO: ya no sirve para proteger lo que muestra datos del censo. Desde que
 * existe el inspector —que no debe ver fichas de hogares damnificados— eso es
 * `LECTURA_RUFE`. Es la misma distinción que hace `Auth` en el servidor, que es
 * quien manda.
 */
export const TODOS: Rol[] = [
	ROLES.ADMINISTRADOR,
	ROLES.GESTOR,
	ROLES.INSPECTOR,
	ROLES.OPERADOR,
	ROLES.VISUALIZACION
];
/** Quienes pueden escribir datos del censo y decidir sobre las fichas. */
export const ESCRITURA: Rol[] = [ROLES.ADMINISTRADOR, ROLES.GESTOR];
/** Quienes pueden consultar el censo y el mapa. */
export const LECTURA_RUFE: Rol[] = [ROLES.ADMINISTRADOR, ROLES.GESTOR, ROLES.VISUALIZACION];
/** Quienes levantan y consultan inspecciones de vivienda. */
export const INSPECCION: Rol[] = [ROLES.ADMINISTRADOR, ROLES.GESTOR, ROLES.INSPECTOR];
/**
 * Quienes pueden CONSULTAR una inspección ya levantada.
 *
 * Es `TODOS` menos el operador de call center, y existe por lo mismo que
 * `LECTURA_RUFE`: al entrar un rol nuevo, `TODOS` dejó de significar «todo el
 * que tiene sesión puede ver esto» y pasó a colar en las inspecciones a alguien
 * cuyo trabajo es marcar un número. Espeja `Auth::LECTURA_INSPECCION`.
 */
export const LECTURA_INSPECCION: Rol[] = [
	ROLES.ADMINISTRADOR,
	ROLES.GESTOR,
	ROLES.INSPECTOR,
	ROLES.VISUALIZACION
];
/**
 * Quienes trabajan la campaña de llamadas sobre la base del RUFE.
 *
 * El operador NO está en `LECTURA_RUFE`: lo que ve es una lista de nombres y
 * teléfonos para llamar, no las fichas del censo.
 */
export const CALL_CENTER: Rol[] = [ROLES.ADMINISTRADOR, ROLES.GESTOR, ROLES.OPERADOR];
/** Solo administración. */
export const SOLO_ADMIN: Rol[] = [ROLES.ADMINISTRADOR];

export const ETIQUETA_ROL: Record<Rol, string> = {
	ADMINISTRADOR: 'Administrador',
	GESTOR: 'Gestor',
	INSPECTOR: 'Insp. de vivienda',
	OPERADOR: 'Operador de call center',
	VISUALIZACION: 'Visualización'
};

export type NavItem = {
	id: string;
	type: 'item' | 'group';
	label: string;
	title?: string;
	href?: string;
	icon?: Component;
	parentId?: string;
	roles: Rol[];
	match?: (string | RegExp)[];
};

export const NAV_ITEMS: NavItem[] = [
	// El panorama de la emergencia: va suelto en el primer nivel porque no es
	// de ningún módulo, es la vista de todos a la vez.
	//
	// Se llamaba «Dashboard» y «Tablero RUFE». Lo primero era la única palabra
	// en inglés de un sistema que usan operadoras y funcionarios de la
	// Alcaldía. Lo segundo nombraba el formulario con el que se llenó, cuando
	// la pantalla ya no habla de un formato: habla de las familias y de en qué
	// punto del trámite está cada una. El RUFE sigue nombrado dentro, en la
	// sección del censo, que es donde el nombre es exacto.
	{
		id: 'dashboard',
		type: 'item',
		label: 'Panorama',
		title: 'Atención a damnificados — Sismo Jamundí',
		href: '/dashboard',
		icon: LayoutDashboard,
		roles: LECTURA_RUFE,
		match: ['/dashboard']
	},

	// «Registro» agrupa los dos formatos que se levantan en campo y la cola local.
	// Separar la cola de la captura evita que la pantalla del formulario tenga que
	// hacer dos trabajos: levantar una ficha nueva y vigilar las que no salieron.
	//
	// Los formatos van primero y con su código oficial, que es como los nombra el
	// equipo; «Pendientes» cierra el grupo porque no es un formato, es el estado de
	// lo ya levantado.
	{
		id: 'grupo-registro',
		type: 'group',
		label: 'Registro',
		icon: ClipboardPlus,
		// El grupo se muestra a quien pueda ver alguno de sus hijos: el inspector
		// solo verá su formato, y el operador de call center solo su lista.
		roles: [...new Set([...INSPECCION, ...CALL_CENTER])]
	},
	{
		id: 'captura-rufe',
		type: 'item',
		parentId: 'grupo-registro',
		label: 'RUFE FR-1703-SMD-69',
		// El título de la barra superior sigue siendo el descriptivo: a esa pantalla
		// también se llega por un enlace directo, sin haber pasado por el menú, y un
		// encabezado que solo dijera el código no le diría nada a quien llega así.
		title: 'Registro Unifamiliar de Emergencias — captura en campo',
		href: '/riesgo/reportar',
		icon: FilePlus2,
		roles: ESCRITURA,
		match: ['/riesgo/reportar']
	},
	{
		id: 'inspeccionar',
		type: 'item',
		parentId: 'grupo-registro',
		label: 'INSP DE VIVIENDA',
		title: 'Inspección de viviendas afectadas — banco de materiales',
		href: '/riesgo/inspeccionar',
		icon: HardHat,
		roles: INSPECCION,
		match: ['/riesgo/inspeccionar']
	},
	{
		id: 'callcenter',
		type: 'item',
		parentId: 'grupo-registro',
		label: 'Call center',
		title: 'Call center — acompañamiento a la preinscripción',
		href: '/riesgo/callcenter',
		icon: PhoneCall,
		// Es lo ÚNICO que ve el operador. Por eso el grupo «Registro» tiene que
		// admitirlo también, o su única pantalla quedaría sin dónde colgarse.
		roles: CALL_CENTER,
		match: ['/riesgo/callcenter']
	},
	// «Reportes» es el espejo de «Registro»: los mismos dos formatos, con los
	// mismos nombres, pero para consultar lo ya registrado en vez de levantarlo.
	// Que la pareja se repita a un lado y al otro es la intención, no un descuido:
	// quien busca una inspección la encuentra escrita igual en los dos sitios.
	//
	// El grupo es de lectura para los tres roles, no solo para quien escribe:
	// Visualización es justamente el rol que más consulta reportes.
	{
		id: 'grupo-reportes',
		type: 'group',
		label: 'Reportes',
		icon: ClipboardList,
		roles: LECTURA_INSPECCION
	},
	{
		id: 'reportes-rufe',
		type: 'item',
		parentId: 'grupo-reportes',
		label: 'RUFE FR-1703-SMD-69',
		title: 'Fichas RUFE registradas',
		href: '/riesgo/reportes',
		icon: FileText,
		roles: LECTURA_RUFE,
		match: ['/riesgo/reportes', /^\/riesgo\/reportes\/[^/]+$/]
	},
	{
		id: 'preinscripciones',
		type: 'item',
		parentId: 'grupo-reportes',
		label: 'Solicitudes ciudadanas',
		// El mismo nombre que el menú. Antes la pantalla se llamaba
		// «Pre-inscripciones recibidas»: tres nombres para una sola cosa —el del
		// menú, el del título y el de la URL— y quien llega desde el menú tiene
		// que adivinar que llegó a donde quería.
		title: 'Solicitudes ciudadanas',
		href: '/riesgo/preinscripciones',
		icon: Inbox,
		// Lectura del censo: son solicitudes con nombre, cédula y dirección de
		// familias. El profesional que inspecciona no las necesita.
		roles: LECTURA_RUFE,
		match: ['/riesgo/preinscripciones', /^\/riesgo\/preinscripciones\/[^/]+$/]
	},
	{
		id: 'inspecciones',
		type: 'item',
		parentId: 'grupo-reportes',
		label: 'INSP DE VIVIENDA',
		title: 'Inspecciones de vivienda registradas',
		href: '/riesgo/inspecciones',
		icon: ClipboardCheck,
		// Incluido Visualización: es el rol que supervisa, y estas fichas
		// sustentan una entrega de recursos públicos. NO el operador de call
		// center: una inspección lleva el nombre, la cédula y la dirección de una
		// familia, y su trabajo es marcar un teléfono.
		roles: LECTURA_INSPECCION,
		match: ['/riesgo/inspecciones', /^\/riesgo\/inspecciones\/[^/]+$/]
	},

	// Fuera del grupo «Registro» y con el mismo rol que Reportes: el mapa se
	// consulta, no se levanta. Meterlo dentro de un grupo restringido a escritura
	// se lo escondería a quien solo tiene Visualización, que es justamente quien
	// más lo mira.
	{
		id: 'mapas',
		type: 'item',
		label: 'Mapas',
		title: 'Mapa de la afectación',
		href: '/riesgo/mapas',
		icon: IconoMapa,
		roles: LECTURA_RUFE,
		match: ['/riesgo/mapas']
	},

	// Suelta y fuera de «Reportes» a propósito: ninguna de estas solicitudes
	// tiene una ficha RUFE detrás todavía, y mezclarla con «Solicitudes
	// ciudadanas» —que sí la tienen— confundiría los conteos de las dos
	// bandejas. Mismo rol que esa: nombre, teléfono y ubicación de alguien que
	// puede ser una familia damnificada, así que ni el inspector ni el
	// operador de call center la necesitan.
	{
		id: 'sin-censo',
		type: 'item',
		label: 'No aparecen en el censo',
		title: 'Quienes no aparecen en el censo (RUFE)',
		href: '/riesgo/sin-censo',
		icon: UserPlus,
		roles: LECTURA_RUFE,
		match: ['/riesgo/sin-censo', /^\/riesgo\/sin-censo\/[^/]+$/]
	},

	{
		id: 'grupo-admin',
		type: 'group',
		label: 'Administración',
		icon: ShieldCheck,
		roles: SOLO_ADMIN
	},
	{
		id: 'usuarios',
		type: 'item',
		parentId: 'grupo-admin',
		label: 'Usuarios del sistema',
		title: 'Gestión de usuarios del sistema',
		href: '/admin/usuarios',
		icon: Users,
		roles: SOLO_ADMIN,
		match: ['/admin/usuarios', /^\/admin\/usuarios\/[^/]+$/]
	},
	{
		id: 'admin-mapas',
		type: 'item',
		parentId: 'grupo-admin',
		label: 'Ubicaciones del mapa',
		title: 'Ubicación de las direcciones del censo',
		href: '/admin/mapas',
		icon: MapPinned,
		roles: SOLO_ADMIN,
		match: ['/admin/mapas']
	},

	{
		id: 'categorias-video',
		type: 'item',
		parentId: 'grupo-admin',
		label: 'Videos que se piden',
		title: 'Categorías de video de la pre-inscripción',
		href: '/admin/categorias-video',
		icon: Video,
		roles: SOLO_ADMIN,
		match: ['/admin/categorias-video']
	},

	{
		id: 'acerca',
		type: 'item',
		label: 'Acerca de',
		title: 'Acerca del sistema',
		href: '/acerca',
		icon: Info,
		roles: TODOS,
		match: ['/acerca']
	}
];

function coincide(patron: string | RegExp, ruta: string): boolean {
	return typeof patron === 'string' ? ruta === patron : patron.test(ruta);
}

export function esActivo(item: NavItem, ruta: string): boolean {
	return (item.match ?? []).some((p) => coincide(p, ruta));
}

/**
 * El elemento más específico que coincide con la ruta: gana la coincidencia
 * exacta más larga y, si no hay ninguna, la primera expresión regular. Así una
 * ruta hija nunca deja activo también a su padre.
 */
export function resolverActivo(ruta: string): NavItem | null {
	let mejor: NavItem | null = null;
	let mejorLargo = -1;
	let primeraRegex: NavItem | null = null;

	for (const item of NAV_ITEMS) {
		if (item.type === 'group') continue;
		for (const p of item.match ?? []) {
			if (typeof p === 'string') {
				if (ruta === p && p.length > mejorLargo) {
					mejorLargo = p.length;
					mejor = item;
				}
			} else if (p.test(ruta) && !primeraRegex) {
				primeraRegex = item;
			}
		}
	}

	return mejor ?? primeraRegex;
}

export function resolverTitulo(ruta: string): string {
	const item = resolverActivo(ruta);

	return item?.title ?? item?.label ?? 'Sistema de Gestión del Riesgo';
}

/**
 * El rastro de navegación completo: grupo y pantalla.
 *
 * La barra decía «SGR Jamundí / Pre-inscripciones recibidas» y se saltaba el
 * grupo, que es justo la mitad de la respuesta a «dónde estoy». Quien entra
 * desde Reportes ve el menú abierto en Reportes y luego una miga que no lo
 * menciona: parece que se cambió de sitio.
 *
 * El grupo va sin enlace a propósito. No es una pantalla —no hay un «índice de
 * Reportes» al que llevar—, y un enlace que no lleva a ninguna parte es peor
 * que no tenerlo.
 *
 * @return de la raíz a la pantalla actual, sin incluir «SGR Jamundí»
 */
export function resolverMiga(ruta: string): { label: string; esGrupo: boolean }[] {
	const item = resolverActivo(ruta);

	if (!item) {
		return [{ label: 'Sistema de Gestión del Riesgo', esGrupo: false }];
	}

	const miga: { label: string; esGrupo: boolean }[] = [];

	if (item.parentId) {
		const grupo = NAV_ITEMS.find((i) => i.id === item.parentId);

		if (grupo) {
			miga.push({ label: grupo.label, esGrupo: true });
		}
	}

	miga.push({ label: item.title ?? item.label, esGrupo: false });

	return miga;
}

export type Seccion =
	| { type: 'item'; item: NavItem }
	| { type: 'group'; group: NavItem; items: NavItem[] };

/** Árbol que dibuja el menú, ya filtrado por el rol de quien mira. */
export function menuParaRol(rol: Rol | null): Seccion[] {
	if (!rol) return [];

	const visibles = NAV_ITEMS.filter((i) => i.roles.includes(rol));
	const grupos = new Map<string, Seccion & { type: 'group' }>();
	const salida: Seccion[] = [];

	for (const item of visibles) {
		if (item.type === 'group') {
			const seccion = { type: 'group' as const, group: item, items: [] as NavItem[] };
			grupos.set(item.id, seccion);
			salida.push(seccion);
		} else if (item.parentId) {
			grupos.get(item.parentId)?.items.push(item);
		} else {
			salida.push({ type: 'item', item });
		}
	}

	// Un grupo sin hijos visibles para este rol no se dibuja.
	return salida.filter((s) => s.type !== 'group' || s.items.length > 0);
}

/**
 * ¿Este rol puede entrar a esta ruta? Se deriva del mismo registro que el menú,
 * así que ocultar un enlace y bloquear su ruta son siempre la misma decisión.
 * Las rutas no registradas (login, raíz) se permiten.
 */
export function puedeAcceder(ruta: string, rol: Rol | null): boolean {
	if (!rol) return false;
	const item = resolverActivo(ruta);
	if (!item) return true;

	return item.roles.includes(rol);
}

/**
 * A dónde llevar a alguien de este rol cuando no hay una ruta concreta.
 *
 * ⚠ NO puede ser `/dashboard` escrito a mano, que es lo que había en los tres
 * sitios que llaman aquí.
 *
 * El tablero está protegido por `LECTURA_RUFE`, y ni el inspector de vivienda ni
 * el operador de call center están en esa lista. La guardia los mandaba al
 * tablero, el tablero los rechazaba, la guardia los volvía a mandar: un bucle de
 * redirección del que no se sale. El inspector ya lo sufría antes de que
 * existiera el call center.
 *
 * Se deriva del MISMO registro que dibuja el menú, así que un rol nuevo tiene
 * su sitio sin que nadie se acuerde de tocar esto: es el primer enlace que ese
 * rol vería al abrir el menú.
 *
 * Sin rol, al login. Y si un rol no tuviera ningún enlace —no debería pasar,
 * pero un catálogo mal editado lo haría posible—, también al login: es preferible
 * pedir sesión otra vez a dejar a alguien girando en el vacío.
 */
export function inicioPara(rol: Rol | null): string {
	if (!rol) return '/login';

	for (const item of NAV_ITEMS) {
		if (item.type !== 'item' || !item.href) continue;
		if (item.roles.includes(rol)) return item.href;
	}

	return '/login';
}
