// El control de acceso del navegador. No es la seguridad real —esa la aplica el
// router de PHP— pero sí decide qué ve cada rol, y una entrada mal registrada
// aquí muestra un enlace que lleva a una pantalla prohibida.

import { describe, expect, it } from 'vitest';
import {
	NAV_ITEMS,
	RUTAS_PUBLICAS,
	esRutaPublica,
	menuParaRol,
	puedeAcceder,
	resolverTitulo
} from './navigation';

describe('rutas públicas', () => {
	it('solo el login se sirve sin sesión', () => {
		expect(RUTAS_PUBLICAS).toEqual(['/login']);
	});

	it('el formulario RUFE ya no es público', () => {
		expect(esRutaPublica('/riesgo/reportar')).toBe(false);
	});

	it('ninguna ruta de riesgo es pública', () => {
		for (const r of ['/riesgo/reportar', '/riesgo/reportes', '/riesgo/reportes/1']) {
			expect(esRutaPublica(r)).toBe(false);
		}
	});
});

describe('acceso al formulario de captura', () => {
	it('un administrador entra', () => {
		expect(puedeAcceder('/riesgo/reportar', 'ADMINISTRADOR')).toBe(true);
	});

	it('un gestor entra', () => {
		expect(puedeAcceder('/riesgo/reportar', 'GESTOR')).toBe(true);
	});

	it('un rol de solo visualización NO entra', () => {
		expect(puedeAcceder('/riesgo/reportar', 'VISUALIZACION')).toBe(false);
	});

	it('sin sesión no entra nadie', () => {
		expect(puedeAcceder('/riesgo/reportar', null)).toBe(false);
	});

	// puedeAcceder() permite por omisión las rutas que no conoce: si la entrada
	// desapareciera del registro, el formulario quedaría abierto a cualquier rol
	// autenticado sin que nada más lo delatara.
	it('la ruta está registrada en el menú, no cae en el permiso por omisión', () => {
		const item = NAV_ITEMS.find((i) => i.href === '/riesgo/reportar');
		expect(item).toBeDefined();
		expect(item?.roles).toEqual(['ADMINISTRADOR', 'GESTOR']);
	});
});

describe('la bandeja sigue siendo de lectura para todos', () => {
	it('los tres roles pueden consultarla', () => {
		for (const rol of ['ADMINISTRADOR', 'GESTOR', 'VISUALIZACION'] as const) {
			expect(puedeAcceder('/riesgo/reportes', rol)).toBe(true);
			expect(puedeAcceder('/riesgo/reportes/42', rol)).toBe(true);
		}
	});
});

describe('menú por rol', () => {
	function etiquetas(rol: 'ADMINISTRADOR' | 'GESTOR' | 'VISUALIZACION') {
		return menuParaRol(rol).flatMap((s) =>
			s.type === 'item' ? [s.item.label] : s.items.map((i) => i.label)
		);
	}

	it('el visor no ve el enlace de registrar', () => {
		expect(etiquetas('VISUALIZACION')).not.toContain('Registro');
	});

	it('el gestor sí lo ve, y no ve administración', () => {
		expect(etiquetas('GESTOR')).toContain('Registro');
		expect(etiquetas('GESTOR')).not.toContain('Usuarios del sistema');
	});

	it('el administrador lo ve todo', () => {
		const e = etiquetas('ADMINISTRADOR');
		expect(e).toContain('Registro');
		expect(e).toContain('Reportes RUFE');
		expect(e).toContain('Usuarios del sistema');
	});

	it('sin rol el menú queda vacío', () => {
		expect(menuParaRol(null)).toEqual([]);
	});
});

describe('títulos', () => {
	it('cada ruta registrada resuelve a su título', () => {
		expect(resolverTitulo('/riesgo/reportar')).toBe(
			'Registro Unifamiliar de Emergencias — captura en campo'
		);
		expect(resolverTitulo('/riesgo/reportes')).toBe('Fichas RUFE registradas');
	});
});
