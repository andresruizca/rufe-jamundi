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
	CloudOff,
	FilePlus2
} from '@lucide/svelte';
import type { Component } from 'svelte';

/**
 * Rutas que se sirven sin sesión. Solo el login.
 *
 * La lista existe para que añadir una ruta pública sea una decisión visible y
 * deliberada, en un solo archivo, y no un `if` escondido en el layout. Cada
 * entrada amplía lo que un desconocido puede abrir.
 *
 * Estas rutas se dibujan sin el armazón: ni menú lateral, ni barra superior.
 */
export const RUTAS_PUBLICAS: string[] = ['/login'];

export function esRutaPublica(ruta: string): boolean {
	return RUTAS_PUBLICAS.includes(ruta);
}

/**
 * Rutas que siguen funcionando sin conexión, con la sesión guardada en el
 * teléfono y sin que el servidor la haya confirmado.
 *
 * Son exactamente las dos del trabajo de campo: levantar una ficha y vigilar las
 * que aún no salieron. Ambas trabajan contra el teléfono —el formulario, sus
 * catálogos guardados y la cola en IndexedDB—, así que sin señal tienen todo lo
 * que necesitan.
 *
 * El resto del sistema lee del servidor: el tablero, la bandeja, el mapa y la
 * administración no tendrían nada que mostrar. Ahí se avisa, en vez de fingir.
 *
 * La lista vive aquí, junto a `RUTAS_PUBLICAS`, por la misma razón: ampliar lo
 * que se abre sin comprobar contra el servidor debe ser una decisión visible en
 * un solo archivo, no un `if` escondido en el layout.
 */
export const RUTAS_SIN_CONEXION: string[] = ['/riesgo/reportar', '/riesgo/pendientes'];

export function funcionaSinConexion(ruta: string): boolean {
	return RUTAS_SIN_CONEXION.includes(ruta);
}

export const ROLES = {
	ADMINISTRADOR: 'ADMINISTRADOR',
	GESTOR: 'GESTOR',
	VISUALIZACION: 'VISUALIZACION'
} as const;

export type Rol = (typeof ROLES)[keyof typeof ROLES];

/** Cualquier persona autenticada. */
export const TODOS: Rol[] = [ROLES.ADMINISTRADOR, ROLES.GESTOR, ROLES.VISUALIZACION];
/** Quienes pueden escribir datos. */
export const ESCRITURA: Rol[] = [ROLES.ADMINISTRADOR, ROLES.GESTOR];
/** Solo administración. */
export const SOLO_ADMIN: Rol[] = [ROLES.ADMINISTRADOR];

export const ETIQUETA_ROL: Record<Rol, string> = {
	ADMINISTRADOR: 'Administrador',
	GESTOR: 'Gestor',
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
	// El tablero del RUFE es la única pantalla operativa del sistema hoy, así
	// que va suelta en el primer nivel y no dentro de un grupo.
	{
		id: 'dashboard',
		type: 'item',
		label: 'Dashboard',
		title: 'Tablero RUFE — Sismo Jamundí',
		href: '/dashboard',
		icon: LayoutDashboard,
		roles: TODOS,
		match: ['/dashboard']
	},

	// «Registro» agrupa el formulario y la cola local. Separarlos evita que la
	// pantalla de captura tenga que hacer dos trabajos: levantar una ficha nueva y
	// vigilar las que todavía no salieron.
	{
		id: 'grupo-registro',
		type: 'group',
		label: 'Registro',
		icon: ClipboardPlus,
		roles: ESCRITURA
	},
	{
		id: 'captura-rufe',
		type: 'item',
		parentId: 'grupo-registro',
		label: 'Nueva ficha',
		title: 'Registro Unifamiliar de Emergencias — captura en campo',
		href: '/riesgo/reportar',
		icon: FilePlus2,
		roles: ESCRITURA,
		match: ['/riesgo/reportar']
	},
	{
		id: 'pendientes-rufe',
		type: 'item',
		parentId: 'grupo-registro',
		label: 'Pendientes',
		title: 'Fichas pendientes de enviar',
		href: '/riesgo/pendientes',
		icon: CloudOff,
		roles: ESCRITURA,
		match: ['/riesgo/pendientes']
	},
	{
		id: 'reportes-rufe',
		type: 'item',
		label: 'Reportes RUFE',
		title: 'Fichas RUFE registradas',
		href: '/riesgo/reportes',
		icon: ClipboardList,
		roles: TODOS,
		match: ['/riesgo/reportes', /^\/riesgo\/reportes\/[^/]+$/]
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
		roles: TODOS,
		match: ['/riesgo/mapas']
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
