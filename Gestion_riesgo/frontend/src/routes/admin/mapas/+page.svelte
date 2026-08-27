<script lang="ts">
	// Convertir las direcciones del censo en coordenadas.
	//
	// Se hace por lotes y a mano por una razón del hosting, no de diseño: aquí no
	// hay cron ni procesos en segundo plano, y OpenStreetMap solo admite una
	// consulta por segundo. Así que se procesa una decena, se vuelve a llamar, y
	// así hasta acabar. La pantalla puede encadenar las llamadas sola mientras
	// esté abierta.

	import { onDestroy, onMount } from 'svelte';
	import { LoaderCircle, MapPinned, Play, RefreshCw, Square, TriangleAlert } from '@lucide/svelte';
	import { mapaApi } from '$lib/api/servicios';
	import { ApiError } from '$lib/api/client';

	type Estado = {
		por_precision: Record<string, number>;
		pendientes: number;
		pendientes_en_uso: number;
		obsoletas: number;
		direcciones_del_censo: number;
		lote: number;
		google_activo: boolean;
		segundos_por_direccion: number;
		consultas_por_direccion: number;
	};

	let estado = $state<Estado | null>(null);
	let cargando = $state(true);
	let error = $state<string | null>(null);
	let corriendo = $state(false);
	let procesadas = $state(0);
	let ubicadas = $state(0);

	/**
	 * Cuántas había que hacer cuando se pulsó el botón.
	 *
	 * Se congela al arrancar y no se recalcula: si el denominador se leyera del
	 * estado en cada vuelta, la barra avanzaría y retrocedería —el total cambia
	 * cuando una dirección falla y sale de la cola—, y una barra que retrocede
	 * hace pensar que el proceso se rompió.
	 */
	let alArrancar = $state(0);

	/** Cuándo empezó, para poder decir cuánto falta con lo que ya se sabe. */
	let comienzo = 0;
	let transcurrido = $state(0);

	const avance = $derived(
		alArrancar > 0 ? Math.min(100, Math.round((procesadas / alArrancar) * 100)) : 0
	);

	/**
	 * Lo que falta, medido con el ritmo REAL de esta corrida.
	 *
	 * La estimación de antes de arrancar sale de un segundo por dirección. La de
	 * aquí sale de lo que está tardando de verdad, que con el barrio delante
	 * puede ser el triple: una dirección cuesta hasta tres consultas.
	 */
	const minutosFaltan = $derived.by(() => {
		if (procesadas === 0 || transcurrido === 0) return null;

		const porDireccion = transcurrido / procesadas;
		const quedan = Math.max(0, alArrancar - procesadas);

		return Math.ceil((quedan * porDireccion) / 60);
	});

	let detener = false;
	let confirmandoRehacer = $state(false);
	let rehaciendo = $state(false);
	let resultadoRehacer = $state<string | null>(null);

	const ETIQUETA: Record<string, string> = {
		EXACTA: 'Ubicación exacta',
		CALLE: 'Sobre la vía',
		BARRIO: 'Aproximada del sector',
		MUNICIPIO: 'Solo llegó al municipio',
		FALLIDA: 'Sin ubicar'
	};

	const utiles = $derived(
		estado
			? (estado.por_precision.EXACTA ?? 0) +
				(estado.por_precision.CALLE ?? 0) +
				(estado.por_precision.BARRIO ?? 0)
			: 0
	);

	const minutosRestantes = $derived(
		estado
			? Math.ceil(
					(estado.pendientes_en_uso *
						estado.segundos_por_direccion *
						estado.consultas_por_direccion) /
						60
				)
			: 0
	);

	onMount(() => void refrescar());
	onDestroy(() => {
		detener = true;
	});

	async function refrescar() {
		try {
			estado = await mapaApi.estado();
			error = null;
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudo leer el estado.';
		} finally {
			cargando = false;
		}
	}

	/**
	 * Vuelve a poner todas las direcciones en cola.
	 *
	 * Hace falta cuando el buscador mejora: lo ya guardado se calculó con las
	 * reglas viejas y no se recalcula solo, porque la caché existe justamente para
	 * no volver a preguntar.
	 */
	async function rehacer() {
		rehaciendo = true;
		error = null;

		try {
			const r = await mapaApi.reubicar();
			resultadoRehacer =
				`${r.reencoladas} direcciones vuelven a la cola.` +
				(r.conservadas > 0 ? ` Se conservan ${r.conservadas} corregidas a mano.` : '');
			confirmandoRehacer = false;
			await refrescar();
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudieron reencolar.';
		} finally {
			rehaciendo = false;
		}
	}

	async function procesar() {
		corriendo = true;
		detener = false;
		procesadas = 0;
		ubicadas = 0;
		error = null;
		alArrancar = estado?.pendientes_en_uso ?? 0;
		comienzo = Date.now();
		transcurrido = 0;

		// El reloj corre aparte del bucle: un lote puede tardar medio minuto, y
		// sin esto el tiempo restante se quedaría congelado todo ese rato.
		const reloj = setInterval(() => (transcurrido = (Date.now() - comienzo) / 1000), 1000);

		// Se encadenan lotes hasta que no queden pendientes o el administrador
		// pare. Si cierra la pantalla, lo hecho queda guardado: cada lote se
		// escribe en la base al terminar.
		while (!detener) {
			try {
				const r = await mapaApi.geocodificar();
				procesadas += r.procesadas;
				ubicadas += r.ubicadas;
				await refrescar();

				if (r.pendientes === 0) break;

				// Cero procesadas con pendientes por hacer no es «ya está»: es que
				// el servidor no encontró ninguna que le tocara. Antes esto se
				// trataba igual que terminar, y la pantalla se detenía sin decir
				// nada —parecía que el botón no hacía nada—.
				if (r.procesadas === 0) {
					error =
						'El servidor no devolvió ninguna dirección para procesar, aunque quedan '
						+ r.pendientes
						+ ' pendientes. Vuelva a intentarlo; si sigue igual, avise.';

					break;
				}
			} catch (e) {
				error = e instanceof ApiError ? e.message : 'Se interrumpió la ubicación.';
				break;
			}
		}

		clearInterval(reloj);
		corriendo = false;
	}
</script>

<div class="tarjeta">
	<h2 class="tarjeta__titulo">Ubicación de las direcciones del censo</h2>
	<p class="tarjeta__nota">
		Convierte las direcciones escritas en el censo en puntos del mapa. Cada dirección se resuelve
		una sola vez y queda guardada, así que esto solo hay que correrlo cuando entran direcciones
		nuevas.
	</p>

	{#if error}
		<p class="aviso aviso--error" role="alert">
			<TriangleAlert size={15} aria-hidden="true" />
			{error}
		</p>
	{/if}

	{#if cargando}
		<p class="cargando">
			<LoaderCircle size={18} class="girando" aria-hidden="true" />
			Leyendo el estado…
		</p>
	{:else if estado}
		<div class="resumen">
			<div class="cifra">
				<span class="cifra__valor">{utiles}</span>
				<span class="cifra__nota">ubicadas y utilizables</span>
			</div>
			<div class="cifra">
				<span class="cifra__valor">{estado.pendientes_en_uso}</span>
				<span class="cifra__nota">por procesar</span>
			</div>
			<div class="cifra">
				<span class="cifra__valor">{estado.direcciones_del_censo}</span>
				<span class="cifra__nota">direcciones en el censo</span>
			</div>
		</div>

		<!--
			De dónde salen estas cifras, dicho en la propia pantalla.
			La cola es histórica: se llena con lo que el mapa va pidiendo y nada la
			vacía. Cuando el tablero leía una hoja de cálculo, ahí entraron las
			direcciones de esa hoja; hoy el mapa lee la base y aquellas quedaron
			dentro sin que nadie las use. Anunciar «tardará 32 minutos» contándolas
			era pedir media hora de espera por puntos que no se van a dibujar.
		-->
		<p class="fuente">
			Las direcciones salen de las <strong>{estado.direcciones_del_censo} que hoy tiene el censo
			en la base de datos</strong>, no de ninguna hoja de cálculo.
			{#if estado.obsoletas > 0}
				Hay <strong>{estado.obsoletas}</strong> en la cola que ya no corresponden a ninguna ficha
				—quedaron de la fuente anterior— y <strong>se saltan</strong>: no gastan tiempo ni
				consultas.
			{/if}
		</p>

		{#if corriendo}
			<!--
				La barra va con `role="progressbar"` y sus valores: quien use lector
				de pantalla tiene que poder saber por dónde va un proceso de veinte
				minutos sin mirar el dibujo.
			-->
			<div
				class="barra"
				class:barra--esperando={procesadas === 0}
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax={alArrancar}
				aria-valuenow={procesadas}
				aria-label="Direcciones procesadas"
			>
				<span
					class="barra__hecho"
					class:barra__hecho--algo={procesadas > 0}
					style="width:{procesadas === 0 ? 100 : avance}%"
				></span>
				<span class="barra__cifra">
					{#if procesadas === 0}
						Consultando el primer grupo…
					{:else}
						{avance}%
					{/if}
				</span>
			</div>
		{/if}

		{#if confirmandoRehacer}
			<div class="aviso aviso--alerta">
				<p>
					<strong>Se vuelven a ubicar todas las direcciones desde cero.</strong> Es lo que hay que
					hacer cuando el buscador mejora: lo ya guardado se calculó con las reglas anteriores y no
					se recalcula solo.
				</p>
				<p>
					Las <strong>corregidas a mano no se tocan</strong>. Después habrá que volver a pulsar
					«Ubicar las pendientes», y tardará lo mismo que la primera vez.
				</p>
				<div class="acciones">
					<button type="button" class="boton" onclick={rehacer} disabled={rehaciendo}>
						{#if rehaciendo}
							<LoaderCircle size={15} class="girando" aria-hidden="true" />
							Reencolando…
						{:else}
							Sí, rehacer todas
						{/if}
					</button>
					<button
						type="button"
						class="boton boton--suave"
						onclick={() => (confirmandoRehacer = false)}
					>
						Cancelar
					</button>
				</div>
			</div>
		{/if}

		{#if resultadoRehacer}
			<p class="aviso aviso--ok" role="status">{resultadoRehacer}</p>
		{/if}

		<table class="tabla">
			<thead>
				<tr><th>Resultado</th><th class="num">Direcciones</th></tr>
			</thead>
			<tbody>
				{#each Object.entries(ETIQUETA) as [codigo, etiqueta] (codigo)}
					<tr>
						<td>{etiqueta}</td>
						<td class="num">{estado.por_precision[codigo] ?? 0}</td>
					</tr>
				{/each}
			</tbody>
		</table>

		<div class="acciones">
			{#if !corriendo && !confirmandoRehacer}
				<button
					type="button"
					class="boton boton--suave"
					onclick={() => (confirmandoRehacer = true)}
					disabled={rehaciendo}
				>
					<RefreshCw size={15} aria-hidden="true" />
					Rehacer todas
				</button>
			{/if}

			{#if corriendo}
				<button type="button" class="boton boton--suave" onclick={() => (detener = true)}>
					<Square size={15} aria-hidden="true" />
					Detener
				</button>
				<span class="progreso">
					<LoaderCircle size={15} class="girando" aria-hidden="true" />
					{#if procesadas === 0}
						Consultando las primeras direcciones… puede tardar medio minuto
					{:else}
						{avance}% · {procesadas} de {alArrancar} · {ubicadas} ubicadas
					{/if}
					{#if minutosFaltan !== null}
						· faltan unos {minutosFaltan} min
					{/if}
				</span>
			{:else}
				<button
					type="button"
					class="boton"
					onclick={procesar}
					disabled={estado.pendientes_en_uso === 0}
				>
					<Play size={15} aria-hidden="true" />
					Ubicar las pendientes
				</button>
				{#if estado.pendientes_en_uso > 0}
					<span class="progreso">
						Tardará unos {minutosRestantes}
						{minutosRestantes === 1 ? 'minuto' : 'minutos'}. Deje esta pantalla abierta.
					</span>
				{/if}
			{/if}
		</div>
	{/if}
</div>

<div class="tarjeta">
	<h2 class="tarjeta__titulo">
		<MapPinned size={17} aria-hidden="true" />
		Cómo funciona
	</h2>
	<ul class="explicacion">
		<li>
			A cada dirección se le añade <strong>«Jamundí, Valle del Cauca»</strong> antes de
			consultarla. Sin eso, una «Carrera 11 # 8 26» existe en media Colombia.
		</li>
		<li>
			Se consulta primero <strong>OpenStreetMap</strong>, que es gratuito.
			{#if estado?.google_activo}
				Lo que falla se reintenta con <strong>Google</strong>, que está activado.
			{:else}
				Google está apagado: para encenderlo hay que poner su clave en la configuración del
				servidor.
			{/if}
		</li>
		<li>
			Va a una consulta por segundo porque es lo que permite OpenStreetMap. Por eso tarda, y por
			eso conviene dejarlo corriendo y ocuparse de otra cosa.
		</li>
		<li>
			Una dirección que solo se resuelve hasta el municipio <strong>no se pinta</strong>. Sería
			un punto válido y falso: amontonaría cientos de hogares sobre el parque principal.
		</li>
		<li>
			Las direcciones incompletas no tienen arreglo automático. Se corrigen en la hoja del censo,
			o el censador toma la ubicación exacta con el botón de ubicación del formulario.
		</li>
	</ul>
</div>

<style>
	.resumen {
		display: flex;
		flex-wrap: wrap;
		gap: 1.5rem;
		margin-bottom: 1rem;
	}

	.cifra {
		display: flex;
		flex-direction: column;
	}

	.cifra__valor {
		font-size: 1.8rem;
		font-weight: 700;
		line-height: 1.1;
		font-variant-numeric: tabular-nums;
	}

	.cifra__nota {
		font-size: 0.8rem;
		color: var(--color-muted);
	}

	.num {
		text-align: right;
		font-variant-numeric: tabular-nums;
	}

	.acciones {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 0.6rem;
		margin-top: 1rem;
	}

	.progreso {
		font-size: 0.83rem;
		color: var(--color-muted);
	}

	.explicacion {
		margin: 0;
		padding-left: 1.15rem;
		display: grid;
		gap: 0.45rem;
		font-size: 0.87rem;
		line-height: 1.55;
	}
	.fuente {
		margin: 0 0 0.9rem;
		font-size: 0.86rem;
		line-height: 1.5;
		color: var(--color-muted);
	}

	/* La barra de avance. Va después de los botones y a todo lo ancho porque es
	   lo que se mira durante veinte minutos: si fuera un detalle al lado del
	   botón, habría que buscarla cada vez. */
	.barra {
		position: relative;
		display: flex;
		align-items: center;
		height: 1.6rem;
		margin: 0.2rem 0 1rem;
		border-radius: 999px;
		background: var(--color-surface-alt);
		border: 1px solid var(--color-border);
		overflow: hidden;
	}

	.barra__hecho {
		display: block;
		height: 100%;
		background: var(--color-primary);
		/* La transición es lo que hace que se lea como avance y no como saltos:
		   los lotes llegan de golpe, de diez en diez. */
		transition: width 400ms ease;
	}

	/* Con menos del 2 % el relleno era una raya de un píxel: se veía igual que
	   una barra vacía, que es justo lo que hacía dudar de si el proceso corría. */
	.barra__hecho--algo {
		min-width: 1.6rem;
	}

	/*
		El brillo que recorre la parte hecha.
		Entre lote y lote pasan hasta treinta segundos sin que el porcentaje se
		mueva. Sin algo vivo, una barra quieta durante medio minuto se lee como un
		proceso colgado — y quien la mira cierra la pantalla, que es lo peor que
		puede pasar aquí.
	*/
	.barra__hecho::after {
		content: '';
		display: block;
		height: 100%;
		background: linear-gradient(
			90deg,
			transparent 0%,
			rgb(255 255 255 / 28%) 50%,
			transparent 100%
		);
		animation: recorrer 1.4s linear infinite;
	}

	@keyframes recorrer {
		from {
			transform: translateX(-100%);
		}
		to {
			transform: translateX(100%);
		}
	}

	@media (prefers-reduced-motion: reduce) {
		.barra__hecho::after {
			animation: none;
		}
	}

	/*
		La cifra, en texto normal y sin trucos de mezcla.
		Antes iba con `mix-blend-mode: difference` y un `filter` para que se leyera
		sobre las dos mitades de la barra. El filtro convierte a ese elemento en su
		propia raíz de composición y la mezcla acababa pintando un rectángulo
		opaco sobre TODA la barra: se tapaban el relleno y la propia cifra, y la
		barra parecía vacía aunque el proceso fuera por el 18 %.
	*/
	.barra__cifra {
		position: absolute;
		inset: 0;
		display: grid;
		place-items: center;
		font-size: 0.78rem;
		font-weight: 700;
		color: var(--color-text);
		/* Un contorno oscuro basta para que se lea igual sobre el azul del
		   relleno y sobre el fondo vacío, sin depender del tema. */
		text-shadow:
			0 1px 2px rgb(0 0 0 / 65%),
			0 0 3px rgb(0 0 0 / 45%);
		pointer-events: none;
	}


	/*
		Mientras no vuelve el primer lote no hay porcentaje que enseñar: la barra
		se llena entera de un tono apagado y deja que la corra el brillo. Es la
		diferencia entre «está trabajando» y «se colgó», y son hasta veinte
		segundos de espera antes de la primera cifra.
	*/
	.barra--esperando .barra__hecho {
		background: color-mix(in srgb, var(--color-primary) 35%, transparent);
		transition: none;
	}

</style>
