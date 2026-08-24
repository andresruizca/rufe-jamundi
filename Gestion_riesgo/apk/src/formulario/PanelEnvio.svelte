<script lang="ts">
	// Qué está pasando con los envíos, en vivo.
	//
	// Va en el último paso porque es donde la persona decide si puede irse
	// tranquila. Hasta ahora la pantalla decía «se enviará en cuanto haya
	// internet» y ahí se acababa: quien lo leía tres horas después no sabía si el
	// teléfono lo había intentado siquiera, y quien atiende el teléfono en la
	// Alcaldía tampoco podía responderle.
	//
	// Tres cosas, y ninguna es decorativa:
	//
	//   1. Si hay internet AHORA. Es lo primero que se pregunta cualquiera.
	//   2. Cuántas solicitudes esperan y cuándo se reintenta.
	//   3. La bitácora: qué se intentó, cuándo, y cómo salió.

	import { onMount, onDestroy } from 'svelte';
	import { Check, Clock, RefreshCw, TriangleAlert, Wifi, WifiOff } from '@lucide/svelte';
	import { Network } from '@capacitor/network';
	import { comoSeLee, cuandoSeLee, ultimosIntentos, type Anotacion } from '$local/bitacora';
	import { cuantasEsperan } from '$local/registros';
	import { sincronizarAhora } from '$local/sincronizar';

	let hayInternet = $state<boolean | null>(null);
	let pendientes = $state(0);
	let intentos = $state<(Anotacion & { radicado: string | null })[]>([]);
	let pidiendo = $state(false);

	let quitarEscucha: (() => void) | null = null;
	let reloj: ReturnType<typeof setInterval> | null = null;

	async function refrescar() {
		try {
			pendientes = await cuantasEsperan();
			intentos = await ultimosIntentos();
		} catch {
			// Sin base todavía no hay nada que enseñar. No es un error que contarle
			// a alguien que está llenando un formulario.
		}
	}

	onMount(() => {
		void (async () => {
			await refrescar();

			try {
				const estado = await Network.getStatus();
				hayInternet = estado.connected;

				const escucha = await Network.addListener('networkStatusChange', (e) => {
					hayInternet = e.connected;
					// La red acaba de volver: en unos segundos habrá algo nuevo que
					// enseñar, porque el envío se dispara solo.
					if (e.connected) setTimeout(refrescar, 3000);
				});

				quitarEscucha = () => void escucha.remove();
			} catch {
				// En el navegador el complemento no existe. Se cae a lo que sabe el
				// propio navegador, que para `npm run dev` es suficiente.
				hayInternet = navigator.onLine;
			}

			// La bitácora la escribe Kotlin desde fuera de esta pantalla, así que
			// hay que volver a mirarla. Diez segundos: lo bastante para que se note
			// y lo bastante poco para no gastar batería en una consulta trivial.
			reloj = setInterval(refrescar, 10_000);
		})();
	});

	onDestroy(() => {
		quitarEscucha?.();
		if (reloj) clearInterval(reloj);
	});

	async function intentarYa() {
		pidiendo = true;

		try {
			await sincronizarAhora();
			// Se le da tiempo a Kotlin a escribir su primera anotación antes de
			// volver a leer; si no, la pantalla parece que no hizo nada.
			setTimeout(refrescar, 2500);
		} finally {
			setTimeout(() => (pidiendo = false), 2500);
		}
	}
</script>

<section class="panel">
	<h3 class="panel__titulo">
		{#if hayInternet === null}
			<Clock size={16} aria-hidden="true" /> Revisando la conexión…
		{:else if hayInternet}
			<Wifi size={16} aria-hidden="true" /> Hay internet
		{:else}
			<WifiOff size={16} aria-hidden="true" /> No hay internet ahora
		{/if}
	</h3>

	<p class="panel__nota">
		{#if hayInternet}
			Lo que guarde saldrá en seguida. No tiene que hacer nada.
		{:else}
			Su registro se guarda igual en el teléfono y saldrá solo cuando vuelva la señal.
		{/if}
	</p>

	{#if pendientes > 0}
		<p class="panel__pendientes">
			{pendientes === 1
				? 'Hay 1 registro esperando salir.'
				: `Hay ${pendientes} registros esperando salir.`}
		</p>

		{#if hayInternet}
			<button type="button" class="boton boton--suave" onclick={intentarYa} disabled={pidiendo}>
				<RefreshCw size={14} aria-hidden="true" />
				{pidiendo ? 'Intentando…' : 'Intentar enviar ahora'}
			</button>
		{/if}
	{/if}

	{#if intentos.length > 0}
		<!--
			La bitácora. Se enseña con fecha y hora porque una solicitud puede
			intentarse varias veces el mismo día, y sin la hora las anotaciones se
			vuelven indistinguibles.
		-->
		<h4 class="panel__sub">Últimos envíos</h4>
		<ol class="registro">
			{#each intentos as a (a.cuando + a.resultado)}
				{@const leido = comoSeLee(a)}
				<li class="linea linea--{leido.clase}">
					{#if leido.clase === 'bien'}
						<Check size={14} aria-hidden="true" />
					{:else if leido.clase === 'mal'}
						<TriangleAlert size={14} aria-hidden="true" />
					{:else}
						<Clock size={14} aria-hidden="true" />
					{/if}
					<span class="linea__cuando">{cuandoSeLee(a.cuando)}</span>
					<span class="linea__que">{leido.texto}</span>
				</li>
			{/each}
		</ol>
	{/if}
</section>

<style>
	.panel {
		margin-top: 1.2rem;
		padding: 0.9rem;
		border: 1px solid var(--color-border);
		border-radius: 12px;
		background: var(--color-surface-alt);
	}

	.panel__titulo {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 0 0 0.35rem;
		font-size: 0.92rem;
	}

	.panel__nota {
		margin: 0;
		font-size: 0.82rem;
		line-height: 1.45;
		color: var(--color-muted);
	}

	.panel__pendientes {
		margin: 0.7rem 0 0.5rem;
		font-size: 0.84rem;
		font-weight: 600;
	}

	.panel__sub {
		margin: 1rem 0 0.4rem;
		font-size: 0.8rem;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: var(--color-muted);
	}

	.registro {
		list-style: none;
		margin: 0;
		padding: 0;
		display: grid;
		gap: 0.35rem;
	}

	.linea {
		display: grid;
		grid-template-columns: auto auto 1fr;
		align-items: baseline;
		gap: 0.4rem;
		font-size: 0.8rem;
		line-height: 1.4;
	}

	.linea :global(svg) {
		align-self: center;
	}

	.linea--bien {
		color: var(--color-success);
	}

	.linea--mal {
		color: var(--color-danger);
	}

	.linea--espera {
		color: var(--color-muted);
	}

	.linea__cuando {
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}

	.linea__que {
		color: var(--color-text);
		min-width: 0;
		word-break: break-word;
	}
</style>
