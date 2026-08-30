<script lang="ts">
	// El interruptor de los avisos al aparato.
	//
	// ── Dónde vive y por qué ─────────────────────────────────────────────────
	//
	// En el menú, junto a «Instalar en este equipo». Son las dos cosas que se
	// deciden una vez y no se vuelven a tocar, y las dos hablan del aparato, no
	// del trabajo.
	//
	// ── Y por qué no sale solo ───────────────────────────────────────────────
	//
	// El permiso se pide con un gesto de la persona, nunca al cargar la
	// pantalla. Un navegador que ve la pregunta sin que nadie haya pulsado nada
	// la rechaza él mismo, y algunos no vuelven a preguntar NUNCA más: un
	// intento de más el primer día deja a esa persona sin avisos para siempre.

	import { Bell, BellOff, BellRing, LoaderCircle } from '@lucide/svelte';
	import { activar, desactivar, estado, type EstadoAvisos } from '$lib/push/avisos';

	let situacion = $state<EstadoAvisos | null>(null);
	let trabajando = $state(false);
	/** Por qué no quedó activado, cuando hay algo que decir. */
	let aviso = $state('');

	$effect(() => {
		void estado().then((e) => (situacion = e));
	});

	async function alternar() {
		if (trabajando || situacion === null) return;

		trabajando = true;
		aviso = '';

		// `finally`, y no una línea al final. Cualquier fallo que se escapara
		// —y uno se escapaba: `Notification.requestPermission()` estaba fuera
		// del try— dejaba este botón girando indefinidamente, sin nada en
		// pantalla que dijera por qué. Un control que gira para siempre es peor
		// que uno que dice «no pude».
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
</script>

<!--
	Mientras no se sabe, no se dibuja nada. Un interruptor que aparece apagado y
	salta a encendido medio segundo después parece que se encendió solo.
-->
{#if situacion !== null && situacion !== 'no-soportado'}
	{#if situacion === 'bloqueados'}
		<!--
			Bloqueados: desde la página ya no se puede volver a preguntar, y un
			botón que no hace nada es peor que ninguno. Se dice dónde se arregla.
		-->
		<p class="avisos avisos--bloqueados">
			<BellOff size={15} aria-hidden="true" />
			<span>
				Los avisos están bloqueados en este navegador. Se vuelven a permitir desde el candado de la
				barra de direcciones.
			</span>
		</p>
	{:else}
		<button
			type="button"
			class="avisos avisos--boton"
			class:avisos--activos={situacion === 'activos'}
			onclick={alternar}
			disabled={trabajando}
			aria-pressed={situacion === 'activos'}
		>
			{#if trabajando}
				<LoaderCircle class="girando" size={15} aria-hidden="true" />
			{:else if situacion === 'activos'}
				<BellRing size={15} aria-hidden="true" />
			{:else}
				<Bell size={15} aria-hidden="true" />
			{/if}

			<span>
				{#if situacion === 'activos'}
					Avisar cuando entre una solicitud
				{:else}
					Avisarme de solicitudes nuevas
				{/if}
			</span>
		</button>

		{#if aviso !== ''}
			<!--
				Por qué no quedó. Antes el botón se apagaba solo y en silencio,
				que es exactamente lo que alguien lee como «está roto».
			-->
			<p class="avisos__nota avisos__nota--fallo" role="status">{aviso}</p>
		{:else if situacion === 'activos'}
			<!--
				Lo que se ve en el aviso y lo que no. Quien enciende esto tiene
				derecho a saber que no le va a aparecer el nombre de una familia
				en la pantalla de bloqueo, delante de quien pase.
			-->
			<p class="avisos__nota">
				El aviso no lleva datos de nadie: solo dice que hay algo nuevo.
			</p>
		{/if}
	{/if}
{/if}

<style>
	.avisos {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		width: 100%;
		margin: 0;
		padding: 0.55rem 0.7rem;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		background: none;
		color: var(--color-muted);
		font: inherit;
		font-size: 0.82rem;
		line-height: 1.35;
		text-align: left;
	}

	.avisos--boton {
		cursor: pointer;
	}

	.avisos--boton:hover:not(:disabled),
	.avisos--boton:focus-visible {
		border-color: var(--color-border-strong);
		color: var(--color-text);
	}

	.avisos--boton:disabled {
		cursor: progress;
		opacity: 0.7;
	}

	.avisos--activos {
		border-color: var(--color-success);
		color: var(--color-text);
	}

	.avisos--bloqueados {
		align-items: flex-start;
		font-size: 0.76rem;
	}

	.avisos__nota {
		margin: 0.3rem 0 0;
		padding: 0 0.2rem;
		font-size: 0.72rem;
		line-height: 1.4;
		color: var(--color-muted);
	}

	/* DESPUÉS de `.avisos__nota`, y no antes: las dos son una clase, así que a
	   igual especificidad gana la última del archivo. Puesta arriba, el gris de
	   abajo se comía el color del aviso y el fallo se leía como una nota más. */
	.avisos__nota--fallo {
		color: var(--color-warning);
	}
</style>
