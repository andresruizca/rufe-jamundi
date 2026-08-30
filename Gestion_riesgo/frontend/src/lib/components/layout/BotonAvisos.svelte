<script lang="ts">
	// Los avisos al aparato, en la barra superior.
	//
	// ── Por qué aquí y no en el menú ─────────────────────────────────────────
	//
	// Estaba abajo del todo en el menú lateral, que pasa la mayor parte del
	// tiempo cerrado. Un control de notificaciones que hay que ir a buscar
	// dentro de un cajón no lo encuentra nadie, y quien lo activó no tiene forma
	// de ver de un vistazo si sigue activado.
	//
	// La campana es donde la gente la busca, porque es donde está en todo lo
	// demás que usa.
	//
	// ── Y por qué un panel y no solo un interruptor ──────────────────────────
	//
	// Porque hay tres cosas que decir y ninguna cabe en un icono: en qué estado
	// está, que el aviso no lleva datos de nadie, y cómo comprobar que llega.
	// Sin lo tercero, la única forma de enterarse de que no funciona sería
	// perderse un aviso de verdad.
	//
	// ── Y por qué el permiso no se pide solo ─────────────────────────────────
	//
	// Se pide con un gesto de la persona, nunca al cargar la pantalla. Un
	// navegador que ve la pregunta sin que nadie haya pulsado nada la rechaza él
	// mismo, y algunos no vuelven a preguntar NUNCA más: un intento de más el
	// primer día deja a esa persona sin avisos para siempre.

	import { Bell, BellOff, BellRing, Check, LoaderCircle } from '@lucide/svelte';
	import { activar, desactivar, estado, probar, type EstadoAvisos } from '$lib/push/avisos';

	let situacion = $state<EstadoAvisos | null>(null);
	let abierto = $state(false);
	let trabajando = $state(false);
	let probando = $state(false);
	/** Lo último que pasó, para decirlo dentro del panel. */
	let aviso = $state('');

	let caja = $state<HTMLDivElement | null>(null);

	$effect(() => {
		void estado().then((e) => (situacion = e));
	});

	/**
	 * Cerrar al tocar fuera.
	 *
	 * Con `pointerdown` y no `click`: en un celular, tocar otra cosa dispara el
	 * foco antes que el click y el panel se quedaba abierto encima.
	 */
	$effect(() => {
		if (!abierto) return;

		const fuera = (e: Event) => {
			if (caja && !caja.contains(e.target as Node)) abierto = false;
		};

		const escape = (e: KeyboardEvent) => {
			if (e.key === 'Escape') abierto = false;
		};

		document.addEventListener('pointerdown', fuera);
		document.addEventListener('keydown', escape);

		return () => {
			document.removeEventListener('pointerdown', fuera);
			document.removeEventListener('keydown', escape);
		};
	});

	async function alternar() {
		if (trabajando || situacion === null) return;

		trabajando = true;
		aviso = '';

		// `finally`, y no una línea al final: cualquier fallo que se escapara
		// dejaría este control girando indefinidamente. Ya pasó una vez.
		try {
			const r = situacion === 'activos' ? await desactivar() : await activar();

			situacion = r.estado;
			aviso = r.aviso ?? '';
		} catch {
			aviso = 'No se pudieron activar los avisos. Intente de nuevo.';
		} finally {
			trabajando = false;
		}
	}

	async function enviarPrueba() {
		if (probando) return;

		probando = true;
		aviso = '';

		try {
			aviso = await probar();
		} finally {
			probando = false;
		}
	}
</script>

<!-- Mientras no se sabe, no se dibuja: una campana que aparece apagada y salta
     a encendida medio segundo después parece que se encendió sola. -->
{#if situacion !== null && situacion !== 'no-soportado'}
	<div class="avisos" bind:this={caja}>
		<button
			type="button"
			class="avisos__campana"
			class:avisos__campana--activa={situacion === 'activos'}
			aria-expanded={abierto}
			aria-label={situacion === 'activos'
				? 'Avisos activados. Abrir sus opciones'
				: 'Avisos desactivados. Abrir sus opciones'}
			onclick={() => (abierto = !abierto)}
		>
			{#if situacion === 'activos'}
				<BellRing size={18} aria-hidden="true" />
				<!-- El punto: en un vistazo, sin abrir nada y sin leer. -->
				<span class="avisos__punto" aria-hidden="true"></span>
			{:else if situacion === 'bloqueados'}
				<BellOff size={18} aria-hidden="true" />
			{:else}
				<Bell size={18} aria-hidden="true" />
			{/if}
		</button>

		{#if abierto}
			<div class="panel" role="dialog" aria-label="Avisos de solicitudes nuevas">
				<p class="panel__titulo">Avisos de solicitudes nuevas</p>

				{#if situacion === 'bloqueados'}
					<!-- Bloqueados: desde la página ya no se puede volver a preguntar,
					     y un botón que no hace nada es peor que ninguno. -->
					<p class="panel__nota">
						Están bloqueados en este navegador. Se vuelven a permitir desde el candado de la barra
						de direcciones.
					</p>
				{:else}
					<p class="panel__nota">
						Le avisamos cuando una familia pida la inspección de su vivienda, para que no se quede
						esperando días.
					</p>

					<button
						type="button"
						class="panel__interruptor"
						class:panel__interruptor--activo={situacion === 'activos'}
						onclick={alternar}
						disabled={trabajando}
						aria-pressed={situacion === 'activos'}
					>
						{#if trabajando}
							<LoaderCircle class="girando" size={15} aria-hidden="true" />
						{:else if situacion === 'activos'}
							<Check size={15} aria-hidden="true" />
						{:else}
							<Bell size={15} aria-hidden="true" />
						{/if}

						<!-- Encendido dice que ESTÁ encendido, no lo que haría al
						     pulsarlo: si las dos etiquetas se leen como una invitación,
						     ni estando activado se sabe si lo está. -->
						{situacion === 'activos' ? 'Activados en este aparato' : 'Activar en este aparato'}
					</button>

					{#if situacion === 'activos'}
						<button type="button" class="panel__probar" onclick={enviarPrueba} disabled={probando}>
							{probando ? 'Enviando…' : 'Enviarme una prueba'}
						</button>
					{/if}

					<p class="panel__nota panel__nota--fina">
						El aviso no lleva datos de nadie: solo dice que hay algo nuevo.
					</p>
				{/if}

				{#if aviso !== ''}
					<p class="panel__resultado" role="status">{aviso}</p>
				{/if}
			</div>
		{/if}
	</div>
{/if}

<style>
	.avisos {
		position: relative;
		flex: 0 0 auto;
	}

	.avisos__campana {
		position: relative;
		display: grid;
		place-items: center;
		width: 2.2rem;
		height: 2.2rem;
		border: none;
		border-radius: 8px;
		background: none;
		color: var(--color-muted);
		cursor: pointer;
	}

	.avisos__campana:hover,
	.avisos__campana:focus-visible {
		color: var(--color-text);
		background: var(--color-surface-alt);
	}

	.avisos__campana--activa {
		color: var(--color-success);
	}

	.avisos__punto {
		position: absolute;
		top: 0.35rem;
		right: 0.35rem;
		width: 0.45rem;
		height: 0.45rem;
		border-radius: 50%;
		background: var(--color-success);
	}

	.panel {
		position: absolute;
		z-index: 50;
		top: calc(100% + 0.4rem);
		right: 0;
		width: min(19rem, calc(100vw - 1.5rem));
		display: flex;
		flex-direction: column;
		gap: 0.55rem;
		padding: 0.85rem 0.9rem;
		border: 1px solid var(--color-border-strong);
		border-radius: 10px;
		background: var(--color-surface);
		box-shadow: 0 14px 32px rgb(0 0 0 / 0.3);
	}

	.panel__titulo {
		margin: 0;
		font-size: 0.85rem;
		font-weight: 700;
		color: var(--color-text);
	}

	.panel__nota {
		margin: 0;
		font-size: 0.78rem;
		line-height: 1.45;
		color: var(--color-muted);
	}

	.panel__nota--fina {
		font-size: 0.72rem;
	}

	.panel__interruptor {
		display: flex;
		align-items: center;
		gap: 0.45rem;
		width: 100%;
		padding: 0.5rem 0.65rem;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		background: none;
		color: var(--color-muted);
		font: inherit;
		font-size: 0.8rem;
		text-align: left;
		cursor: pointer;
	}

	.panel__interruptor:hover:not(:disabled),
	.panel__interruptor:focus-visible {
		border-color: var(--color-border-strong);
		color: var(--color-text);
	}

	.panel__interruptor:disabled {
		cursor: progress;
		opacity: 0.7;
	}

	.panel__interruptor--activo {
		border-color: var(--color-success);
		color: var(--color-text);
	}

	.panel__probar {
		align-self: flex-start;
		padding: 0.1rem 0;
		border: none;
		background: none;
		color: var(--color-primary);
		font: inherit;
		font-size: 0.75rem;
		text-decoration: underline;
		cursor: pointer;
	}

	.panel__probar:disabled {
		color: var(--color-muted);
		cursor: progress;
	}

	.panel__resultado {
		margin: 0;
		padding-top: 0.5rem;
		border-top: 1px solid var(--color-border);
		font-size: 0.76rem;
		line-height: 1.45;
		color: var(--color-warning);
	}
</style>
