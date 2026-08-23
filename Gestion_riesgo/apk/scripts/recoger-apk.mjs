// Saca el APK de donde lo deja Gradle y lo pone donde se encuentra.
//
// Gradle lo escribe en `android/app/build/outputs/apk/debug/app-debug.apk`:
// siete carpetas dentro, con un nombre que no dice de qué aplicación es ni de
// qué día. Y como `build/` está en el .gitignore, quien clone el repositorio no
// lo ve por ningún lado.
//
// Esto lo copia a `salida/` con fecha en el nombre, que es lo que se manda por
// WhatsApp o se pasa por Bluetooth.

import { copyFileSync, existsSync, mkdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const origen = join(raiz, 'android', 'app', 'build', 'outputs', 'apk', 'debug', 'app-debug.apk');

if (!existsSync(origen)) {
	console.error('\n  No hay APK compilado. Ejecute primero `npm run apk:debug`.\n');
	process.exit(1);
}

const salida = join(raiz, 'salida');
mkdirSync(salida, { recursive: true });

const hoy = new Date().toISOString().slice(0, 10).replace(/-/g, '');
const destino = join(salida, `sgr-jamundi-${hoy}-debug.apk`);

copyFileSync(origen, destino);

const mb = (statSync(destino).size / 1048576).toFixed(0);

console.log(`\n  APK listo:  apk/salida/sgr-jamundi-${hoy}-debug.apk  (${mb} MB)\n`);
console.log('  Para instalarlo con el teléfono conectado por USB:');
console.log(`    adb install -r "${destino}"\n`);
