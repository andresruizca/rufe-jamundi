import type { CapacitorConfig } from '@capacitor/cli';

/**
 * Configuración del APK ciudadano.
 *
 * `webDir` apunta al build propio de esta carpeta, no al de `frontend/`: este
 * proyecto es autónomo. El formulario está copiado en `src/formulario/` y
 * `scripts/comparar-con-web.mjs` avisa cuando se separa del original.
 */
const config: CapacitorConfig = {
	appId: 'co.gov.jamundi.sgr',
	appName: 'Inspección de Vivienda · Jamundí',
	webDir: 'build',

	android: {
		// Sin depuración web en el APK que se publica: expondría los datos de las
		// familias a cualquiera que conecte el teléfono a un computador.
		webContentsDebuggingEnabled: false,
		// El teclado no debe tapar el campo que se está llenando. En un formulario
		// de cuatro pasos con la mano izquierda sosteniendo el teléfono, eso es la
		// diferencia entre poder escribir la dirección y no poder.
		useLegacyBridge: false
	},

	server: {
		// HTTPS también dentro del WebView. Con `http`, Android 9+ bloquea el
		// tráfico en claro y algunas APIs del navegador —la cámara, entre ellas—
		// se niegan a funcionar en un origen inseguro.
		androidScheme: 'https'
	},

	plugins: {
		CapacitorSQLite: {
			androidIsEncryption: false
		}
	}
};

export default config;
