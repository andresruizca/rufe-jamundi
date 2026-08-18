<script lang="ts">
	// Las fichas levantadas que todavía no llegaron a la Alcaldía.
	//
	// Vive aparte del formulario a propósito. Mezcladas, la pantalla de captura
	// tenía que hacer dos trabajos —levantar una ficha nueva y vigilar las que no
	// salieron— y acababa bloqueando lo primero por culpa de lo segundo.
	//
	// Es la única pantalla del sistema que funciona sin conexión: lee de la base
	// del propio navegador, no de la API. Ahí está su razón de ser — el censador
	// necesita poder comprobar en plena vereda que no perdió el trabajo del día.

	import { onMount, onDestroy } from 'svelte';
	import { browser } from '$app/environment';
	import {
		CheckCircle2, CloudOff, LoaderCircle, RefreshCw, Trash2, TriangleAlert
	} from '@lucide/svelte';
	import { sesion } from '$lib/stores/sesion.svelte';
	import {
		borrarFicha,
		espacioDisponible,
		fichasPendientes,
		fotosDe,
		type FichaEnCola
	} from '$lib/rufe-form/cola';
	import { GestorEnvio } from '$lib/rufe-form/envio.svelte';
	import { tamanoLegible } from '$lib/rufe-form/imagen';

	let fichas = $state<FichaEnCola[]>([]);
	let fotosPorFicha = $state<Record<string, number>>({});
	let cargando = $state(true);
	let enLinea = $state(true);
	let espacio = $state<{ usado: number; total: number } | null>(null);
	let confirmandoBorrado = $state<string | null>(null);

	const envio = new GestorEnvio();
	let detener: (() => void) | null = null;

	onMount(() => {
		enLinea = navigator.onLine;
		const conectar = () => {
			enLinea = true;
			void refrescar();
		};
		const desconectar = () => (enLinea = false);
		window.addEventListener('online', conectar);
		window.addEventListener('offline', desconectar);

		detener = envio.iniciar();
		void refrescar();

		// La cola la mueve el Service Worker por su cuenta; sin releer cada tanto,
		// la pantalla mostraría fichas que ya salieron.
		const latido = setInterval(refrescar, 5000);

		return () => {
			window.removeEventListener('online', conectar);
			window.removeEventListener('offline', desconectar);
			clearInterval(latido);
		};
	});

	onDestroy(() => detener?.());

	async function refrescar() {
		if (!browser) return;

		fichas = await fichasPendientes();

		const cuenta: Record<string, number> = {};
		for (const f of fichas) cuenta[f.envioId] = (await fotosDe(f.envioId)).length;
		fotosPorFicha = cuenta;

		espacio = await espacioDisponible();
		cargando = false;
	}

	async function intentarAhora() {
		await envio.reintentarTodo();
		await refrescar();
	}

	async function descartar(envioId: string) {
		await borrarFicha(envioId);
		confirmandoBorrado = null;
		await refrescar();
	}

	function cuando(ms: number): string {
		return new Date(ms).toLocaleString('es-CO', {
			day: '2-digit',
			month: 'long',
			hour: '2-digit',
			minute: '2-digit'
		});
	}
</script>

<div class="tarjeta">
	<h2 class="tarjeta__titulo">Fichas pendientes de enviar</h2>
	<p class="tarjeta__nota">
		Fichas levantadas en este teléfono que todavía no llegaron a la Alcaldía. Se envían solas en
		cuanto haya señal; esta pantalla funciona sin conexión.
	</p>

	{#if !enLinea}
		<p class="aviso aviso--info" role="status">
			<CloudOff size={15} aria-hidden="true" />
			Sin conexión. Las fichas están guardadas y saldrán cuando vuelva la señal.
		</p>
	{/if}

	{#if envio.sesionRequerida}
		<p class="aviso aviso--error" role="alert">
			<TriangleAlert size={15} aria-hidden="true" />
			Su sesión venció. Vuelva a iniciar sesión y las fichas se enviarán solas. No se ha perdido
			ninguna.
		</p>
	{/if}

	{#if cargando}
		<p class="cargando">
			<LoaderCircle size={18} class="girando" aria-hidden="true" />
			Leyendo lo guardado en este dispositivo…
		</p>
	{:else if fichas.length === 0}
		<p class="vacio">
			<CheckCircle2 size={26} aria-hidden="true" />
			<span>No hay nada pendiente. Todas las fichas levantadas en este teléfono ya se enviaron.</span>
		</p>
	{:else}
		<div class="acciones">
			<button type="button" class="boton" onclick={intentarAhora} disabled={!enLinea || envio.estado === 'enviando'}>
				{#if envio.estado === 'enviando'}
					<LoaderCircle size={15} class="girando" aria-hidden="true" />
					Enviando…
				{:else}
					<RefreshCw size={15} aria-hidden="true" />
					Intentar enviar ahora
				{/if}
			</button>

			{#if !envio.enSegundoPlano}
				<span class="acciones__nota">
					Este navegador no permite enviar en segundo plano: deje la aplicación abierta.
				</span>
			{/if}
		</div>

		<ul class="lista">
			{#each fichas as ficha (ficha.envioId)}
				<li class="ficha">
					<div class="ficha__cuerpo">
						<p class="ficha__direccion">{ficha.resumen.direccion}</p>
						<p class="ficha__meta">
							{ficha.resumen.evento} · {ficha.resumen.personas}
							{ficha.resumen.personas === 1 ? 'persona' : 'personas'}
							{#if fotosPorFicha[ficha.envioId] > 0}
								· {fotosPorFicha[ficha.envioId]}
								{fotosPorFicha[ficha.envioId] === 1 ? 'foto' : 'fotos'}
							{/if}
						</p>
						<p class="ficha__fecha">Levantada el {cuando(ficha.creadoEn)}</p>

						{#if ficha.error}
							<p class="ficha__error">
								<TriangleAlert size={13} aria-hidden="true" />
								{ficha.error}
							</p>
						{:else if ficha.intentos > 0}
							<p class="ficha__fecha">
								{ficha.intentos === 1 ? '1 intento de envío' : `${ficha.intentos} intentos de envío`}
							</p>
						{/if}
					</div>

					<div class="ficha__acciones">
						{#if ficha.estado === 'enviando'}
							<LoaderCircle size={16} class="girando" aria-hidden="true" />
						{/if}

						{#if confirmandoBorrado === ficha.envioId}
							<button type="button" class="boton boton--peligro" onclick={() => descartar(ficha.envioId)}>
								Sí, descartar
							</button>
							<button type="button" class="boton boton--suave" onclick={() => (confirmandoBorrado = null)}>
								Cancelar
							</button>
						{:else}
							<button
								type="button"
								class="boton boton--suave"
								onclick={() => (confirmandoBorrado = ficha.envioId)}
								aria-label="Descartar la ficha de {ficha.resumen.direccion}"
							>
								<Trash2 size={14} aria-hidden="true" />
							</button>
						{/if}
					</div>
				</li>
			{/each}
		</ul>

		<!-- Descartar es irreversible y no queda registro en ninguna parte: la ficha
		     nunca llegó al servidor. Por eso se pide confirmación y se dice esto. -->
		<p class="advertencia">
			Descartar una ficha borra definitivamente los datos de ese hogar. No hay forma de
			recuperarla, porque nunca llegó a la Alcaldía.
		</p>
	{/if}
</div>

<div class="tarjeta">
	<h2 class="tarjeta__titulo">Cómo funciona</h2>
	<ul class="explicacion">
		<li>
			Las fichas se guardan en este teléfono en cuanto pulsa «Guardar», aunque no haya señal.
		</li>
		<li>
			Se envían solas cuando vuelve la conexión.
			{#if envio.enSegundoPlano}
				Este navegador puede hacerlo <strong>aunque cierre la aplicación</strong>.
			{/if}
		</li>
		<li>
			El número de radicado se genera cuando la ficha llega a la Alcaldía. Después aparece en
			<a href="/riesgo/reportes">Reportes RUFE</a>.
		</li>
		<li>
			<strong>No borre los datos del navegador</strong> mientras haya fichas aquí: se perderían.
		</li>
	</ul>

	{#if espacio && espacio.total > 0}
		<p class="espacio">
			Espacio usado en este dispositivo: {tamanoLegible(espacio.usado)} de
			{tamanoLegible(espacio.total)} disponibles.
		</p>
	{/if}

	{#if sesion.rol}
		<p class="espacio">Sesión activa: {sesion.usuario?.email}</p>
	{/if}
</div>

<style>
	.acciones {
		display: flex;
		align-items: center;
		gap: 0.6rem;
		flex-wrap: wrap;
		margin-bottom: 1rem;
	}

	.acciones__nota {
		font-size: 0.78rem;
		color: var(--color-muted);
	}

	.lista {
		list-style: none;
		margin: 0 0 0.9rem;
		padding: 0;
		display: grid;
		gap: 0.6rem;
	}

	.ficha {
		display: flex;
		align-items: flex-start;
		gap: 0.6rem;
		flex-wrap: wrap;
		padding: 0.75rem;
		border: 1px solid var(--color-border);
		border-radius: 10px;
		background: var(--color-surface);
	}

	.ficha__cuerpo {
		flex: 1 1 12rem;
		min-width: 0;
	}

	.ficha__direccion {
		margin: 0 0 0.15rem;
		font-size: 0.9rem;
		font-weight: 600;
		overflow-wrap: anywhere;
	}

	.ficha__meta {
		margin: 0;
		font-size: 0.8rem;
		color: var(--color-text);
		overflow-wrap: anywhere;
	}

	.ficha__fecha {
		margin: 0.1rem 0 0;
		font-size: 0.75rem;
		color: var(--color-muted);
	}

	.ficha__error {
		display: flex;
		align-items: flex-start;
		gap: 0.25rem;
		flex-wrap: wrap;
		margin: 0.3rem 0 0;
		font-size: 0.75rem;
		color: var(--aviso-alerta-texto);
		overflow-wrap: anywhere;
	}

	.ficha__acciones {
		display: flex;
		align-items: center;
		gap: 0.35rem;
		flex: 0 0 auto;
		margin-left: auto;
		flex-wrap: wrap;
	}

	.advertencia {
		margin: 0;
		font-size: 0.78rem;
		color: var(--color-muted);
	}

	.vacio {
		display: grid;
		justify-items: center;
		gap: 0.5rem;
		color: var(--color-success);
	}

	.vacio span {
		color: var(--color-muted);
		font-size: 0.9rem;
	}

	.explicacion {
		margin: 0 0 0.8rem;
		padding-left: 1.15rem;
		display: grid;
		gap: 0.4rem;
		font-size: 0.85rem;
		line-height: 1.5;
	}

	.espacio {
		margin: 0.3rem 0 0;
		font-size: 0.78rem;
		color: var(--color-muted);
	}
</style>
