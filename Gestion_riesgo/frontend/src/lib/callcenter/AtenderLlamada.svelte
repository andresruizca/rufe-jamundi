<script lang="ts">
	// La pantalla de una llamada, dedicada a una sola persona.
	//
	// Ocupa el sitio de la lista en vez de flotar encima. Con seis bloques
	// dentro, una ventana flotante obliga a desplazar dentro de otro
	// desplazamiento, y la operadora acaba perdiendo de vista o el guión o el
	// formulario justo cuando la persona está esperando al teléfono.
	//
	// El orden es el de la llamada, no el del formulario: primero qué decir,
	// después con quién se habla y a qué número, y al final qué pasó.
	//
	// El guión sale entero desde el principio, no de a un paso. Un paso a la vez
	// obligaba a ir oprimiendo «Siguiente» mientras se habla —una mano ocupada
	// que no hay— y, peor, escondía lo que venía después: la operadora no podía
	// mirar de reojo si la pregunta que le acaban de hacer está tres párrafos
	// más abajo. Con la columna pegada y su propio desplazamiento, cabe entero.

	import { onDestroy, onMount } from 'svelte';
	import {
		ArrowLeft,
		Check,
		ChevronDown,
		ClipboardCopy,
		Headphones,
		LoaderCircle,
		MessageCircle,
		PhoneOff,
		TriangleAlert
	} from '@lucide/svelte';
	import { callCenterApi } from '$lib/api/servicios';
	import CompartirPreinscripcion from '$lib/components/CompartirPreinscripcion.svelte';
	import { almacenGuion } from './guionStore.svelte';
	import { leerGuion } from './guion';
	import type { EnvioWhatsapp, GestionLlamada, HogarParaLlamar } from './tipos';

	let {
		hogar,
		resultados,
		atendidaPorOtra = null,
		onCerrar,
		onGuardado
	}: {
		hogar: HogarParaLlamar;
		resultados: Record<string, string>;
		/** Nombre de la otra operadora que ya tenía este hogar abierto, si la hay. */
		atendidaPorOtra?: string | null;
		onCerrar: () => void;
		onGuardado: () => void;
	} = $props();

	const secciones = $derived(leerGuion(almacenGuion.guion?.cuerpo ?? ''));

	let formulario = $state({ resultado: '', nota: '', proxima_llamada: '', enlace_enviado: false });
	let guardando = $state(false);
	let errores = $state<Record<string, string>>({});
	let copiado = $state(false);
	let copiadoOficial = $state(false);

	// El envío del enlace por WhatsApp. `aviso` guarda el mensaje del servidor
	// tal cual: está escrito para leerse —«ya se le envió el 27/08 a las 18:40»,
	// «este hogar no tiene celular»— y traducirlo aquí solo lo empeoraría.
	let enviandoWa = $state(false);
	let waAviso = $state<{ texto: string; ok: boolean } | null>(null);

	/**
	 * Los WhatsApp que ya se le mandaron a este hogar.
	 *
	 * Se cargan al abrir la llamada, no al desplegar nada: es lo que sustituyó
	 * al bloqueo de 24 horas. Con tres operadoras sobre la misma lista, el freno
	 * útil no es prohibir el reenvío, sino que se vea lo que ya se mandó —y
	 * quién lo mandó— antes de volver a mandarlo.
	 */
	let envios = $state<EnvioWhatsapp[]>([]);

	let historial = $state<GestionLlamada[]>([]);
	let historialAbierto = $state(false);
	let historialPedido = $state(false);

	let latido: ReturnType<typeof setInterval> | null = null;

	const hoy = new Date().toISOString().slice(0, 10);

	onMount(() => {
		void almacenGuion.cargar();

		// Abrir esta pantalla ES tomar el hogar: es lo que ven las otras dos
		// operadoras para no marcarle a la misma familia. Se repite cada minuto
		// mientras siga abierta, porque el aviso caduca solo a los seis.
		avisar();
		latido = setInterval(avisar, 60_000);

		// Sin `await`: que la lista de envíos tarde no puede retrasar el resto de
		// la pantalla, que es lo que la operadora necesita para empezar a hablar.
		void cargarEnvios();
	});

	onDestroy(() => {
		if (latido) clearInterval(latido);
		void callCenterApi.atender(hogar.id, true).catch(() => {});
	});

	function avisar() {
		void callCenterApi.atender(hogar.id).catch(() => {});
	}

	async function cargarEnvios() {
		try {
			envios = (await callCenterApi.enviosWhatsapp(hogar.id)).envios;
		} catch {
			// Sin señal o sin permiso: se queda la lista vacía. Es información de
			// apoyo, y no puede impedir que la operadora atienda la llamada.
		}
	}

	/**
	 * «hoy a las 9:09», «ayer a las 18:40», «el 26/08 a las 9:41».
	 *
	 * Con la hora, y no solo la fecha como la `cuando()` del historial: entre
	 * tres operadoras que trabajan la misma lista a la vez, saber que el mensaje
	 * salió «hoy» no basta para decidir si repetirlo. Saber que salió hace diez
	 * minutos, sí.
	 */
	function fechaYHora(iso: string): string {
		const d = new Date(iso.replace(' ', 'T'));
		if (Number.isNaN(d.getTime())) return iso;

		const hora = d.toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' });
		const dias = Math.round(
			(new Date(hoy).getTime() - new Date(d.toISOString().slice(0, 10)).getTime()) / 86_400_000
		);

		if (dias === 0) return `hoy a las ${hora}`;
		if (dias === 1) return `ayer a las ${hora}`;

		return `el ${d.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit' })} a las ${hora}`;
	}

	async function copiarOficial() {
		try {
			await navigator.clipboard.writeText(almacenGuion.whatsappOficial);
			copiadoOficial = true;
			setTimeout(() => (copiadoOficial = false), 1800);
		} catch {
			// Sin permiso de portapapeles el número sigue en pantalla, grande y
			// separado en grupos, que es para lo que está ahí.
		}
	}

	async function copiar() {
		if (!hogar.telefono) return;

		try {
			await navigator.clipboard.writeText(hogar.telefono);
			copiado = true;
			setTimeout(() => (copiado = false), 1800);
		} catch {
			// Sin permiso de portapapeles el número sigue en pantalla, grande y
			// separado en grupos, para marcarlo a mano en el teléfono IP.
		}
	}

	/** 3183333510 → 318 333 3510. Diez cifras seguidas se marcan mal. */
	function agrupar(telefono: string): string {
		const d = telefono.replace(/\D+/g, '');

		return d.length === 10 ? `${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6)}` : telefono;
	}

	/**
	 * Le manda a esta persona el enlace del formulario por WhatsApp.
	 *
	 * Pide confirmación antes: es un mensaje real a una familia que acaba de
	 * perder parte de su casa, no una acción reversible. Y el botón se bloquea
	 * mientras dura, porque el proveedor tarda un par de segundos y el segundo
	 * clic sería un segundo mensaje.
	 */
	async function enviarWhatsapp(repetir = false) {
		if (enviandoWa || !hogar.telefono) return;

		const quien = hogar.nombre ?? 'este hogar';

		if (!repetir && !confirm(`¿Enviarle el enlace del formulario por WhatsApp a ${quien}?`)) {
			return;
		}

		enviandoWa = true;
		waAviso = null;

		try {
			const r = await callCenterApi.enviarWhatsapp(hogar.id, repetir);
			waAviso = { texto: `Enviado a ${r.nombre}`, ok: true };
			// El servidor devuelve el reporte ya actualizado: la operadora ve su
			// propio envío en la lista en el mismo momento en que le confirmamos
			// que salió. Sin esto dudaría, y volvería a pulsar.
			envios = r.envios ?? envios;

			// El envío ES una gestión: si el historial está abierto, que se vea.
			historialPedido = false;
			if (historialAbierto) {
				historialAbierto = false;
				await verHistorial();
			}
		} catch (e) {
			const err = e as { errors?: Record<string, string>; message?: string; status?: number };

			// 409 con envíos previos es «ya se le mandó, ¿seguro?». Se pregunta
			// una vez, con lo que dice el servidor, y si contesta que sí se
			// repite. El freno de los dos minutos también responde 409 pero no
			// admite `repetir`, así que ahí volverá a negarse — y con razón.
			if (err.status === 409 && !repetir) {
				const texto = err.message ?? 'A este hogar ya se le envió el WhatsApp.';

				if (confirm(`${texto}\n\n¿Enviárselo otra vez de todos modos?`)) {
					enviandoWa = false;
					await enviarWhatsapp(true);

					return;
				}

				waAviso = { texto, ok: false };
			} else {
				waAviso = {
					texto: err.errors?.telefono ?? err.message ?? 'No se pudo enviar el WhatsApp.',
					ok: false
				};
			}
		} finally {
			enviandoWa = false;
		}
	}

	async function verHistorial() {
		historialAbierto = !historialAbierto;

		if (!historialAbierto || historialPedido) return;

		historialPedido = true;

		try {
			const r = await callCenterApi.historial(hogar.id);
			historial = r.gestiones;
		} catch {
			historialPedido = false;
		}
	}

	async function guardar() {
		if (guardando || formulario.resultado === '') return;

		guardando = true;
		errores = {};

		try {
			await callCenterApi.registrar(hogar.id, formulario);
			onGuardado();
		} catch (e) {
			const err = e as { errors?: Record<string, string>; message?: string };
			errores = err.errors ?? {};

			if (Object.keys(errores).length === 0) {
				errores = { resultado: err.message ?? 'No se pudo guardar la llamada.' };
			}
		} finally {
			guardando = false;
		}
	}

	function cuando(iso: string | null): string {
		if (!iso) return '';

		return new Date(iso.replace(' ', 'T')).toLocaleDateString('es-CO', {
			day: '2-digit',
			month: 'short'
		});
	}
</script>

<div class="atencion">
	<!-- Salir siempre a la vista. Es lo que permite abandonar una llamada que no
	     entró sin tener que anotar algo que no pasó. -->
	<div class="atencion__barra">
		<button type="button" class="boton boton--suave" onclick={onCerrar}>
			<ArrowLeft size={15} aria-hidden="true" />
			Volver a la lista
		</button>

		<span class="atencion__ficha">
			{hogar.radicado} · {hogar.zona === 'RURAL' ? 'Rural' : 'Urbano'}
		</span>
	</div>

	{#if atendidaPorOtra}
		<p class="ocupado">
			<Headphones size={15} aria-hidden="true" />
			Lo está atendiendo <strong>{atendidaPorOtra}</strong>. Puede llamarlo igual, pero confirme
			antes con ella para no llamar dos veces a la misma familia.
		</p>
	{/if}

	<!--
		Dos columnas: el guión a la izquierda, ancho, y a la derecha con quién se
		habla y qué se anota.

		El guión ocupa la mayor parte porque es lo único que se lee EN VOZ ALTA
		mientras se habla; los datos de la derecha se consultan de reojo. Apilados
		uno debajo de otro, llegar al formulario obligaba a desplazar hasta perder
		el guión de vista, justo cuando hace falta.
	-->
	<div class="atencion__cuerpo">

	<!-- ① El guión ─────────────────────────────────────────────────────── -->
	<section class="bloque bloque--guion">
		<!--
			El número al que hay que mandar al ciudadano, arriba del guión y en
			grande.

			Es el mismo en las mil trescientas llamadas, y es lo único que la
			operadora tiene que dictar de memoria. Leerlo de un párrafo
			trescientas veces al día es la fricción que hay que quitar, y un
			dígito mal dictado manda a la familia a un número que no existe.
		-->
		{#if almacenGuion.whatsappOficial}
			<div class="oficial">
				<span class="oficial__que">Dígale que escriba por WhatsApp a</span>
				<strong class="oficial__numero">{agrupar(almacenGuion.whatsappOficial)}</strong>
				<button
					type="button"
					class="oficial__copiar"
					onclick={copiarOficial}
					aria-label="Copiar el número oficial de WhatsApp"
				>
					{#if copiadoOficial}
						<Check size={14} aria-hidden="true" />
						Copiado
					{:else}
						<ClipboardCopy size={14} aria-hidden="true" />
						Copiar
					{/if}
				</button>
				<span class="oficial__paso">Le contesta el asistente · responde <strong>1</strong> · recibe el enlace</span>
			</div>
		{/if}
		{#if almacenGuion.cargando && secciones.length === 0}
			<p class="cargando">
				<LoaderCircle size={16} class="girando" aria-hidden="true" />
				Cargando el guión…
			</p>
		{:else if secciones.length === 0}
			<p class="bloque__error">
				<TriangleAlert size={15} aria-hidden="true" />
				No se pudo cargar el guión. Puede hacer la llamada igual, pero avise al administrador.
			</p>
		{:else}
			<header class="guion__cabeza">
				<span class="rotulo">Guión de la llamada</span>
			</header>

			<div class="guion__cuerpo">
				{#each secciones as s, i (i)}
					{#if s.titulo}
						<h4 class="guion__sub">{s.titulo}</h4>
					{/if}

					{#each s.lineas as l, j (j)}
						{#if l.tipo === 'decir'}
							<p class="decir">{l.texto}</p>
						{:else if l.tipo === 'hacer'}
							<p class="hacer">{l.texto}</p>
						{:else if l.tipo === 'nunca'}
							<p class="nunca">
								<TriangleAlert size={13} aria-hidden="true" />
								{l.texto}
							</p>
						{:else if l.tipo === 'pregunta'}
							<div class="frecuente">
								<p class="frecuente__p">{l.texto}</p>
								{#if l.respuesta}<p class="decir decir--respuesta">{l.respuesta}</p>{/if}
							</div>
						{:else}
							<p class="hacer">{l.texto}</p>
						{/if}
					{/each}
				{/each}
			</div>
		{/if}
	</section>

	<div class="atencion__ficha-col">

	<!-- ② Con quién se habla y a qué número ───────────────────────────── -->
	<section class="bloque identidad">
		<span class="rotulo">Jefe de hogar según el RUFE</span>
		<h2 class="identidad__nombre" class:identidad__nombre--sin={!hogar.nombre}>
			{hogar.nombre ?? 'La ficha no registró jefe de hogar'}
		</h2>
		<p class="identidad__lugar">{hogar.lugar}</p>

		{#if hogar.telefono}
			<div class="telefono">
				<span class="telefono__numero">{agrupar(hogar.telefono)}</span>
				<button type="button" class="telefono__copiar" onclick={copiar}>
					{#if copiado}
						<Check size={14} aria-hidden="true" />
						Copiado
					{:else}
						<ClipboardCopy size={14} aria-hidden="true" />
						Copiar
					{/if}
				</button>
				<button
					type="button"
					class="telefono__wa"
					onclick={() => enviarWhatsapp()}
					disabled={enviandoWa}
				>
					{#if enviandoWa}
						<LoaderCircle size={14} class="girando" aria-hidden="true" />
						Enviando
					{:else}
						<MessageCircle size={14} aria-hidden="true" />
						{envios.some((e) => e.ok) ? 'Reenviar por WhatsApp' : 'Enviar por WhatsApp'}
					{/if}
				</button>
			</div>
			{#if waAviso}
				<p class="waaviso" class:waaviso--mal={!waAviso.ok} role="status">{waAviso.texto}</p>
			{/if}

			<!--
				El reporte de envíos, debajo del número y siempre a la vista.

				Sustituye al bloqueo de 24 horas que había antes. Prohibir el
				reenvío era negarle a la operadora un remedio que a veces hace
				falta —el mensaje no llegó, la persona lo borró, cambió de
				teléfono— y a la vez no resolvía el problema de fondo, que es que
				tres personas trabajan la misma lista sin verse. Esto sí lo
				resuelve: se ve qué se mandó, cuándo y quién.
			-->
			{#if envios.length > 0}
				<div class="envios">
					<h4 class="envios__titulo">
						WhatsApp enviados
						<span class="envios__cuenta">{envios.length}</span>
					</h4>
					<ul class="envios__lista">
						{#each envios as e (e.cuando + (e.quien ?? ''))}
							<li class="envio" class:envio--mal={!e.ok}>
								<span class="envio__marca" aria-hidden="true">
									{#if e.ok}
										<Check size={13} />
									{:else}
										<TriangleAlert size={13} />
									{/if}
								</span>
								<span class="envio__texto">
									<span class="envio__cuando">
										{e.ok ? 'Enviado' : 'Falló'} {fechaYHora(e.cuando)}
									</span>
									{#if e.quien}<span class="envio__quien">por {e.quien}</span>{/if}
									{#if e.error}<span class="envio__error">{e.error}</span>{/if}
								</span>
							</li>
						{/each}
					</ul>
				</div>
			{/if}
		{:else}
			<p class="sintel">
				<PhoneOff size={15} aria-hidden="true" />
				Esta ficha no registró teléfono. Por aquí no se le puede llegar.
			</p>
		{/if}
	</section>

	<!-- ③ Qué decidió el ingeniero ────────────────────────────────────── -->
	{#if hogar.descarte}
		<section class="bloque">
			<p class="decidido" class:decidido--fin={!hogar.descarte.llamar}>
				{#if hogar.descarte.llamar}
					<TriangleAlert size={16} aria-hidden="true" />
				{:else}
					<PhoneOff size={16} aria-hidden="true" />
				{/if}
				<span>
					<strong>{hogar.descarte.etiqueta}.</strong>
					{hogar.descarte.decirle}
					{#if hogar.preinscripcion}<em>Solicitud {hogar.preinscripcion.radicado}.</em>{/if}
				</span>
			</p>
		</section>
	{/if}

	<!-- ④ Mandarle el enlace, mientras sigue al teléfono ──────────────── -->
	{#if hogar.telefono}
		<section class="bloque">
			<CompartirPreinscripcion
				nombre={hogar.nombre ?? ''}
				telefono={hogar.telefono}
				titulo="Mandarle el enlace ahora"
			/>
		</section>
	{/if}

	<!-- ⑤ Cómo terminó ────────────────────────────────────────────────── -->
	<section class="bloque">
		<h3 class="bloque__titulo">¿Cómo terminó la llamada?</h3>

		<div class="opciones">
			{#each Object.entries(resultados) as [valor, etiqueta] (valor)}
				<label class="opcion" class:opcion--activa={formulario.resultado === valor}>
					<input type="radio" bind:group={formulario.resultado} value={valor} />
					<span>{etiqueta}</span>
				</label>
			{/each}
		</div>

		{#if errores.resultado}
			<p class="bloque__error">{errores.resultado}</p>
		{/if}

		<div class="campos">
			<label class="campo">
				<span class="campo__etiqueta">Cuándo volver a llamar</span>
				<input class="campo__control" type="date" min={hoy} bind:value={formulario.proxima_llamada} />
				{#if errores.proxima_llamada}
					<span class="bloque__error">{errores.proxima_llamada}</span>
				{/if}
			</label>

			<label class="campo campo--ancho">
				<span class="campo__etiqueta">Nota</span>
				<input
					class="campo__control"
					maxlength="500"
					placeholder="Ej.: pidió que se le llame después de las 5"
					bind:value={formulario.nota}
				/>
			</label>
		</div>

		<label class="opcion opcion--suelta">
			<input type="checkbox" bind:checked={formulario.enlace_enviado} />
			<span>Le mandé el enlace</span>
		</label>

		<div class="acciones">
			<button
				type="button"
				class="boton boton--principal"
				onclick={guardar}
				disabled={guardando || formulario.resultado === ''}
			>
				{guardando ? 'Guardando…' : 'Guardar y volver a la lista'}
			</button>
			<button type="button" class="boton boton--suave" onclick={onCerrar}>Cancelar</button>
		</div>
	</section>

	<!-- ⑥ Lo que ya se intentó ────────────────────────────────────────── -->
	{#if hogar.intentos > 0}
		<section class="bloque">
			<button type="button" class="historial__abrir" onclick={verHistorial}>
				<ChevronDown size={15} class={historialAbierto ? 'girado' : ''} aria-hidden="true" />
				Llamadas anteriores a este hogar · {hogar.intentos}
			</button>

			{#if historialAbierto}
				<ul class="historial">
					{#each historial as g (g.id)}
						<li>
							<strong>{cuando(g.creado_en)}</strong>
							— {resultados[g.resultado] ?? g.resultado}
							{#if g.usuario_email}· {g.usuario_email}{/if}
							{#if g.nota}<em>«{g.nota}»</em>{/if}
						</li>
					{:else}
						<li class="historial__vacio">Cargando…</li>
					{/each}
				</ul>
			{/if}
		</section>
	{/if}

	</div><!-- /columna de la derecha -->
	</div><!-- /las dos columnas -->
</div>

<style>
	.atencion {
		display: flex;
		flex-direction: column;
		gap: 0.85rem;
	}

	.atencion__barra {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.6rem;
		flex-wrap: wrap;
	}

	.atencion__ficha {
		font-size: 0.78rem;
		color: var(--color-muted);
		font-variant-numeric: tabular-nums;
	}

	.atencion__cuerpo {
		display: grid;
		grid-template-columns: minmax(0, 1fr);
		gap: 0.85rem;
		align-items: start;
	}

	.atencion__ficha-col {
		display: flex;
		flex-direction: column;
		gap: 0.85rem;
		min-width: 0;
	}

	@media (min-width: 62rem) {
		.atencion__cuerpo {
			/* Aproximadamente 60 / 40, como en la pizarra. El guión se lleva la
			   parte ancha porque son frases que se leen en voz alta: partidas en
			   una columna estrecha se leen a trompicones. */
			grid-template-columns: minmax(0, 1.5fr) minmax(20rem, 1fr);
		}

		/* El guión se queda a la vista mientras se rellena el formulario de al
		   lado. Con desplazamiento propio: si compartiera el de la página, bajar
		   a «¿Cómo terminó la llamada?» se lo llevaría fuera de la pantalla. */
		/* ── El número oficial ──────────────────────────────────────────────
	   Lo primero de la columna del guión, y con el contraste más alto de la
	   pantalla: es lo único que la operadora tiene que dictar de memoria. */
	.oficial {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 0.35rem 0.7rem;
		margin-bottom: 0.9rem;
		padding: 0.75rem 0.9rem;
		border: 1px solid var(--color-success);
		border-radius: 12px;
		background: var(--color-success-bg);
	}

	.oficial__que {
		flex-basis: 100%;
		font-size: 0.78rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: var(--color-success);
	}

	.oficial__numero {
		font-size: clamp(1.5rem, 4vw, 2rem);
		font-weight: 700;
		letter-spacing: 0.03em;
		font-variant-numeric: tabular-nums;
		color: var(--color-text);
	}

	.oficial__copiar {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		border: 1px solid var(--color-success);
		border-radius: 999px;
		background: none;
		color: var(--color-success);
		font-size: 0.8rem;
		font-weight: 600;
		padding: 0.25rem 0.7rem;
		cursor: pointer;
	}

	.oficial__paso {
		flex-basis: 100%;
		font-size: 0.83rem;
		line-height: 1.4;
		color: var(--color-muted);
	}

	.bloque--guion {
			position: sticky;
			top: calc(var(--alto-barra, 3.8rem) + 0.5rem);
			max-height: calc(100vh - var(--alto-barra, 3.8rem) - 1.5rem);
			overflow-y: auto;
		}
	}

	.bloque {
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		border-radius: 12px;
		padding: 1rem 1.1rem;
		display: flex;
		flex-direction: column;
		gap: 0.6rem;
	}

	.bloque--guion {
		border-color: var(--color-primary);
	}

	.bloque__titulo {
		margin: 0;
		font-size: 1rem;
		font-weight: 700;
	}

	.bloque__error {
		display: flex;
		align-items: flex-start;
		gap: 0.35rem;
		margin: 0;
		font-size: 0.82rem;
		color: var(--color-danger);
	}

	.rotulo {
		font-size: 0.7rem;
		font-weight: 700;
		letter-spacing: 0.07em;
		text-transform: uppercase;
		color: var(--color-muted);
	}

	.cargando {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 0;
		font-size: 0.85rem;
		color: var(--color-muted);
	}

	.ocupado {
		display: flex;
		align-items: flex-start;
		gap: 0.45rem;
		margin: 0;
		padding: 0.6rem 0.8rem;
		border-radius: 8px;
		background: var(--color-info-bg);
		color: var(--color-info);
		font-size: 0.85rem;
	}

	/* ── El guión ────────────────────────────────────────────────────── */

	.guion__cabeza {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.8rem;
		flex-wrap: wrap;
	}

	.guion__cuerpo {
		display: flex;
		flex-direction: column;
		gap: 0.55rem;
	}

	.guion__sub {
		margin: 0.5rem 0 0;
		font-size: 0.72rem;
		font-weight: 700;
		letter-spacing: 0.06em;
		text-transform: uppercase;
		color: var(--color-primary);
	}

	/* Lo que se lee en voz alta va más grande que nada en esta pantalla: es lo
	   único que la operadora mira mientras habla. */
	.decir {
		margin: 0;
		padding: 0.6rem 0.8rem;
		border-left: 3px solid var(--color-primary);
		background: var(--color-surface-alt);
		border-radius: 0 8px 8px 0;
		font-size: 1rem;
		line-height: 1.5;
	}

	.decir::before {
		content: '“';
	}

	.decir::after {
		content: '”';
	}

	.decir--respuesta {
		border-left-color: var(--color-secondary);
		font-size: 0.92rem;
	}

	.hacer {
		margin: 0;
		font-size: 0.85rem;
		line-height: 1.45;
		color: var(--color-muted);
	}

	.hacer::before {
		content: '▸ ';
		color: var(--color-secondary);
	}

	.nunca {
		display: flex;
		align-items: flex-start;
		gap: 0.35rem;
		margin: 0;
		padding: 0.45rem 0.6rem;
		border-radius: 6px;
		background: var(--color-danger-bg);
		color: var(--color-danger);
		font-size: 0.84rem;
		font-weight: 600;
		line-height: 1.4;
	}

	.frecuente {
		display: flex;
		flex-direction: column;
		gap: 0.25rem;
	}

	.frecuente__p {
		margin: 0;
		font-size: 0.86rem;
		font-weight: 700;
	}

	/* ── Quién es y a qué número ─────────────────────────────────────── */

	/* Se queda pegada al bajar: el número hace falta antes de marcar y el
	   nombre durante toda la llamada, para confirmar con quién se habla. */
	.identidad {
		position: sticky;
		top: calc(var(--alto-barra, 3.8rem) + 0.5rem);
		z-index: 5;
	}

	/* Con las dos columnas, la de la derecha es la que se desplaza y la
	   identidad va arriba del todo: pegarla ahí la dejaría flotando sobre su
	   propia columna sin ganar nada. */
	@media (min-width: 62rem) {
		.identidad {
			position: static;
		}
	}

	.identidad__nombre {
		margin: 0;
		font-size: 1.5rem;
		font-weight: 700;
		line-height: 1.15;
	}

	.identidad__nombre--sin {
		font-size: 1.05rem;
		font-style: italic;
		color: var(--color-muted);
	}

	.identidad__lugar {
		margin: 0;
		font-size: 0.85rem;
		color: var(--color-muted);
	}

	.telefono {
		display: inline-flex;
		align-items: center;
		gap: 0.7rem;
		padding: 0.4rem 0.45rem 0.4rem 0.9rem;
		border: 1px solid var(--color-border-strong);
		border-radius: 10px;
		background: var(--color-surface-alt);
		width: fit-content;
	}

	.telefono__numero {
		font-size: 1.6rem;
		font-weight: 700;
		letter-spacing: 0.04em;
		font-variant-numeric: tabular-nums;
		user-select: all;
	}

	.telefono__copiar {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		border: 1px solid var(--color-border);
		background: var(--color-surface);
		color: var(--color-muted);
		border-radius: 7px;
		padding: 0.32rem 0.6rem;
		font-size: 0.78rem;
		cursor: pointer;
	}

	.telefono__copiar:hover {
		color: var(--color-text);
		border-color: var(--color-border-strong);
	}

	/* Comparte la forma del botón de copiar, no su discreción: este manda un
	   mensaje real a una familia, así que se ve que es una acción. */
	.telefono__wa {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		border: 1px solid var(--color-border-strong);
		background: var(--color-surface);
		color: var(--color-text);
		border-radius: 7px;
		padding: 0.32rem 0.6rem;
		font-size: 0.78rem;
		cursor: pointer;
	}

	.telefono__wa:hover:not(:disabled) {
		border-color: var(--color-accent, var(--color-border-strong));
	}

	.telefono__wa:disabled {
		opacity: 0.6;
		cursor: progress;
	}

	/* ── El reporte de envíos ───────────────────────────────────────────── */
	.envios {
		margin-top: 0.7rem;
		border: 1px solid var(--color-border);
		border-radius: 10px;
		padding: 0.6rem 0.7rem;
		background: var(--color-surface-alt);
	}

	.envios__titulo {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 0 0 0.45rem;
		font-size: 0.75rem;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: var(--color-muted);
	}

	.envios__cuenta {
		border-radius: 999px;
		padding: 0.05rem 0.4rem;
		background: var(--color-surface);
		font-size: 0.72rem;
		letter-spacing: 0;
		font-variant-numeric: tabular-nums;
	}

	.envios__lista {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 0.35rem;
	}

	.envio {
		display: flex;
		align-items: flex-start;
		gap: 0.4rem;
		font-size: 0.82rem;
		line-height: 1.35;
		color: var(--color-success);
	}

	.envio--mal {
		color: var(--color-danger);
	}

	.envio__marca {
		flex: none;
		margin-top: 0.12rem;
	}

	.envio__texto {
		display: flex;
		flex-wrap: wrap;
		gap: 0.15rem 0.4rem;
		min-width: 0;
	}

	.envio__cuando {
		font-weight: 600;
	}

	/* Quién y por qué falló se apagan: lo primero que hay que leer es cuándo. */
	.envio__quien,
	.envio__error {
		color: var(--color-muted);
		overflow-wrap: anywhere;
	}

	.waaviso {
		margin: 0.45rem 0 0;
		font-size: 0.82rem;
		color: var(--color-muted);
	}

	/* El fallo se lee distinto: la operadora tiene a alguien esperando y no
	   puede quedarse con la duda de si el mensaje salió. */
	.waaviso--mal {
		color: var(--color-danger, #b42318);
	}

	.sintel {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 0;
		font-size: 0.86rem;
		color: var(--color-muted);
	}

	/* ── Lo que decidió el ingeniero ─────────────────────────────────── */

	.decidido {
		display: flex;
		align-items: flex-start;
		gap: 0.5rem;
		margin: 0;
		font-size: 0.9rem;
		line-height: 1.45;
		color: var(--color-text);
	}

	.decidido--fin {
		color: var(--color-danger);
	}

	.decidido em {
		opacity: 0.75;
		font-size: 0.82rem;
	}

	/* ── El formulario ───────────────────────────────────────────────── */

	.opciones {
		display: flex;
		flex-wrap: wrap;
		gap: 0.4rem;
	}

	.campos {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
		gap: 0.6rem;
	}

	.campo--ancho {
		grid-column: span 2;
	}

	@media (max-width: 40rem) {
		.campo--ancho {
			grid-column: span 1;
		}
	}

	.acciones {
		display: flex;
		flex-wrap: wrap;
		gap: 0.5rem;
		padding-top: 0.2rem;
	}

	/* ── Historial ───────────────────────────────────────────────────── */

	.historial__abrir {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		border: none;
		background: none;
		color: var(--color-muted);
		font-size: 0.85rem;
		font-weight: 600;
		cursor: pointer;
		padding: 0;
	}

	.historial__abrir:hover {
		color: var(--color-text);
	}

	.historial__abrir :global(.girado) {
		transform: rotate(180deg);
	}

	.historial {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 0.35rem;
		font-size: 0.83rem;
		color: var(--color-muted);
	}

	.historial em {
		display: block;
		opacity: 0.8;
	}

	.historial__vacio {
		font-style: italic;
	}
</style>
