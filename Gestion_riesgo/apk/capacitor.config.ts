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

	/**
	 * Sin esto, la primera pintura del WebView es un destello blanco antes de
	 * que cargue la hoja de estilos. En modo oscuro se nota mucho.
	 */
	backgroundColor: '#f1f5fc',

	plugins: {
		CapacitorSQLite: {
			androidIsEncryption: false
		},

		/**
		 * Desde Android 15, una aplicación que apunta a SDK 35 se dibuja de borde
		 * a borde por obligación: el contenido pasa POR DEBAJO de la barra de
		 * estado y de la de navegación. Sin tratarlo, la cabecera del formulario
		 * queda tapada y los botones de «Siguiente» y «Guardar» caen detrás de la
		 * barra de navegación, donde puede que ni se puedan tocar.
		 *
		 * `insetsHandling: 'css'` es la solución que documenta Capacitor: inyecta
		 * variables `--safe-area-inset-*` con los valores correctos, porque los
		 * WebView anteriores a la versión 140 devuelven mal los `env()` estándar.
		 *
		 * `style: 'DEFAULT'` deja que los iconos de las barras sigan al tema del
		 * teléfono, que es lo que espera cualquiera en 2026.
		 */
		SystemBars: {
			insetsHandling: 'css',
			style: 'DEFAULT'
		}
	}
};

export default config;
