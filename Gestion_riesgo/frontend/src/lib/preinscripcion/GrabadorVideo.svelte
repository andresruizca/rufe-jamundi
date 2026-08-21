<script lang="ts">
	// Grabar un video de una categoría, verlo y subirlo.
	//
	// Lo llena alguien de pie en el patio de su casa. Eso manda:
	//
	//  • Un botón grande y una cuenta atrás visible. Nada de controles finos.
	//  • La grabación se corta SOLA al llegar al máximo. Esperar a que la
	//    persona se acuerde de parar produce videos de dos minutos que no suben.
	//  • Se puede ver antes de subir y volver a grabar. Nadie manda a ciegas algo
	//    que va a esperar diez minutos en la cola.
	//  • Si el navegador no sabe grabar, se dice y se sigue: quedarse sin turno
	//    por un teléfono viejo sería lo contrario de lo que esto busca.

	import { onDestroy } from 'svelte';
	import { CheckCircle2, LoaderCircle, RotateCcw, Square, TriangleAlert, Video } from '@lucide/svelte';
	import {
		ErrorDeVideo, RESTRICCIONES, formatoSoportado, mimeBase, subirVideo
	} from './video';

	type Categoria = {
		id: number;
		nombre: string;
		instruccion: string | null;
		obligatoria: boolean;
		segundos_min: number;
		segundos_max: number;
	};

	type Props = {
		categoria: Categoria;
		carga: string | null;
		/** Se avisa al terminar para que el formulario sepa qué falta. */
		alSubir?: (categoriaId: number) => void;
	};

	let { categoria, carga, alSubir }: Props = $props();

	type Fase = 'listo' | 'grabando' | 'revisando' | 'subiendo' | 'subido' | 'error';

	let fase = $state<Fase>('listo');
	let error = $state('');
	let segundos = $state(0);
	let progreso = $state(0);
	let vistaPrevia = $state<string | null>(null);

	let video = $state<HTMLVideoElement | null>(null);
	let flujo: MediaStream | null = null;
	let grabadora: MediaRecorder | null = null;
	let trozos: Blob[] = [];
	// Reactivo: de él dependen el tamaño que se muestra y si el botón de enviar
	// está habilitado.
	let grabado = $state<Blob | null>(null);
	let mime = '';
	let reloj: ReturnType<typeof setInterval> | null = null;

	const soportado = formatoSoportado() !== null;
	const faltanSegundos = $derived(Math.max(0, categoria.segundos_min - segundos));

	onDestroy(() => limpiar());

	function limpiar() {
		if (reloj) clearInterval(reloj);
		reloj = null;
		flujo?.getTracks().forEach((t) => t.stop());
		flujo = null;
		if (vistaPrevia) URL.revokeObjectURL(vistaPrevia);
	}

	async function empezar() {
		error = '';
		const formato = formatoSoportado();

		if (!formato) {
			error = 'Este teléfono no permite grabar desde el navegador. Puede continuar sin el video.';
			fase = 'error';

			return;
		}

		try {
			flujo = await navigator.mediaDevices.getUserMedia(RESTRICCIONES);
		} catch {
			error = 'No se pudo abrir la cámara. Revise los permisos y vuelva a intentarlo.';
			fase = 'error';

			return;
		}

		mime = mimeBase(formato);
		trozos = [];
		segundos = 0;

		grabadora = new MediaRecorder(flujo, { mimeType: formato, videoBitsPerSecond: 800_000 });
		grabadora.ondataavailable = (e) => {
			if (e.data.size > 0) trozos.push(e.data);
		};
		grabadora.onstop = () => {
			grabado = new Blob(trozos, { type: mime });
			if (vistaPrevia) URL.revokeObjectURL(vistaPrevia);
			vistaPrevia = URL.createObjectURL(grabado);
			limpiar();
			fase = 'revisando';
		};

		grabadora.start(1000);
		fase = 'grabando';

		if (video) {
			video.srcObject = flujo;
			void video.play();
		}

		reloj = setInterval(() => {
			segundos += 1;

			// El corte automático es lo que mantiene el archivo dentro de lo que
			// una conexión rural puede subir.
			if (segundos >= categoria.segundos_max) detener();
		}, 1000);
	}

	function detener() {
		if (grabadora?.state === 'recording') grabadora.stop();
		if (reloj) clearInterval(reloj);
		reloj = null;
	}

	function repetir() {
		grabado = null;
		if (vistaPrevia) URL.revokeObjectURL(vistaPrevia);
		vistaPrevia = null;
		segundos = 0;
		fase = 'listo';
	}

	async function subir() {
		if (!grabado || !carga) return;

		fase = 'subiendo';
		progreso = 0;
		error = '';

		try {
			await subirVideo(carga, categoria.id, grabado, mime, segundos, (e) => {
				progreso = Math.round((e.subidos / e.total) * 100);
			});

			fase = 'subido';
			alSubir?.(categoria.id);
		} catch (e) {
			error =
				e instanceof ErrorDeVideo
					? e.message
					: 'No se pudo subir el video. Puede intentarlo otra vez o continuar sin él.';
			fase = 'revisando';
		}
	}
</script>

<div class="grabador" class:grabador--listo={fase === 'subido'}>
	<div class="grabador__cabecera">
		<p class="grabador__nombre">
			{categoria.nombre}
			{#if categoria.obligatoria}<span class="marca">recomendado</span>{/if}
		</p>
		{#if fase === 'subido'}
			<span class="grabador__ok"><CheckCircle2 size={16} aria-hidden="true" /> Enviado</span>
		{/if}
	</div>

	{#if categoria.instruccion}
		<p class="grabador__instruccion">{categoria.instruccion}</p>
	{/if}

	{#if !soportado}
		<p class="grabador__aviso">
			<TriangleAlert size={14} aria-hidden="true" />
			Este teléfono no permite grabar desde el navegador. Puede continuar sin este video.
		</p>
	{:else if fase === 'listo' || fase === 'error'}
		{#if error}<p class="grabador__aviso" role="alert">{error}</p>{/if}
		<button type="button" class="boton boton--suave grabador__accion" onclick={empezar}>
			<Video size={16} aria-hidden="true" />
			Grabar ({categoria.segundos_min}–{categoria.segundos_max} segundos)
		</button>
	{:else if fase === 'grabando'}
		<!-- svelte-ignore a11y_media_has_caption -->
		<video class="grabador__vista" bind:this={video} muted playsinline></video>

		<div class="grabador__contador" role="status" aria-live="polite">
			<span class="grabador__punto" aria-hidden="true"></span>
			{segundos}s de {categoria.segundos_max}s
			{#if faltanSegundos > 0}· faltan {faltanSegundos}s para lo mínimo{/if}
		</div>

		<button
			type="button"
			class="boton boton--suave grabador__accion"
			onclick={detener}
			disabled={faltanSegundos > 0}
		>
			<Square size={15} aria-hidden="true" />
			{faltanSegundos > 0 ? `Espere ${faltanSegundos}s…` : 'Detener y revisar'}
		</button>
	{:else if fase === 'revisando'}
		{#if error}<p class="grabador__aviso" role="alert">{error}</p>{/if}
		{#if vistaPrevia}
			<!-- svelte-ignore a11y_media_has_caption -->
			<video class="grabador__vista" src={vistaPrevia} controls playsinline></video>
		{/if}
		<p class="grabador__meta">
			{segundos} segundos · {grabado ? Math.round(grabado.size / 1024) : 0} KB
		</p>
		<div class="grabador__botones">
			<button type="button" class="boton boton--suave" onclick={repetir}>
				<RotateCcw size={15} aria-hidden="true" />
				Repetir
			</button>
			<button type="button" class="boton" onclick={subir} disabled={!carga}>Enviar este video</button>
		</div>
	{:else if fase === 'subiendo'}
		<div class="grabador__contador" role="status" aria-live="polite">
			<LoaderCircle size={15} class="girando" aria-hidden="true" />
			Enviando… {progreso}%
		</div>
		<div class="grabador__barra" role="progressbar" aria-valuenow={progreso} aria-valuemin={0} aria-valuemax={100}>
			<span style="width: {progreso}%"></span>
		</div>
	{:else if fase === 'subido'}
		<div class="grabador__botones">
			<button type="button" class="boton boton--suave" onclick={repetir}>
				<RotateCcw size={15} aria-hidden="true" />
				Grabar otro
			</button>
		</div>
	{/if}
</div>

<style>
	.grabador {
		padding: 0.8rem;
		border: 1px solid var(--color-border);
		border-radius: 0.6rem;
		background: var(--color-surface);
	}

	.grabador + :global(.grabador) {
		margin-top: 0.7rem;
	}

	.grabador--listo {
		border-color: var(--color-success);
	}

	.grabador__cabecera {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.5rem;
		flex-wrap: wrap;
	}

	.grabador__nombre {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		flex-wrap: wrap;
		margin: 0;
		font-size: 0.92rem;
		font-weight: 600;
	}

	.marca {
		padding: 0.05rem 0.4rem;
		border: 1px solid var(--color-primary);
		border-radius: 999px;
		font-size: 0.66rem;
		font-weight: 600;
		text-transform: uppercase;
		color: var(--color-primary-dark);
	}

	.grabador__ok {
		display: flex;
		align-items: center;
		gap: 0.25rem;
		font-size: 0.8rem;
		font-weight: 600;
		color: var(--color-success);
	}

	.grabador__instruccion {
		margin: 0.3rem 0 0.6rem;
		font-size: 0.83rem;
		line-height: 1.45;
		color: var(--color-muted);
	}

	.grabador__aviso {
		display: flex;
		align-items: flex-start;
		gap: 0.35rem;
		margin: 0 0 0.6rem;
		font-size: 0.8rem;
		line-height: 1.4;
		color: var(--aviso-alerta-texto);
	}

	.grabador__accion {
		width: 100%;
		justify-content: center;
		min-height: 2.8rem;
	}

	.grabador__vista {
		width: 100%;
		max-height: 60vh;
		border-radius: 0.5rem;
		background: #000;
	}

	.grabador__contador {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 0.5rem 0;
		font-size: 0.85rem;
		font-variant-numeric: tabular-nums;
	}

	.grabador__punto {
		width: 0.6rem;
		height: 0.6rem;
		border-radius: 50%;
		background: var(--color-danger);
		animation: latido 1s ease-in-out infinite;
	}

	@keyframes latido {
		50% {
			opacity: 0.25;
		}
	}

	@media (prefers-reduced-motion: reduce) {
		.grabador__punto {
			animation: none;
		}
	}

	.grabador__meta {
		margin: 0.4rem 0;
		font-size: 0.78rem;
		color: var(--color-muted);
	}

	.grabador__botones {
		display: flex;
		gap: 0.5rem;
		flex-wrap: wrap;
	}

	.grabador__botones .boton {
		flex: 1;
		justify-content: center;
	}

	.grabador__barra {
		height: 0.4rem;
		border-radius: 999px;
		background: var(--color-border);
		overflow: hidden;
	}

	.grabador__barra span {
		display: block;
		height: 100%;
		background: var(--color-primary);
	}
</style>
