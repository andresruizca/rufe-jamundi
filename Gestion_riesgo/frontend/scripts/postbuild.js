import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * adapter-static genera solo `200.html` cuando no se pre-renderiza ninguna ruta
 * (esta aplicación decide qué mostrar según la sesión, así que no puede
 * pre-renderizarse). Apache, en cambio, sirve `index.html` como índice del
 * directorio: sin él, la raíz del dominio responde 403.
 *
 * Se copia en lugar de enlazar porque el despliegue viaja como ZIP y los
 * enlaces simbólicos no sobreviven a la extracción en el hosting.
 */
const build = resolve(import.meta.dirname, '..', 'build');
const origen = resolve(build, '200.html');
const destino = resolve(build, 'index.html');

if (!existsSync(origen)) {
	console.error('postbuild: no se encontró build/200.html — ¿cambió el adaptador?');
	process.exit(1);
}

copyFileSync(origen, destino);
console.log('postbuild: build/index.html generado desde 200.html');

// La lista de barrios de «BASE-DATOS RUFE» ya no se publica: el tablero dejó de
// leer hojas de cálculo y el censo lo sirve la API desde MySQL. Publicar un dato
// que nadie consulta solo deja una pista falsa para quien venga después.
