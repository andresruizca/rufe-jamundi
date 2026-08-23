// Las fotos del formulario, guardadas en el teléfono.
//
// Expone la MISMA interfaz que `GestorEvidencias` de la web —`archivosDe`,
// `limiteDe`, `agregar`, `quitar`, `reintentar`, `describir`, `optimizando`—
// para que `SubidaEvidencias.svelte` se pueda copiar sin tocar una línea. El
// componente no sabe si lo que hay detrás sube a un servidor o escribe en un
// disco, y así el APK se ve exactamente igual que la web.
//
// La diferencia está aquí dentro: en la web el archivo se sube y se olvida;
// aquí se comprime igual —la original nunca se guarda— y termina en el
// almacenamiento del aparato, anotado en SQLite, esperando a que
// `SyncWorker.kt` lo mande.

import { Directory, Filesystem } from '@capacitor/filesystem';
import {
	comprimirEvidencia,
	extensionDe,
	liberarVistaPrevia,
	type MetricasImagen,
	type TipoEvidencia
} from './imagen';
import { cabe, MAX_FOTOS_CEDULA, MAX_FOTOS_DANO } from '../captura/limites';
import { abrir } from '../local/base';

export type FotoLocal = {
	uid: string;
	tipo: TipoEvidencia;
	nombre: string;
	tamano: number;
	/**
	 * `subiendo` no ocurre nunca en el APK —aquí no se sube nada, eso es cosa de
	 * Kotlin horas después— pero se declara igual para que
	 * `SubidaEvidencias.svelte` se pueda copiar de la web sin tocar una línea.
	 * Es el precio de que el formulario se vea EXACTAMENTE igual, y es barato.
	 */
	estado: 'optimizando' | 'pendiente' | 'subiendo' | 'listo' | 'error';
	progreso: number;
	error?: string;
	reintentable?: boolean;
	metricas?: MetricasImagen;
	vistaPrevia?: string;
	descripcion?: string;
	/** Dónde quedó en el teléfono. Es lo que lee Kotlin al sincronizar. */
	ruta?: string;
};

/** Igual que en la web, para que el componente diga lo mismo. */
export function tamanoLegible(bytes: number): string {
	if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`;

	return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

const CARPETA = Directory.Data;

function uid(): string {
	return crypto.randomUUID();
}

/**
 * Capacitor escribe base64, no binario.
 *
 * Se lee con `FileReader` y no montando la cadena a mano: para una foto de
 * 900 KB, un bucle sobre el arreglo de bytes bloquea el hilo de la interfaz casi
 * un segundo en un teléfono modesto, y parece que la aplicación se colgó.
 */
function aBase64(archivo: Blob): Promise<string> {
	return new Promise((resolver, rechazar) => {
		const lector = new FileReader();

		lector.onerror = () => rechazar(new Error('No se pudo leer el archivo.'));
		lector.onload = () => {
			const url = String(lector.result);
			resolver(url.slice(url.indexOf(',') + 1));
		};

		lector.readAsDataURL(archivo);
	});
}

export class GestorFotos {
	fotos = $state<FotoLocal[]>([]);

	/** Lo mira el formulario para no dejar avanzar con una foto a medio hacer. */
	optimizando = $derived(this.fotos.some((f) => f.estado === 'optimizando'));

	constructor(private registroId: string) {}

	archivosDe(tipo: TipoEvidencia): FotoLocal[] {
		return this.fotos.filter((f) => f.tipo === tipo);
	}

	limiteDe(tipo: TipoEvidencia): number {
		return tipo === 'PRE_CEDULA' ? MAX_FOTOS_CEDULA : MAX_FOTOS_DANO;
	}

	private get bytesTotales(): number {
		return this.fotos.reduce((suma, f) => suma + f.tamano, 0);
	}

	async agregar(archivos: FileList | File[], tipo: TipoEvidencia): Promise<void> {
		for (const original of Array.from(archivos)) {
			if (this.archivosDe(tipo).length >= this.limiteDe(tipo)) break;

			const registro: FotoLocal = {
				uid: uid(),
				tipo,
				nombre: original.name,
				tamano: original.size,
				estado: 'optimizando',
				progreso: 0
			};

			this.fotos = [...this.fotos, registro];

			await this.procesar(registro, original);
		}
	}

	private async procesar(registro: FotoLocal, original: File): Promise<void> {
		const resultado = await comprimirEvidencia(original, registro.tipo, (p) => {
			this.actualizar(registro.uid, { progreso: p });
		});

		if (!resultado.ok) {
			this.actualizar(registro.uid, {
				estado: 'error',
				error: resultado.motivo,
				// No es reintentable: el problema es la foto, no el camino. Volver a
				// intentarlo con la misma imagen daría el mismo resultado.
				reintentable: false
			});

			return;
		}

		// Se comprueba el cupo con el tamaño YA comprimido: lo que cuenta contra
		// el límite es lo que se va a subir, no lo que entregó la cámara.
		const veredicto = cabe(
			registro.tipo === 'PRE_CEDULA' ? 'PRE_CEDULA' : 'PRE_DANO',
			resultado.archivo.size,
			this.archivosDe(registro.tipo).length - 1,
			this.bytesTotales - registro.tamano
		);

		if (!veredicto.ok) {
			this.actualizar(registro.uid, {
				estado: 'error',
				error: veredicto.motivo,
				reintentable: false
			});

			return;
		}

		try {
			const ruta = `${this.registroId}/${uid()}.${extensionDe(resultado.archivo.type)}`;

			await Filesystem.writeFile({
				path: ruta,
				directory: CARPETA,
				data: await aBase64(resultado.archivo),
				recursive: true
			});

			const db = await abrir();
			const idFila = uid();

			await db.run(
				`INSERT INTO adjuntos (id, registro_id, tipo, ruta, mime, bytes, creado_en, actualizado_en)
				 VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`,
				[
					idFila,
					this.registroId,
					registro.tipo,
					ruta,
					resultado.archivo.type,
					resultado.archivo.size
				]
			);

			this.actualizar(registro.uid, {
				// `listo` y no `pendiente`: en el teléfono no queda nada por hacer.
				// La subida es cosa de Kotlin y ocurre horas después.
				estado: 'listo',
				tamano: resultado.archivo.size,
				metricas: resultado.metricas,
				vistaPrevia: resultado.vistaPrevia,
				ruta,
				progreso: 100
			});
		} catch {
			this.actualizar(registro.uid, {
				estado: 'error',
				error: 'No se pudo guardar la foto. Puede que no quede espacio en el teléfono.',
				// Esta sí: liberar espacio y reintentar tiene sentido.
				reintentable: true
			});
		}
	}

	async quitar(uidFoto: string): Promise<void> {
		const foto = this.fotos.find((f) => f.uid === uidFoto);
		if (!foto) return;

		liberarVistaPrevia(foto.vistaPrevia);

		if (foto.ruta) {
			// Si el archivo ya no está, la fila se borra igual: dejarla apuntando a
			// algo inexistente haría que la sincronización fallara para siempre.
			await Filesystem.deleteFile({ path: foto.ruta, directory: CARPETA }).catch(() => undefined);

			const db = await abrir();
			await db.run('DELETE FROM adjuntos WHERE registro_id = ? AND ruta = ?', [
				this.registroId,
				foto.ruta
			]);
		}

		this.fotos = this.fotos.filter((f) => f.uid !== uidFoto);
	}

	/**
	 * Solo tiene sentido cuando falló el disco. Una foto que no se pudo
	 * comprimir no mejora por insistir, y por eso esas se marcan como no
	 * reintentables.
	 */
	async reintentar(_uidFoto: string): Promise<void> {
		// La web reintenta la subida. Aquí no hay subida que reintentar: lo que
		// falló fue escribir, y para volver a intentarlo hace falta el archivo
		// original, que ya no se conserva. Se pide tomarla de nuevo.
		this.actualizar(_uidFoto, {
			estado: 'error',
			error: 'Vuelva a tomar la foto.',
			reintentable: false
		});
	}

	describir(uidFoto: string, texto: string): void {
		this.actualizar(uidFoto, { descripcion: texto });
	}

	private actualizar(uidFoto: string, cambios: Partial<FotoLocal>): void {
		this.fotos = this.fotos.map((f) => (f.uid === uidFoto ? { ...f, ...cambios } : f));
	}
}
