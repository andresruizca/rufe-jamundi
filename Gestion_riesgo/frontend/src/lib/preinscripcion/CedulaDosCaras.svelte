<script lang="ts">
	// Las dos caras de la cédula, cada una en su casilla.
	//
	// Se pedía una sola foto y con eso no basta: en la cédula colombiana los
	// datos están repartidos. Delante van el retrato, los nombres y el NUIP;
	// detrás va la zona de lectura mecánica, que es la que permite comprobar que
	// el número y la fecha de nacimiento son los que el documento dice.
	//
	// ── Por qué dos casillas y no «suba 2 fotos» ─────────────────────────────
	//
	// Con dos fotos del mismo tipo, nadie puede saber si la persona subió las
	// dos caras o dos veces la misma. Pasa constantemente. Con una casilla por
	// cara, la pantalla dice cuál falta y el servidor sabe cuál recibió.
	//
	// Cada casilla lleva un dibujo de la cara que le toca. No es adorno: entre
	// «la de atrás» y ver el dibujo del código de barras, la segunda no se
	// interpreta mal.

	import { Camera, Check, ImagePlus, Trash2 } from '@lucide/svelte';
	import CamaraFoto from '$lib/camara/CamaraFoto.svelte';
	import { pedirApaisado } from '$lib/camara/orientacion.svelte';
	import type { GestorEvidencias } from '$lib/rufe-form/evidencias.svelte';
	import type { TipoEvidencia } from '$lib/rufe-form/tipos';

	let { gestor }: { gestor: GestorEvidencias } = $props();

	/**
	 * La proporción de una cédula colombiana: 85,6 × 54 mm, la ISO/IEC 7810
	 * ID-1, la misma de cualquier tarjeta bancaria.
	 *
	 * Se escribe como número y no como «1.58» redondeado: la silueta de la
	 * cámara y el recorte usan el MISMO valor, y medio milímetro de diferencia
	 * entre los dos deja una franja negra en el borde de la foto.
	 */
	const PROPORCION = 85.6 / 54;

	type Cara = {
		tipo: TipoEvidencia;
		titulo: string;
		pista: string;
		ayudaCamara: string;
	};

	const CARAS: Cara[] = [
		{
			tipo: 'PRE_CEDULA',
			titulo: 'Por delante',
			pista: 'La cara donde está su foto y sus nombres.',
			ayudaCamara: 'Encaje la cédula en el marco. Que se lean los nombres y el número.'
		},
		{
			tipo: 'PRE_CEDULA_REVERSO',
			titulo: 'Por detrás',
			pista: 'La cara del código de barras y las líneas de letras y números.',
			ayudaCamara: 'Encaje la cédula en el marco. Que se lean las tres líneas de abajo.'
		}
	];

	/** Qué cara se está fotografiando, si hay alguna cámara abierta. */
	let capturando = $state<Cara | null>(null);

	/**
	 * Abrir la cámara, y de paso poner la pantalla apaisada.
	 *
	 * El giro se pide AQUÍ y no dentro de la cámara: pantalla completa solo se
	 * concede mientras dure la activación que deja este toque, y dentro de la
	 * cámara ya se ha esperado al permiso del aparato. Es una comodidad, así que
	 * no se espera el resultado ni se comprueba: si falla, la cámara se abre
	 * igual y queda el aviso de girar.
	 */
	function abrirCamara(cara: Cara) {
		void pedirApaisado();
		capturando = cara;
	}

	let entradas = $state<Record<string, HTMLInputElement | null>>({});

	/** Lo que el gestor rechazó, si rechazó algo. Ver `poner`. */
	let aviso = $state('');

	function fotoDe(tipo: TipoEvidencia) {
		return gestor.archivosDe(tipo)[0] ?? null;
	}

	/**
	 * Guarda la foto de esta cara, reemplazando la que hubiera.
	 *
	 * ── El fallo que esto corrige ────────────────────────────────────────────
	 *
	 * El cupo de cada cara es UNA foto. Sin quitar la anterior, el gestor
	 * rechazaba la nueva, y lo hacía EN SILENCIO: su aviso (`gestor.error`) no
	 * se dibuja en ningún sitio de este formulario. La persona pulsaba «Repetir
	 * la foto», encuadraba en la silueta, disparaba, la cámara se cerraba… y
	 * seguía viendo la foto borrosa de antes, sin que nada explicara por qué.
	 *
	 * Con la cédula ahora obligatoria, repetir es el gesto natural después de
	 * una foto movida, así que era el camino más transitado del formulario.
	 *
	 * No se espera a `quitar`: esa función saca la foto de la lista de una vez y
	 * solo DESPUÉS va al servidor a borrarla. Esperar esa ida y vuelta con mala
	 * señal dejaría la casilla vacía varios segundos justo después de disparar.
	 * Lo que aquí hace falta —que el cupo quede libre— ya ocurrió.
	 */
	async function poner(archivos: FileList | File[], tipo: TipoEvidencia) {
		const anterior = fotoDe(tipo);
		if (anterior) void gestor.quitar(anterior.uid);

		gestor.error = null;
		await gestor.agregar(archivos, tipo);
		aviso = gestor.error ?? '';
	}

	async function alTomar(archivo: File, tipo: TipoEvidencia) {
		await poner([archivo], tipo);
	}

	async function alElegir(evento: Event, tipo: TipoEvidencia) {
		const entrada = evento.currentTarget as HTMLInputElement;

		if (entrada.files && entrada.files.length > 0) {
			// Solo la primera: son dos casillas de una cara cada una, y quien elige
			// tres archivos de la galería no está pidiendo tres veces la misma cara.
			await poner([entrada.files[0]], tipo);
		}

		// Sin esto, volver a elegir el MISMO archivo no dispara `change` y la
		// persona cree que la aplicación se colgó.
		entrada.value = '';
	}
</script>

<div class="caras">
	{#each CARAS as cara (cara.tipo)}
		{@const foto = fotoDe(cara.tipo)}
		<section class="cara" class:cara--lista={foto?.estado === 'listo'}>
			<header class="cara__cabeza">
				<h4 class="cara__titulo">{cara.titulo}</h4>
				{#if foto?.estado === 'listo'}
					<span class="cara__marca"><Check size={13} aria-hidden="true" /> Lista</span>
				{:else if foto}
					<span class="cara__marca cara__marca--espera">Subiendo…</span>
				{/if}
			</header>

			<div class="cara__lienzo">
				{#if foto?.vistaPrevia}
					<img class="cara__previa" src={foto.vistaPrevia} alt="Foto de la cédula, {cara.titulo}" />
				{:else if cara.tipo === 'PRE_CEDULA'}
					<!--
						Un dibujo, no la foto de una cédula real. Enseñar un documento
						de verdad —aunque fuera de muestra— en una pantalla pública es
						repartir el aspecto de un documento de identidad con datos
						dentro. El dibujo dice lo mismo: cuál de las dos caras es.
					-->
					<svg class="cara__dibujo" viewBox="0 0 128 81" role="img" aria-label="Cara con la foto y los nombres">
						<rect x="1" y="1" width="126" height="79" rx="6" />
						<rect class="relleno" x="9" y="9" width="30" height="38" rx="3" />
						<line x1="48" y1="14" x2="112" y2="14" />
						<line x1="48" y1="24" x2="98" y2="24" />
						<line x1="48" y1="34" x2="106" y2="34" />
						<line x1="48" y1="44" x2="88" y2="44" />
						<line x1="9" y1="58" x2="60" y2="58" />
						<line x1="9" y1="68" x2="44" y2="68" />
					</svg>
				{:else}
					<svg class="cara__dibujo" viewBox="0 0 128 81" role="img" aria-label="Cara con el código de barras">
						<rect x="1" y="1" width="126" height="79" rx="6" />
						<rect class="relleno" x="86" y="8" width="33" height="33" rx="2" />
						<line class="mono" x1="10" y1="54" x2="118" y2="54" />
						<line class="mono" x1="10" y1="64" x2="118" y2="64" />
						<line class="mono" x1="10" y1="74" x2="92" y2="74" />
					</svg>
				{/if}
			</div>

			<p class="cara__pista">{cara.pista}</p>

			<div class="cara__acciones">
				<button type="button" class="boton boton--suave" onclick={() => abrirCamara(cara)}>
					<Camera size={16} aria-hidden="true" />
					{foto ? 'Repetir la foto' : 'Tomar la foto'}
				</button>

				<!-- La galería, para quien ya la tenga fotografiada o para cuando la
				     cámara del navegador no esté disponible. -->
				<button
					type="button"
					class="cara__archivo"
					onclick={() => entradas[cara.tipo]?.click()}
				>
					<ImagePlus size={14} aria-hidden="true" />
					Elegir archivo
				</button>

				{#if foto}
					<button
						type="button"
						class="cara__archivo cara__archivo--quitar"
						onclick={() => gestor.quitar(foto.uid)}
					>
						<Trash2 size={14} aria-hidden="true" />
						Quitar
					</button>
				{/if}
			</div>

			{#if foto?.estado === 'error'}
				<p class="cara__error" role="alert">{foto.error ?? 'No se pudo subir esta foto.'}</p>
			{/if}

			<input
				bind:this={entradas[cara.tipo]}
				class="oculto"
				type="file"
				accept="image/*"
				onchange={(e) => alElegir(e, cara.tipo)}
			/>
		</section>
	{/each}
</div>

<!-- Lo que el gestor rechace deja de ser invisible. Antes se guardaba en
     `gestor.error` y no se dibujaba en ninguna parte, así que un rechazo se
     veía como una aplicación que no responde. -->
{#if aviso}
	<p class="caras__aviso" role="alert">{aviso}</p>
{/if}

{#if capturando}
	<CamaraFoto
		titulo="Cédula — {capturando.titulo.toLowerCase()}"
		ayuda={capturando.ayudaCamara}
		proporcion={PROPORCION}
		nombreBase="cedula"
		textoGiro="Gire el teléfono: la cédula entra completa y se lee mejor"
		alTomar={(archivo) => alTomar(archivo, capturando!.tipo)}
		alUsarCamaraSistema={() => entradas[capturando!.tipo]?.click()}
		alCerrar={() => (capturando = null)}
	/>
{/if}

<style>
	.caras {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
		gap: 0.8rem;
	}

	.cara {
		display: flex;
		flex-direction: column;
		gap: 0.5rem;
		border: 1px solid var(--color-border);
		border-radius: 10px;
		padding: 0.8rem;
		background: var(--color-surface-alt);
	}

	.cara--lista {
		border-color: var(--color-success);
	}

	.cara__cabeza {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.5rem;
	}

	.cara__titulo {
		margin: 0;
		font-size: 0.95rem;
		font-weight: 700;
	}

	.cara__marca {
		display: inline-flex;
		align-items: center;
		gap: 0.25rem;
		border-radius: 999px;
		padding: 0.12rem 0.5rem;
		font-size: 0.72rem;
		font-weight: 600;
		background: var(--color-success-bg);
		color: var(--color-success);
	}

	.cara__marca--espera {
		background: var(--color-info-bg);
		color: var(--color-info);
	}

	/* El hueco tiene la proporción del documento, así la vista previa y el
	   dibujo ocupan el mismo sitio y la casilla no salta al tomar la foto. */
	.cara__lienzo {
		aspect-ratio: 85.6 / 54;
		display: grid;
		place-items: center;
		overflow: hidden;
		border-radius: 8px;
		background: var(--color-surface);
	}

	.cara__previa {
		width: 100%;
		height: 100%;
		object-fit: cover;
	}

	.cara__dibujo {
		width: 100%;
		height: 100%;
		fill: none;
		stroke: var(--color-muted);
		stroke-width: 2;
		stroke-linecap: round;
		opacity: 0.55;
	}

	.cara__dibujo .relleno {
		fill: var(--color-muted);
		stroke: none;
		opacity: 0.35;
	}

	/* Las tres líneas de la zona de lectura mecánica, más juntas y parejas: es
	   lo que la distingue de un texto cualquiera de un vistazo. */
	.cara__dibujo .mono {
		stroke-dasharray: 3 2;
	}

	.cara__pista {
		margin: 0;
		font-size: 0.8rem;
		line-height: 1.4;
		color: var(--color-muted);
	}

	.cara__acciones {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 0.5rem;
	}

	.cara__archivo {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		border: none;
		background: none;
		color: var(--color-muted);
		font-size: 0.8rem;
		text-decoration: underline;
		cursor: pointer;
		padding: 0.25rem 0;
	}

	.cara__archivo:hover {
		color: var(--color-text);
	}

	.cara__archivo--quitar:hover {
		color: var(--color-danger);
	}

	.cara__error {
		margin: 0;
		font-size: 0.8rem;
		color: var(--color-danger);
	}

	.caras__aviso {
		margin: 0.6rem 0 0;
		font-size: 0.83rem;
		line-height: 1.45;
		color: var(--color-danger);
	}

	.oculto {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
	}
</style>
