<script lang="ts">
	// La cámara para fotografiar una cédula, con la silueta encima.
	//
	// ── Por qué no vale el `capture` del navegador ────────────────────────────
	//
	// Un `<input type="file" capture>` abre la cámara del sistema, y encima de
	// esa cámara no se puede dibujar nada: es una aplicación aparte. La persona
	// queda sola frente a un cuadro vacío, y lo que llega son cédulas torcidas,
	// de lejos, con media cara del documento fuera.
	//
	// Con `getUserMedia` el video es un elemento de la página, así que encima
	// cabe una silueta con la proporción exacta del documento y un aviso de que
	// gire el teléfono. Quien encuadra dentro de la silueta manda una foto que
	// se lee.
	//
	// ── Y por qué sigue existiendo el camino de antes ────────────────────────
	//
	// `getUserMedia` no está en navegadores viejos, exige HTTPS, y la persona
	// puede negar el permiso. En cualquiera de esos casos se cae al input de
	// siempre. Quedarse sin poder mandar la solicitud porque el navegador no
	// tiene una función moderna sería lo contrario de lo que este formulario
	// busca.

	import { onDestroy } from 'svelte';
	import { Camera, ImagePlus, RefreshCw, TriangleAlert, X } from '@lucide/svelte';
	import GirarTelefono from './GirarTelefono.svelte';
	import { usarOrientacion } from './orientacion.svelte';

	let {
		titulo,
		ayuda,
		alTomar,
		alCerrar
	}: {
		titulo: string;
		ayuda: string;
		/** La foto ya recortada, lista para el gestor de evidencias. */
		alTomar: (archivo: File) => void;
		alCerrar: () => void;
	} = $props();

	const orientacion = usarOrientacion();

	let video = $state<HTMLVideoElement | null>(null);
	let flujo: MediaStream | null = null;
	let error = $state('');
	let lista = $state(false);
	let tomando = $state(false);

	/**
	 * La proporción de una cédula colombiana: 85,6 × 54 mm, la ISO/IEC 7810
	 * ID-1, la misma de cualquier tarjeta bancaria.
	 *
	 * Se escribe como número y no como «1.58» redondeado: la silueta y el
	 * recorte usan el MISMO valor, y medio milímetro de diferencia entre los dos
	 * deja una franja negra en el borde de la foto.
	 */
	const PROPORCION = 85.6 / 54;

	/** Cuánto del ancho de la pantalla ocupa la silueta. */
	const ANCHO_SILUETA = 0.86;

	$effect(() => {
		void abrir();
	});

	onDestroy(cerrarFlujo);

	async function abrir() {
		if (flujo !== null) return;

		try {
			flujo = await navigator.mediaDevices.getUserMedia({
				video: {
					facingMode: { ideal: 'environment' },
					// Se pide resolución alta porque de la foto hay que poder LEER
					// un número de cédula. El gestor la reduce después; lo que no
					// se puede es recuperar detalle que la cámara no capturó.
					width: { ideal: 1920 },
					height: { ideal: 1080 }
				},
				audio: false
			});

			if (video) {
				video.srcObject = flujo;
				await video.play();
				lista = true;
			}
		} catch {
			// Permiso negado, cámara ocupada, o un navegador sin getUserMedia. En
			// los tres casos la salida es la misma: el camino de siempre.
			error = 'No pudimos abrir la cámara. Puede tomar la foto con la cámara de su teléfono.';
		}
	}

	function cerrarFlujo() {
		flujo?.getTracks().forEach((t) => t.stop());
		flujo = null;
		lista = false;
	}

	function cerrar() {
		cerrarFlujo();
		alCerrar();
	}

	/**
	 * Recorta lo que hay dentro de la silueta y lo entrega como archivo.
	 *
	 * Se recorta AQUÍ y no se manda el cuadro entero: la silueta le prometió a
	 * la persona que lo que quedara dentro era lo que iba a mandar. Si después
	 * llegara la foto completa con la mesa y el suelo, la silueta habría sido un
	 * adorno y quien revisa tendría que ampliar a mano.
	 */
	async function disparar() {
		if (!video || !lista || tomando) return;

		tomando = true;

		try {
			const anchoFuente = video.videoWidth;
			const altoFuente = video.videoHeight;

			// La silueta se dibuja sobre el video TAL COMO SE VE, y el video se
			// muestra con `object-fit: cover`: si la cámara entrega un cuadro más
			// alto o más ancho que el hueco, sobra por algún lado. Hay que recortar
			// en las coordenadas de la fuente, no en las de la pantalla.
			const recorteAncho = Math.round(anchoFuente * ANCHO_SILUETA);
			const recorteAlto = Math.round(recorteAncho / PROPORCION);

			// Si la cámara entrega un cuadro más estrecho que alto —un teléfono de
			// pie— la silueta no cabe a lo ancho: se ajusta por el alto.
			const cabe = recorteAlto <= altoFuente;
			const ancho = cabe ? recorteAncho : Math.round(altoFuente * ANCHO_SILUETA * PROPORCION);
			const alto = cabe ? recorteAlto : Math.round(altoFuente * ANCHO_SILUETA);

			const x = Math.round((anchoFuente - ancho) / 2);
			const y = Math.round((altoFuente - alto) / 2);

			const lienzo = document.createElement('canvas');
			lienzo.width = ancho;
			lienzo.height = alto;

			const pincel = lienzo.getContext('2d');
			if (!pincel) throw new Error('sin lienzo');

			pincel.drawImage(video, x, y, ancho, alto, 0, 0, ancho, alto);

			const blob = await new Promise<Blob | null>((resolver) =>
				// 0.92 y no 1: por encima de eso el archivo crece mucho y no se lee
				// mejor. El gestor lo vuelve a comprimir a WebP antes de subirlo.
				lienzo.toBlob(resolver, 'image/jpeg', 0.92)
			);

			if (!blob) throw new Error('sin imagen');

			alTomar(new File([blob], `cedula-${Date.now()}.jpg`, { type: 'image/jpeg' }));
			cerrar();
		} catch {
			error = 'No se pudo tomar la foto. Inténtelo otra vez o use la cámara de su teléfono.';
		} finally {
			tomando = false;
		}
	}
</script>

<div class="camara" role="dialog" aria-modal="true" aria-label={titulo}>
	<header class="camara__barra">
		<span class="camara__titulo">{titulo}</span>
		<button type="button" class="camara__cerrar" onclick={cerrar} aria-label="Cerrar la cámara">
			<X size={20} aria-hidden="true" />
		</button>
	</header>

	<div class="camara__visor">
		<!-- svelte-ignore a11y_media_has_caption -->
		<video bind:this={video} class="camara__video" playsinline muted autoplay></video>

		{#if lista}
			<!--
				La silueta. Es un marco con la proporción exacta del documento y el
				resto oscurecido: se ve dónde poner la cédula sin leer una sola
				instrucción, que es lo que hace falta cuando se sujeta el teléfono
				con una mano.
			-->
			<div class="silueta" aria-hidden="true">
				<div class="silueta__hueco" style="--proporcion: {PROPORCION}; --ancho: {ANCHO_SILUETA}">
					<span class="silueta__esquina silueta__esquina--si"></span>
					<span class="silueta__esquina silueta__esquina--sd"></span>
					<span class="silueta__esquina silueta__esquina--ii"></span>
					<span class="silueta__esquina silueta__esquina--id"></span>
				</div>
			</div>

			<p class="camara__ayuda">{ayuda}</p>
		{/if}

		{#if orientacion.actual === 'vertical' && lista}
			<GirarTelefono texto="Gire el teléfono: la cédula entra completa y se lee mejor" />
		{/if}

		{#if error}
			<div class="camara__error" role="alert">
				<TriangleAlert size={20} aria-hidden="true" />
				<p>{error}</p>
			</div>
		{/if}

		{#if !lista && !error}
			<p class="camara__esperando">Abriendo la cámara…</p>
		{/if}
	</div>

	<footer class="camara__pie">
		{#if error}
			<button type="button" class="boton" onclick={cerrar}>
				<ImagePlus size={17} aria-hidden="true" />
				Usar la cámara del teléfono
			</button>
			<button type="button" class="camara__reintentar" onclick={() => { error = ''; void abrir(); }}>
				<RefreshCw size={14} aria-hidden="true" />
				Reintentar
			</button>
		{:else}
			<!--
				El disparador sigue funcionando con el teléfono de pie. El aviso de
				girar insiste; no encierra. Quien tiene el giro de pantalla
				bloqueado no puede obedecerlo aunque quiera.
			-->
			<button
				type="button"
				class="camara__disparar"
				disabled={!lista || tomando}
				onclick={disparar}
				aria-label="Tomar la foto"
			>
				<Camera size={26} aria-hidden="true" />
			</button>
		{/if}
	</footer>
</div>

<style>
	.camara {
		position: fixed;
		inset: 0;
		z-index: 80;
		display: flex;
		flex-direction: column;
		background: #000;
	}

	.camara__barra {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.6rem;
		padding: 0.7rem 0.9rem;
		color: #fff;
		/* Deja libre la muesca y la barra de estado del teléfono. */
		padding-top: max(0.7rem, env(safe-area-inset-top));
	}

	.camara__titulo {
		font-weight: 600;
		font-size: 0.95rem;
	}

	.camara__cerrar {
		display: grid;
		place-items: center;
		border: none;
		background: rgb(255 255 255 / 0.14);
		color: #fff;
		border-radius: 999px;
		width: 2.2rem;
		height: 2.2rem;
		cursor: pointer;
	}

	.camara__visor {
		position: relative;
		flex: 1;
		min-height: 0;
		overflow: hidden;
	}

	.camara__video {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
	}

	/* ── La silueta ──────────────────────────────────────────────────────────
	   Un marco con la proporción del documento, y todo lo de fuera oscurecido.
	   El oscurecido se hace con una sombra enorme hacia fuera y no con cuatro
	   paneles: así el hueco es exactamente el rectángulo, sin costuras de un
	   píxel entre los bordes. */
	.silueta {
		position: absolute;
		inset: 0;
		display: grid;
		place-items: center;
		pointer-events: none;
	}

	.silueta__hueco {
		position: relative;
		width: calc(var(--ancho) * 100%);
		aspect-ratio: var(--proporcion);
		/* Si la tarjeta no cabe a lo ancho —teléfono de pie— se limita por el
		   alto, igual que hace el recorte al disparar. */
		max-height: calc(var(--ancho) * 100%);
		max-width: calc(var(--ancho) * 100vh * var(--proporcion));
		border: 2px solid rgb(255 255 255 / 0.9);
		border-radius: 10px;
		box-shadow: 0 0 0 100vmax rgb(0 0 0 / 0.55);
	}

	/* Las cuatro esquinas marcadas: es lo que hace que se lea como «encaje aquí»
	   y no como un rectángulo dibujado por casualidad. */
	.silueta__esquina {
		position: absolute;
		width: 1.4rem;
		height: 1.4rem;
		border: 3px solid var(--color-primary, #4f8ef7);
	}

	.silueta__esquina--si {
		top: -3px;
		left: -3px;
		border-right: none;
		border-bottom: none;
		border-radius: 10px 0 0 0;
	}

	.silueta__esquina--sd {
		top: -3px;
		right: -3px;
		border-left: none;
		border-bottom: none;
		border-radius: 0 10px 0 0;
	}

	.silueta__esquina--ii {
		bottom: -3px;
		left: -3px;
		border-right: none;
		border-top: none;
		border-radius: 0 0 0 10px;
	}

	.silueta__esquina--id {
		bottom: -3px;
		right: -3px;
		border-left: none;
		border-top: none;
		border-radius: 0 0 10px 0;
	}

	.camara__ayuda {
		position: absolute;
		left: 50%;
		bottom: 0.8rem;
		transform: translateX(-50%);
		margin: 0;
		max-width: 92%;
		text-align: center;
		color: #fff;
		font-size: 0.85rem;
		line-height: 1.4;
		text-shadow: 0 1px 3px rgb(0 0 0 / 0.8);
		pointer-events: none;
	}

	.camara__esperando,
	.camara__error {
		position: absolute;
		inset: 0;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 0.6rem;
		margin: 0;
		padding: 1.5rem;
		text-align: center;
		color: #fff;
		background: rgb(0 0 0 / 0.6);
	}

	.camara__error p {
		margin: 0;
		max-width: 22rem;
		line-height: 1.5;
	}

	.camara__pie {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 0.6rem;
		padding: 1rem;
		padding-bottom: max(1rem, env(safe-area-inset-bottom));
	}

	.camara__disparar {
		display: grid;
		place-items: center;
		width: 4.2rem;
		height: 4.2rem;
		border-radius: 999px;
		border: 4px solid rgb(255 255 255 / 0.35);
		background: #fff;
		color: #111;
		cursor: pointer;
	}

	.camara__disparar:disabled {
		opacity: 0.45;
		cursor: default;
	}

	.camara__reintentar {
		display: inline-flex;
		align-items: center;
		gap: 0.35rem;
		border: none;
		background: none;
		color: rgb(255 255 255 / 0.75);
		font-size: 0.83rem;
		text-decoration: underline;
		cursor: pointer;
	}

	/* Apaisado hay poco alto: los controles se van al costado para no comerse
	   el visor, que es donde la persona está mirando. */
	@media (orientation: landscape) and (max-height: 30rem) {
		.camara {
			flex-direction: row;
		}

		.camara__barra {
			flex-direction: column-reverse;
			justify-content: flex-end;
			padding: 0.6rem;
		}

		.camara__titulo {
			writing-mode: vertical-rl;
			font-size: 0.8rem;
		}

		.camara__pie {
			justify-content: center;
			padding: 0.6rem;
		}
	}
</style>
