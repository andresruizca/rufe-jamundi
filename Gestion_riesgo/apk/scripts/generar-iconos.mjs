// Genera los iconos del APK a partir del escudo oficial.
//
//   node scripts/generar-iconos.mjs
//
// Capacitor deja su propio marcador de posición —una X azul— y así se quedó.
// Importa más de lo que parece: este APK se pasa de teléfono a teléfono por
// Bluetooth, y quien lo recibe decide si lo instala mirando el icono. Un logo
// genérico de una herramienta de desarrollo no dice «Alcaldía de Jamundí».
//
// Misma técnica que el generador del sitio web: se compone un SVG y se
// rasteriza con `qlmanage` y `sips`, que trae macOS. Sin dependencias nuevas.
//
// ── Los iconos adaptativos de Android ────────────────────────────────────────
//
// Desde Android 8 el lanzador recorta el icono con la forma que quiera —círculo,
// cuadrado redondeado, gota— así que se entrega en dos capas: fondo y frente. El
// frente se dibuja en un lienzo de 108 dp del que **solo se garantiza el 72 dp
// central**: lo que caiga fuera puede desaparecer.
//
// Por eso el escudo del frente va bastante más pequeño de lo que uno pondría a
// ojo. Es la diferencia entre un icono que se ve entero en cualquier teléfono y
// uno al que le cortan la corona en la mitad de los aparatos.

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, copyFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const res = join(aqui, '..', 'android', 'app', 'src', 'main', 'res');

const MAESTRO = 1024;

const original = readFileSync(join(aqui, 'escudo.svg'), 'utf8');

/** El escudo colocado en una caja, sin el prólogo XML ni el DOCTYPE. */
function escudoEn({ x, y, lado }) {
	return original
		.slice(original.indexOf('<svg'))
		.replace(
			/^<svg[^>]*>/s,
			`<svg x="${x}" y="${y}" width="${lado}" height="${lado}" viewBox="0 0 130 130" preserveAspectRatio="xMidYMid meet">`
		);
}

/**
 * @param escudo  alto del escudo respecto al lienzo.
 * @param fondo   dibuja el azul institucional detrás del escudo.
 *
 *                Va en TODAS las capas, también en la de frente del icono
 *                adaptativo, aunque lo natural sería dejarla transparente y que
 *                Android pusiera el color debajo. El motivo es que `qlmanage`
 *                rasteriza sobre BLANCO OPACO: no sabe hacer transparencia. Una
 *                capa de frente «sin fondo» salía como un cuadrado blanco que
 *                tapaba el azul entero, y el icono quedaba blanco.
 *
 *                Lo comprobé mirando el canal alfa del PNG generado: 255 de
 *                extremo a extremo. Con el azul dibujado sale bien, y lo único
 *                que se pierde es el efecto de paralaje de algunos lanzadores.
 * @param radio   esquinas redondeadas, para el icono heredado de Android 7.
 */
function componer({ escudo, fondo = true, radio = 0 }) {
	const L = MAESTRO;
	const lado = Math.round(L * escudo);
	const y = Math.round((L - lado) / 2);

	const capaFondo = fondo
		? `<rect width="${L}" height="${L}" fill="url(#base)"/>
	<rect width="${L}" height="${L}" fill="url(#brillo)"/>
	<rect width="${L}" height="${L}" fill="url(#malla)"/>`
		: '';

	return `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
	width="${L}" height="${L}" viewBox="0 0 ${L} ${L}">
<defs>
	<linearGradient id="base" x1="0" y1="0" x2="0.42" y2="1">
		<stop offset="0" stop-color="#123a63"/>
		<stop offset="0.52" stop-color="#0d2b4e"/>
		<stop offset="1" stop-color="#0a2140"/>
	</linearGradient>
	<radialGradient id="brillo" cx="0.74" cy="-0.12" r="0.85">
		<stop offset="0" stop-color="#1577d6" stop-opacity="0.5"/>
		<stop offset="0.62" stop-color="#1577d6" stop-opacity="0"/>
	</radialGradient>
	<pattern id="malla" width="128" height="128" patternUnits="userSpaceOnUse">
		<path d="M0 0H128M0 0V128" stroke="#ffffff" stroke-opacity="0.06" stroke-width="2" fill="none"/>
	</pattern>
	<clipPath id="marco"><rect width="${L}" height="${L}" rx="${Math.round(L * radio)}"/></clipPath>
</defs>
<g clip-path="url(#marco)">
	${capaFondo}
	${escudoEn({ x: Math.round((L - lado) / 2), y, lado })}
</g>
</svg>`;
}

// ── Qué se genera ───────────────────────────────────────────────────────────

const DENSIDADES = [
	{ carpeta: 'mipmap-mdpi', heredado: 48, frente: 108 },
	{ carpeta: 'mipmap-hdpi', heredado: 72, frente: 162 },
	{ carpeta: 'mipmap-xhdpi', heredado: 96, frente: 216 },
	{ carpeta: 'mipmap-xxhdpi', heredado: 144, frente: 324 },
	{ carpeta: 'mipmap-xxxhdpi', heredado: 192, frente: 432 }
];

/**
 * El icono de siempre, para Android 7 y anteriores: el lienzo se ve entero, así
 * que el escudo puede ser grande y lleva sus esquinas dibujadas.
 */
const HEREDADO = { escudo: 0.62, fondo: true, radio: 0.22 };

/**
 * La capa de frente del adaptativo. El escudo baja al 42 % porque solo el 72 dp
 * central de 108 está garantizado: 0.42 × 108 ≈ 45 dp, con margen de sobra
 * incluso para el recorte circular.
 */
const FRENTE = { escudo: 0.42, fondo: true, radio: 0 };

const temporal = mkdtempSync(join(tmpdir(), 'iconos-apk-'));

function rasterizar(nombre, opciones, lado, destino) {
	const svg = join(temporal, `${nombre}.svg`);
	writeFileSync(svg, componer(opciones));

	execFileSync('qlmanage', ['-t', '-s', String(MAESTRO), '-o', temporal, svg], { stdio: 'ignore' });
	copyFileSync(join(temporal, `${nombre}.svg.png`), destino);
	execFileSync('sips', ['-z', String(lado), String(lado), destino], { stdio: 'ignore' });
}

try {
	for (const { carpeta, heredado, frente } of DENSIDADES) {
		const base = join(res, carpeta);
		if (!existsSync(base)) continue;

		rasterizar(`h${heredado}`, HEREDADO, heredado, join(base, 'ic_launcher.png'));
		// El redondo es el mismo: el lanzador que lo pide ya lo recorta.
		copyFileSync(join(base, 'ic_launcher.png'), join(base, 'ic_launcher_round.png'));
		rasterizar(`f${frente}`, FRENTE, frente, join(base, 'ic_launcher_foreground.png'));

		console.log(`  ✓ ${carpeta}  (${heredado} px · frente ${frente} px)`);
	}

	// La pantalla de arranque: el escudo pequeño y centrado sobre el azul. Se
	// ve un instante, así que no lleva texto — no daría tiempo a leerlo.
	const splash = join(res, 'drawable', 'splash.png');
	if (existsSync(splash)) {
		rasterizar('splash', { escudo: 0.3, fondo: true, radio: 0 }, 480, splash);
		console.log('  ✓ drawable/splash.png');
	}
} finally {
	rmSync(temporal, { recursive: true, force: true });
}

console.log('\nIconos del APK regenerados desde el escudo oficial.');
