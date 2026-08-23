import type { CapacitorConfig } from '@capacitor/cli';

/**
 * Configuración del proyecto iOS.
 *
 * ── Por qué `webDir` apunta a `../apk/build` ────────────────────────────────
 *
 * Es la ÚNICA referencia que sale de esta carpeta, y solo lee. El formulario ya
 * está copiado una vez en `apk/src/formulario/` desde la web; copiarlo otra vez
 * aquí serían TRES copias de las mismas reglas de validación, los mismos ocho
 * dibujos y el mismo texto de la Ley 1581, separándose cada una por su lado.
 *
 * Ningún archivo del proyecto iOS vive fuera de `ios/`. Lo que se comparte es el
 * resultado de compilar la web, que es un artefacto, no código fuente.
 *
 * ── Por qué el mismo appId ─────────────────────────────────────────────────
 *
 * `co.gov.jamundi.sgr` es el mismo que el APK. En Android e iOS son espacios de
 * nombres distintos, así que no chocan, y así la Alcaldía tiene un solo
 * identificador que registrar.
 */
const config: CapacitorConfig = {
	appId: 'co.gov.jamundi.sgr',
	appName: 'Inspección de Vivienda',
	webDir: '../apk/build',

	/**
	 * `localhost` y no otra cosa, y esto NO es negociable en iOS.
	 *
	 * `getUserMedia` —lo que usa el grabador de video— exige un contexto seguro.
	 * WKWebView considera seguro `capacitor://localhost`; cualquier otro nombre
	 * de servidor deja de serlo y la cámara deja de existir para la aplicación,
	 * sin ningún error que lo explique.
	 */
	server: {
		iosScheme: 'capacitor',
		hostname: 'localhost'
	},

	/** Sin esto, la primera pintura es un destello blanco antes de la hoja de estilos. */
	backgroundColor: '#f1f5fc',

	ios: {
		/**
		 * El WebView no rebota al final de la lista. En una página web se espera;
		 * dentro de una aplicación delata que hay un navegador debajo.
		 */
		scrollEnabled: true,
		contentInset: 'always'
	},

	plugins: {
		CapacitorSQLite: {
			/**
			 * iOS guarda la base en el contenedor de la aplicación. `iosDatabaseLocation`
			 * la deja en Library/CapacitorDatabase, que es lo que iCloud NO respalda
			 * por omisión — y eso es lo que se quiere: son fotos de cédula y datos de
			 * terceros, no tienen por qué acabar en la nube de nadie.
			 */
			iosDatabaseLocation: 'Library/CapacitorDatabase',
			iosIsEncryption: false
		},

		SystemBars: {
			insetsHandling: 'css',
			style: 'DEFAULT'
		}
	}
};

export default config;
