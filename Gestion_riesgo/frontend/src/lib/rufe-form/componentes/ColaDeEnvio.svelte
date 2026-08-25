<script lang="ts">
	// Las fichas de ESTE formato que ya se terminaron y todavía no llegaron a la
	// Alcaldía.
	//
	// Vivía en una pantalla propia, «Pendientes», colgada del menú y sin relación
	// visible con nada: quien terminaba una ficha sin señal tenía que acordarse de
	// que existía otro sitio donde comprobarlo. Ahora va dentro del formulario que
	// las produjo, junto a los borradores a medias, que es donde se hace la
	// pregunta: «¿salió lo que levanté hoy?».
	//
	// Filtra por formato a propósito. La cola es una sola y la comparten el RUFE y
	// la inspección; enseñarle al inspector fichas del censo —con nombres y
	// cédulas de hogares que él no atiende— sería enseñarle datos que no le
	// corresponden.
	//
	// Lee de IndexedDB, no de la API: funciona en plena vereda, sin señal, que es
	// justo cuando hace falta.

	import { onMount } from 'svelte';
	import { browser } from '$app/environment';
	import {
		CheckCircle2,
		CloudOff,
		LoaderCircle,
		RefreshCw,
		Trash2,
		TriangleAlert
	} from '@lucide/svelte';
	import {
		borrarFicha,
		fichasPendientes,
		fotosDe,
		tipoDe,
		type FichaEnCola,
		type TipoFicha
	} from '$lib/rufe-form/cola';
	import { GestorEnvio } from '$lib/rufe-form/envio.svelte';
	import { aparato } from '$lib/aparato';
	import { esWebKitDeApple, porQueNoSaleSolo } from '$lib/offline/plataforma';
	import { estaInstalada } from '$lib/offline/preparar';

	type Props = {
		/**
		 * El gestor de envío de la página, NO uno propio.
		 *
		 * Crear otro aquí ponía dos en marcha en la misma pantalla, cada uno con
		 * su latido de reintento cada 30 segundos. El seguro contra envíos
		 * simultáneos es `estado === 'enviando'`, y es POR INSTANCIA: dos gestores
		 * pueden mandar la misma ficha a la vez. La API es idempotente por
		 * `envio_id` y no se duplicaría el expediente, pero se gastarían datos
		 * móviles por partida doble en una vereda.
		 */
		envio: GestorEnvio;
		formato: TipoFicha;
		/** Palabra con la que se nombra una ficha de este formato, en singular. */
		nombre?: string;
		/**
		 * Decir «todo salió» cuando no queda nada.
		 *
		 * Solo donde tiene sentido decirlo, que es justo al terminar una ficha. En
		 * la portada del formulario sería un cartel diario: quien lo lee todos los
		 * días deja de leerlo, y entonces tampoco lo lee el día que sí dice algo.
		 */
		mostrarVacio?: boolean;
	};

	let { envio, formato, nombre = 'ficha', mostrarVacio = false }: Props = $props();

	const cual = aparato();

	/**
	 * Sin instalar, lo guardado se puede perder — y en iPhone es peor.
	 *
	 * Chrome desaloja el almacenamiento cuando al aparato le falta espacio, y
	 * `pedirAlmacenamientoPersistente()` intenta evitarlo. **Safari no da esa
	 * garantía**: borra los datos de un sitio NO instalado tras unos días sin
	 * abrirlo, aunque sobre espacio. Un censador con iPhone que levante fichas un
	 * viernes y no vuelva a abrir hasta el otro puede encontrarlas borradas.
	 *
	 * Instalada, en los dos casos el sistema operativo la trata como aplicación y
	 * deja de desalojarla.
	 *
	 * Este aviso vivía en la pantalla «Pendientes» y se perdió al mudarla aquí
	 * dentro. Vuelve, pero SOLO cuando hay algo que perder: un cartel permanente
	 * pidiendo instalar es el que nadie lee el día que sí importa.
	 */
	let instalada = $state(true);
	const enApple = esWebKitDeApple();

	let fichas = $state<FichaEnCola[]>([]);
	let fotosPorFicha = $state<Record<string, number>>({});
	let enLinea = $state(true);
	let confirmandoBorrado = $state<string | null>(null);

	const plural = $derived(nombre === 'ficha' ? 'fichas' : `${nombre}s`);

	onMount(() => {
		enLinea = navigator.onLine;
		instalada = estaInstalada();

		const conectar = () => {
			enLinea = true;
			void refrescar();
		};
		const desconectar = () => (enLinea = false);

		window.addEventListener('online', conectar);
		window.addEventListener('offline', desconectar);

		void refrescar();

		// La cola la mueve el Service Worker por su cuenta; sin releer cada tanto,
		// seguirían en pantalla fichas que ya salieron.
		const latido = setInterval(refrescar, 5000);

		return () => {
			window.removeEventListener('online', conectar);
			window.removeEventListener('offline', desconectar);
			clearInterval(latido);
		};
	});

	async function refrescar() {
		if (!browser) return;

		fichas = (await fichasPendientes()).filter((f) => tipoDe(f) === formato);

		const cuenta: Record<string, number> = {};
		for (const f of fichas) cuenta[f.envioId] = (await fotosDe(f.envioId)).length;
		fotosPorFicha = cuenta;
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

	/**
	 * Convierte la clave que devuelve el servidor en algo legible.
	 *
	 * Llegan como `personas.2.numero_documento`. No se traduce contra un
	 * diccionario de etiquetas a propósito: se duplicaría el esquema y se
	 * desincronizaría en silencio. El mensaje del servidor ya es una frase
	 * completa; esto solo dice a qué parte de la ficha corresponde.
	 */
	function dondeEsta(clave: string): string {
		const partes = clave.split('.');
		const trozos: string[] = [];

		for (let i = 0; i < partes.length; i++) {
			const parte = partes[i];
			if (/^\d+$/.test(parte)) continue;

			const siguiente = partes[i + 1];
			const numero = siguiente && /^\d+$/.test(siguiente) ? ` ${Number(siguiente) + 1}` : '';
			trozos.push(parte.replace(/_/g, ' ') + numero);
		}

		const texto = trozos.join(' · ');

		return texto.charAt(0).toUpperCase() + texto.slice(1);
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

<!--
	Cuando no hay nada pendiente esto NO se dibuja, salvo que se pida. Es la
	diferencia entre una herramienta y un cartel.
-->
{#if fichas.length > 0}
	<section class="cola">
		<h3 class="cola__titulo">
			<CloudOff size={16} aria-hidden="true" />
			{fichas.length === 1
				? `Hay 1 ${nombre} terminada que aún no llegó a la Alcaldía`
				: `Hay ${fichas.length} ${plural} terminadas que aún no llegaron a la Alcaldía`}
		</h3>

		<p class="cola__nota">
			{#if enLinea}
				Se envían solas. Puede seguir trabajando.
			{:else}
				Sin conexión. Están guardadas en {cual.este} y saldrán cuando vuelva la señal.
			{/if}
		</p>

		{#if envio.sesionRequerida}
			<p class="aviso aviso--error" role="alert">
				<TriangleAlert size={15} aria-hidden="true" />
				Su sesión venció. Vuelva a iniciar sesión y saldrán solas. No se ha perdido ninguna.
			</p>
		{/if}

		<ul class="cola__lista">
			{#each fichas as ficha (ficha.envioId)}
				<li class="enviada">
					<div class="enviada__cuerpo">
						<p class="enviada__direccion">{ficha.resumen.direccion}</p>
						<p class="enviada__meta">
							{ficha.resumen.evento}{#if ficha.resumen.personas > 0} · {ficha.resumen.personas}
								{ficha.resumen.personas === 1 ? 'persona' : 'personas'}{/if}
							{#if fotosPorFicha[ficha.envioId] > 0}
								· {fotosPorFicha[ficha.envioId]}
								{fotosPorFicha[ficha.envioId] === 1 ? 'foto' : 'fotos'}
							{/if}
						</p>
						<p class="enviada__fecha">Levantada el {cuando(ficha.creadoEn)}</p>

						{#if ficha.error}
							<p class="enviada__error">
								<TriangleAlert size={13} aria-hidden="true" />
								{ficha.error}
							</p>

							{#if ficha.errores}
								<ul class="enviada__campos">
									{#each Object.entries(ficha.errores) as [campo, mensaje] (campo)}
										<li><strong>{dondeEsta(campo)}:</strong> {mensaje}</li>
									{/each}
								</ul>
							{/if}
						{:else if ficha.intentos > 0}
							<p class="enviada__fecha">
								{ficha.intentos === 1
									? '1 intento de envío'
									: `${ficha.intentos} intentos de envío`}
							</p>
						{/if}
					</div>

					<div class="enviada__acciones">
						{#if ficha.estado === 'enviando'}
							<LoaderCircle size={16} class="girando" aria-hidden="true" />
						{/if}

						{#if confirmandoBorrado === ficha.envioId}
							<!--
								Descartar es irreversible y no queda registro en ninguna
								parte: esta ficha nunca llegó al servidor.
							-->
							<div class="enviada__confirmar">
								<span>Se borran los datos de ese hogar. No hay copia en la Alcaldía.</span>
								<div class="enviada__botones">
									<button
										type="button"
										class="boton boton--peligro"
										onclick={() => descartar(ficha.envioId)}
									>
										<Trash2 size={14} aria-hidden="true" />
										Sí, descartar
									</button>
									<button
										type="button"
										class="boton boton--suave"
										onclick={() => (confirmandoBorrado = null)}
									>
										Conservarla
									</button>
								</div>
							</div>
						{:else}
							<button
								type="button"
								class="boton boton--suave"
								onclick={() => (confirmandoBorrado = ficha.envioId)}
								aria-label="Descartar la ficha de {ficha.resumen.direccion}"
							>
								<Trash2 size={14} aria-hidden="true" />
								Descartar
							</button>
						{/if}
					</div>
				</li>
			{/each}
		</ul>

		{#if !instalada}
			<p class="cola__riesgo">
				<TriangleAlert size={15} aria-hidden="true" />
				<span>
					{#if enApple}
						<strong>Instale la aplicación</strong> desde Compartir → Añadir a inicio. En iPhone,
						Safari borra lo guardado de un sitio sin instalar tras unos días sin abrirlo — y con
						ello estas fichas.
					{:else}
						<strong>Instale la aplicación</strong> desde el menú lateral. Sin instalar,
						{cual.el} puede borrar lo guardado —estas fichas incluidas— cuando le falte espacio.
					{/if}
				</span>
			</p>
		{/if}

		<div class="cola__acciones">
			<button
				type="button"
				class="boton boton--suave"
				onclick={intentarAhora}
				disabled={!enLinea || envio.estado === 'enviando'}
			>
				{#if envio.estado === 'enviando'}
					<LoaderCircle size={15} class="girando" aria-hidden="true" />
					Enviando…
				{:else}
					<RefreshCw size={15} aria-hidden="true" />
					Intentar enviar ahora
				{/if}
			</button>

			{#if !envio.enSegundoPlano}
				<span class="cola__ojo">
					<!--
						Se nombra a Safari cuando toca. Sin nombrarlo, en un iPhone suena
						a que la aplicación está a medio hacer y alguien se pone a buscar
						un fallo que no existe.
					-->
					{porQueNoSaleSolo()} Deje la aplicación abierta.
				</span>
			{/if}
		</div>
	</section>
{:else if mostrarVacio}
	<p class="cola__vacio">
		<CheckCircle2 size={15} aria-hidden="true" />
		Todo lo levantado en {cual.este} ya llegó a la Alcaldía.
	</p>
{/if}

<style>
	/* El aviso de instalar. En ámbar y no en rojo: no es un error, es un riesgo
	   —lo guardado sigue ahí— y pintarlo de rojo junto a fichas sin enviar haría
	   pensar que ya se perdió algo. */
	.cola__riesgo {
		display: flex;
		align-items: flex-start;
		gap: 0.4rem;
		margin: 0.75rem 0 0;
		padding: 0.6rem 0.7rem;
		border: 1px solid var(--aviso-alerta-borde);
		border-radius: 8px;
		background: var(--aviso-alerta-fondo);
		color: var(--aviso-alerta-texto);
		font-size: 0.8rem;
		line-height: 1.45;
	}

	.cola__riesgo :global(svg) {
		flex: none;
		margin-top: 0.1rem;
	}


	.cola {
		margin-top: 1rem;
		padding: 0.9rem;
		border: 1px solid var(--color-border);
		border-radius: 12px;
		background: var(--color-surface-alt);
	}

	.cola__titulo {
		display: flex;
		align-items: center;
		gap: 0.45rem;
		margin: 0 0 0.3rem;
		font-size: 0.92rem;
		font-weight: 600;
	}

	.cola__nota {
		margin: 0 0 0.7rem;
		font-size: 0.82rem;
		line-height: 1.45;
		color: var(--color-muted);
	}

	.cola__lista {
		list-style: none;
		margin: 0;
		padding: 0;
		display: grid;
		gap: 0.5rem;
	}

	.enviada {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-start;
		justify-content: space-between;
		gap: 0.7rem;
		padding: 0.7rem 0.8rem;
		border: 1px solid var(--color-border);
		border-radius: 10px;
		background: var(--color-surface);
	}

	.enviada__cuerpo {
		min-width: 0;
		flex: 1 1 14rem;
	}

	.enviada__direccion {
		margin: 0;
		font-weight: 600;
		font-size: 0.9rem;
		overflow-wrap: anywhere;
	}

	.enviada__meta,
	.enviada__fecha {
		margin: 0.15rem 0 0;
		font-size: 0.78rem;
		color: var(--color-muted);
	}

	.enviada__error {
		display: flex;
		align-items: center;
		gap: 0.3rem;
		margin: 0.35rem 0 0;
		font-size: 0.79rem;
		color: var(--color-danger);
	}

	.enviada__campos {
		margin: 0.25rem 0 0 1rem;
		padding: 0;
		font-size: 0.76rem;
		color: var(--color-muted);
	}

	.enviada__acciones {
		display: flex;
		align-items: center;
		gap: 0.4rem;
	}

	.enviada__confirmar {
		display: grid;
		gap: 0.45rem;
		flex: 1 1 100%;
		font-size: 0.8rem;
		line-height: 1.45;
	}

	.enviada__botones {
		display: flex;
		gap: 0.4rem;
		flex-wrap: wrap;
	}

	.cola__acciones {
		display: flex;
		align-items: center;
		gap: 0.6rem;
		flex-wrap: wrap;
		margin-top: 0.75rem;
	}

	.cola__ojo {
		font-size: 0.77rem;
		color: var(--color-muted);
	}

	.cola__vacio {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 1rem 0 0;
		font-size: 0.83rem;
		color: var(--color-success);
	}
</style>
