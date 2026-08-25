<script lang="ts">
	// Compartir el enlace del formulario ciudadano desde la bandeja.
	//
	// La bandeja es donde vive el equipo que atiende las solicitudes, así que es
	// donde suena el teléfono: «¿por dónde me inscribo?». Hasta ahora la
	// respuesta era dictar una dirección web de memoria — y una dirección
	// dictada se escribe mal.
	//
	// Es el enlace GENERAL, no dirigido a nadie. Para mandárselo a una persona
	// concreta de la base del RUFE está el call center, que sabe a quién llama.
	// Aquí no se sabe: puede ser para un vecino, para un grupo de WhatsApp del
	// barrio o para pegarlo en una cartelera.

	import { tick } from 'svelte';
	import { Check, Copy, MessageCircle, QrCode, Share2, X } from '@lucide/svelte';
	import { page } from '$app/state';
	import { enlaceDePreinscripcion } from '$lib/compartir';

	let abierto = $state(false);
	let copiado = $state<'enlace' | 'mensaje' | null>(null);
	let boton = $state<HTMLButtonElement | null>(null);
	let panel = $state<HTMLDivElement | null>(null);

	const enlace = $derived(enlaceDePreinscripcion(page.url.origin));

	/**
	 * El mensaje listo para pegar en un chat.
	 *
	 * Distinto del que manda el call center: aquel saluda por su nombre a una
	 * persona concreta. Este va a un grupo o a alguien que acaba de preguntar,
	 * así que dice de dónde viene y qué hace falta tener a mano — la cédula y el
	 * teléfono cargado — que es lo que hace que no se abandone a mitad.
	 */
	const mensaje = $derived(
		'Alcaldía de Jamundí · Gestión del Riesgo\n\n' +
			'Si su vivienda resultó afectada, registre la afectación en este formulario. ' +
			'Toma unos minutos desde el celular y puede adjuntar fotos del daño:\n\n' +
			`${enlace}\n\n` +
			'Tenga a mano su cédula. Al terminar recibirá un número de radicado: guárdelo, es su constancia.'
	);

	async function alternar() {
		abierto = !abierto;
		copiado = null;

		if (abierto) {
			// `tick()` no es opcional: el panel no existe en el DOM hasta que
			// Svelte lo dibuja, y eso pasa DESPUÉS de esta línea.
			await tick();
			panel?.querySelector('button')?.focus();
		}
	}

	function cerrar(devolverFoco = true) {
		abierto = false;
		copiado = null;
		if (devolverFoco) boton?.focus();
	}

	async function copiar(que: 'enlace' | 'mensaje') {
		try {
			await navigator.clipboard.writeText(que === 'enlace' ? enlace : mensaje);
			copiado = que;
			setTimeout(() => (copiado = null), 2500);
		} catch {
			// Sin permiso de portapapeles el enlace sigue a la vista y se puede
			// seleccionar a mano. No se avisa de un fallo que no impide nada.
		}
	}

	async function compartir() {
		try {
			await navigator.share({ title: 'Registro de vivienda afectada', text: mensaje });
			cerrar(false);
		} catch {
			// Cerrar el menú del sistema lanza; no es un error que contar.
		}
	}

	// Un clic fuera cierra. Sin esto el panel se queda abierto tapando la tabla,
	// y quien lo abrió por curiosidad tiene que buscar cómo quitarlo.
	$effect(() => {
		if (!abierto) return;

		const fuera = (e: MouseEvent) => {
			const t = e.target as Node;
			if (!panel?.contains(t) && !boton?.contains(t)) cerrar(false);
		};

		document.addEventListener('pointerdown', fuera);

		return () => document.removeEventListener('pointerdown', fuera);
	});
</script>

<div class="compartir">
	<button
		type="button"
		class="boton boton--suave compartir__boton"
		bind:this={boton}
		aria-expanded={abierto}
		aria-haspopup="dialog"
		onclick={alternar}
	>
		<Share2 size={15} aria-hidden="true" />
		Compartir el formulario
	</button>

	{#if abierto}
		<div
			class="panel"
			bind:this={panel}
			role="dialog"
			aria-label="Compartir el formulario de pre-inscripción"
			tabindex="-1"
			onkeydown={(e) => {
				if (e.key === 'Escape') cerrar();
			}}
		>
			<div class="panel__cabeza">
				<h3 class="panel__titulo">Formulario para la ciudadanía</h3>
				<button type="button" class="panel__cerrar" onclick={() => cerrar()} aria-label="Cerrar">
					<X size={15} aria-hidden="true" />
				</button>
			</div>

			<p class="panel__nota">
				Es el formulario público. Quien lo abra puede registrar su vivienda afectada sin tener
				cuenta.
			</p>

			<!--
				El enlace se ENSEÑA, no solo se copia. Quien va a mandarlo quiere ver
				a dónde apunta antes de pegarlo en un grupo del barrio, y quien no
				pueda usar el portapapeles lo selecciona a mano.
			-->
			<p class="panel__enlace">{enlace}</p>

			<div class="panel__acciones">
				<button type="button" class="boton boton--principal" onclick={() => copiar('enlace')}>
					{#if copiado === 'enlace'}
						<Check size={15} aria-hidden="true" />
						Enlace copiado
					{:else}
						<Copy size={15} aria-hidden="true" />
						Copiar el enlace
					{/if}
				</button>

				<button type="button" class="boton boton--suave" onclick={() => copiar('mensaje')}>
					{#if copiado === 'mensaje'}
						<Check size={15} aria-hidden="true" />
						Mensaje copiado
					{:else}
						<Copy size={15} aria-hidden="true" />
						Copiar con el mensaje
					{/if}
				</button>
			</div>

			<!--
				`wa.me` SIN número abre la lista de contactos para elegir a quién.
				Para mandárselo a alguien concreto de la base del RUFE está el call
				center, que ya sabe su teléfono.
			-->
			<a
				class="boton boton--suave panel__wa"
				href="https://wa.me/?text={encodeURIComponent(mensaje)}"
				target="_blank"
				rel="noopener noreferrer"
			>
				<MessageCircle size={15} aria-hidden="true" />
				Abrir WhatsApp y elegir a quién
			</a>

			{#if typeof navigator !== 'undefined' && 'share' in navigator}
				<button type="button" class="boton boton--suave panel__wa" onclick={compartir}>
					<Share2 size={15} aria-hidden="true" />
					Compartir por otro medio
				</button>
			{/if}

			<p class="panel__ojo">
				<QrCode size={14} aria-hidden="true" />
				<span>
					Para una jornada en el barrio, imprima el enlace en la cartelera: cualquiera puede
					abrirlo desde su celular.
				</span>
			</p>
		</div>
	{/if}
</div>

<style>
	.compartir {
		position: relative;
	}

	.compartir__boton {
		white-space: nowrap;
	}

	/*
		Anclado al botón y alineado a la derecha: el botón vive al final de la
		barra, y abriendo hacia la izquierda el panel no se sale de la pantalla.
	*/
	.panel {
		position: absolute;
		top: calc(100% + 0.4rem);
		right: 0;
		z-index: 40;
		width: min(23rem, calc(100vw - 2rem));
		padding: 0.9rem;
		border: 1px solid var(--color-border-strong);
		border-radius: 12px;
		background: var(--color-surface);
		box-shadow: var(--shadow-lg);
		display: grid;
		gap: 0.55rem;
		text-align: left;
	}

	.panel__cabeza {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.5rem;
	}

	.panel__titulo {
		margin: 0;
		font-size: 0.92rem;
		font-weight: 600;
	}

	.panel__cerrar {
		display: grid;
		place-items: center;
		width: 26px;
		height: 26px;
		border: 0;
		border-radius: 7px;
		background: transparent;
		color: var(--color-muted);
		cursor: pointer;
	}

	.panel__cerrar:hover {
		background: var(--color-surface-alt);
		color: var(--color-text);
	}

	.panel__nota,
	.panel__ojo {
		margin: 0;
		font-size: 0.79rem;
		line-height: 1.45;
		color: var(--color-muted);
	}

	.panel__enlace {
		margin: 0.15rem 0;
		padding: 0.5rem 0.6rem;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		background: var(--color-surface-alt);
		font-family: var(--font-sans);
		font-size: 0.78rem;
		color: var(--color-text);

		/* Una URL larga no puede ensanchar el panel ni salirse por el borde. */
		overflow-wrap: anywhere;

		/* Seleccionable de un doble clic, para quien no tenga portapapeles. */
		user-select: all;
	}

	.panel__acciones {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 0.4rem;
	}

	.panel__acciones .boton,
	.panel__wa {
		justify-content: center;
		width: 100%;
		font-size: 0.82rem;
	}

	.panel__ojo {
		display: flex;
		align-items: flex-start;
		gap: 0.4rem;
		margin-top: 0.3rem;
		padding-top: 0.6rem;
		border-top: 1px solid var(--color-border);
	}

	.panel__ojo :global(svg) {
		flex: none;
		margin-top: 0.15rem;
	}

	/* En pantalla estrecha el panel ocupa el ancho y los dos botones se apilan:
	   apretados en dos columnas de 8rem es fácil pulsar el que no es. */
	@media (max-width: 480px) {
		.panel {
			right: auto;
			left: 0;
		}

		.panel__acciones {
			grid-template-columns: 1fr;
		}
	}
</style>
