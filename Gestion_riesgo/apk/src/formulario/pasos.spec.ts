import { describe, expect, it } from 'vitest';
import {
	PASOS_PRE,
	bloqueoDeAvance,
	datosVacios,
	paraEnviar,
	pasosVigentes,
	validarPaso,
	videosQueFaltan,
	videosQueSePiden,
	type CategoriaVideo,
	type DatosPre
} from './pasos';

function completos(cambios: Partial<DatosPre> = {}): DatosPre {
	return {
		...datosVacios(),
		nombre_completo: 'Pedro Antonio Pérez Gómez',
		documento: '16.234.567',
		telefono: '315 123 4567',
		direccion: 'Carrera 11 # 8-26',
		zona: 'URBANA',
		...cambios
	};
}

describe('los pasos', () => {
	it('son cuatro y en el orden que se acordó', () => {
		expect(PASOS_PRE.map((p) => p.id)).toEqual(['datos', 'vivienda', 'video', 'envio']);
	});

	it('se salta el video cuando nadie ha definido categorías', () => {
		// Es el estado de producción hoy: el catálogo está vacío a propósito
		// hasta que alguien con criterio estructural decida qué debe grabarse.
		// Sin esto, el ciudadano vería una pantalla vacía con un «Siguiente».
		expect(pasosVigentes(false).map((p) => p.id)).toEqual(['datos', 'vivienda', 'envio']);
		expect(pasosVigentes(true)).toHaveLength(4);
	});
});

describe('el paso de datos', () => {
	it('deja pasar lo mínimo completo', () => {
		expect(validarPaso('datos', completos())).toEqual({});
	});

	it('cuenta los dígitos de la cédula, no los puntos', () => {
		// La gente la escribe como la lee en su documento. Rechazar «16.234.567»
		// sería rechazar la forma normal de escribirla.
		expect(validarPaso('datos', completos({ documento: '16.234.567' })).documento).toBeUndefined();
		expect(validarPaso('datos', completos({ documento: '1.2' })).documento).toBeDefined();
	});

	it('exige la zona urbana o rural', () => {
		expect(validarPaso('datos', completos({ zona: '' })).zona).toBeDefined();
	});

	it('acepta una dirección que es una referencia y no una nomenclatura', () => {
		// Media zona rural de Jamundí no tiene calle y número.
		const d = completos({
			zona: 'RURAL',
			direccion: 'La casa azul pasando el puente de La Liberia'
		});

		expect(validarPaso('datos', d)).toEqual({});
	});

	it('deja el correo en blanco, pero caza la errata', () => {
		expect(validarPaso('datos', completos({ correo: '' })).correo).toBeUndefined();
		expect(validarPaso('datos', completos({ correo: 'pedro@correo.com' })).correo).toBeUndefined();
		expect(validarPaso('datos', completos({ correo: 'pedro@correo' })).correo).toBeDefined();
	});
});

describe('el paso de la vivienda', () => {
	it('no obliga a marcar ninguna señal', () => {
		// Quien tiene la casa partida por la mitad puede no reconocerse en
		// ninguno de los dibujos. Negarle el turno por eso sería el error que
		// este formulario existe para no cometer.
		expect(validarPaso('vivienda', completos({ senales: [] }))).toEqual({});
	});
});

describe('el paso de video', () => {
	it('nunca bloquea', () => {
		// Un celular viejo o una vereda sin señal no pueden costar el turno.
		expect(validarPaso('video', completos())).toEqual({});
	});
});

describe('el paso de envío', () => {
	it('exige la autorización de datos', () => {
		expect(validarPaso('envio', completos({ autoriza_datos: false })).autoriza_datos).toBeDefined();
		expect(validarPaso('envio', completos({ autoriza_datos: true }))).toEqual({});
	});

	it('corta el relato demasiado largo antes de mandarlo', () => {
		const d = completos({ autoriza_datos: true, descripcion_dano: 'x'.repeat(1001) });

		expect(validarPaso('envio', d).descripcion_dano).toBeDefined();
	});
});

describe('lo que se manda al servidor', () => {
	it('descarta el corregimiento en zona urbana', () => {
		// Si alguien eligió uno y después corrigió la zona, ese dato sobrante no
		// debe viajar. PHP hace lo mismo; esto evita mandar una contradicción.
		const enviado = paraEnviar(completos({ zona: 'URBANA', corregimiento: 'Robles' }));

		expect(enviado.corregimiento).toBe('');
	});

	it('conserva el corregimiento en zona rural', () => {
		const enviado = paraEnviar(completos({ zona: 'RURAL', corregimiento: 'Robles' }));

		expect(enviado.corregimiento).toBe('Robles');
	});

	it('normaliza cédula y teléfono como lo hace PHP', () => {
		const enviado = paraEnviar(completos());

		expect(enviado.documento).toBe('16234567');
		expect(enviado.telefono).toBe('3151234567');
	});

	it('lleva la trampa antirrobot, aunque esté vacía', () => {
		// Si dejara de mandarse, el servidor nunca vería el campo lleno y la
		// trampa quedaría desarmada sin que nada fallara.
		expect(paraEnviar(completos())).toHaveProperty('sitio_web');
	});
});

describe('lo que impide avanzar aparte de los campos', () => {
	it('deja pasar cuando no hay nada en curso', () => {
		expect(bloqueoDeAvance({ optimizandoFotos: false, videosSubiendo: 0 })).toBe('');
	});

	it('frena con una foto a medio preparar', () => {
		expect(bloqueoDeAvance({ optimizandoFotos: true, videosSubiendo: 0 })).not.toBe('');
	});

	it('frena con un video todavía subiendo, y dice qué se pierde', () => {
		// El servidor descarta el video incompleto porque no se puede reproducir.
		// Sin este freno, la persona ve «Solicitud registrada» y su video no
		// existe en ningún sitio, sin que nadie se lo diga.
		const aviso = bloqueoDeAvance({ optimizandoFotos: false, videosSubiendo: 1 });

		expect(aviso).not.toBe('');
		expect(aviso).toContain('perderá');
	});
});

describe('los videos que se le piden a cada persona', () => {
	function categoria(id: number, senal: string | null): CategoriaVideo {
		return {
			id,
			nombre: `Video ${id}`,
			instruccion: null,
			senal,
			obligatoria: true,
			segundos_min: 5,
			segundos_max: 120
		};
	}

	const catalogo = [
		categoria(1, 'PARED_AGRIETADA'),
		categoria(2, 'TECHO_TEJAS'),
		categoria(3, 'LUZ_DANADA')
	];

	it('pide uno por cada daño marcado, y solo esos', () => {
		const pedidos = videosQueSePiden(catalogo, ['TECHO_TEJAS', 'PARED_AGRIETADA']);

		expect(pedidos.map((c) => c.id)).toEqual([1, 2]);
	});

	it('no pide nada si no se marcó ningún daño', () => {
		// Sin daños marcados no hay nada que grabar, y el paso entero desaparece.
		expect(videosQueSePiden(catalogo, [])).toEqual([]);
	});

	it('ignora las categorías que no cuelgan de ningún daño', () => {
		// Son las del modelo anterior, que se le pedían a todo el mundo por
		// igual. No se borran —pueden tener videos grabados detrás— pero ya no
		// se le piden a nadie.
		const conHuerfana = [...catalogo, categoria(9, null)];

		expect(videosQueSePiden(conHuerfana, ['PARED_AGRIETADA']).map((c) => c.id)).toEqual([1]);
	});

	it('sabe cuáles faltan por grabar', () => {
		const pedidos = videosQueSePiden(catalogo, ['PARED_AGRIETADA', 'LUZ_DANADA']);

		expect(videosQueFaltan(pedidos, [1]).map((c) => c.id)).toEqual([3]);
		expect(videosQueFaltan(pedidos, [1, 3])).toEqual([]);
	});
});

describe('los videos que faltan frenan el envío', () => {
	it('frena y dice cuántos faltan', () => {
		const aviso = bloqueoDeAvance({
			optimizandoFotos: false,
			videosSubiendo: 0,
			videosFaltantes: 2,
			puedeGrabar: true
		});

		expect(aviso).toContain('2');
	});

	it('NO frena en un teléfono que no sabe grabar', () => {
		// La diferencia entre «obligatorio» e «imposible». Un celular viejo sin
		// MediaRecorder no graba por mucho que el formulario insista, y dejar a
		// esa familia sin turno de inspección sería el peor final posible.
		expect(
			bloqueoDeAvance({
				optimizandoFotos: false,
				videosSubiendo: 0,
				videosFaltantes: 3,
				puedeGrabar: false
			})
		).toBe('');
	});

	it('deja pasar cuando ya están todos', () => {
		expect(
			bloqueoDeAvance({
				optimizandoFotos: false,
				videosSubiendo: 0,
				videosFaltantes: 0,
				puedeGrabar: true
			})
		).toBe('');
	});
});
