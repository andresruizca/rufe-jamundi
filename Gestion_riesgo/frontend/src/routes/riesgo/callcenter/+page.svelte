<script lang="ts">
	// La campaña de llamadas que lleva a la gente del RUFE hasta la
	// preinscripción.
	//
	// El enlace del formulario ciudadano se le manda a quien YA está en el censo
	// y tiene que continuar el proceso. Eso no es un gesto suelto: es llamar uno
	// por uno, explicar para qué sirve, dejar constancia de que ya se llamó —para
	// que el turno de la tarde no repita— y volver a intentarlo con quien no
	// contestó.
	//
	// La pantalla se ordena por esa jornada: primero cuánto falta, después a
	// quién llamar ahora, y en cada fila las tres cosas que se hacen con esa
	// persona —marcar, mandarle el enlace, anotar qué pasó.

	import { onDestroy, onMount } from 'svelte';
	import {
		ArrowLeft,
		BookOpen,
		Check,
		CircleDot,
		ClipboardCheck,
		ClipboardCopy,
		Clock,
		Headphones,
		Info,
		LoaderCircle,
		PhoneForwarded,
		PhoneOff,
		Search,
		TriangleAlert,
		UserCheck,
		Users
	} from '@lucide/svelte';
	import { callCenterApi } from '$lib/api/servicios';
	import { sesion } from '$lib/stores/sesion.svelte';
	import KpiTile from '$lib/components/KpiTile.svelte';
	import CompartirFormulario from '$lib/components/CompartirFormulario.svelte';
	import PanelGuion from '$lib/callcenter/PanelGuion.svelte';
	import AtenderLlamada from '$lib/callcenter/AtenderLlamada.svelte';
	import {
		PESTANAS,
		estadoDe,
		porcentaje,
		type AtencionEnCurso,
		type FiltroEstado,
		type HogarParaLlamar,
		type ResumenCallCenter
	} from '$lib/callcenter/tipos';

	let resumen = $state<ResumenCallCenter | null>(null);
	let hogares = $state<HogarParaLlamar[]>([]);
	let resultados = $state<Record<string, string>>({});
	let total = $state(0);
	/**
	 * Cuántos hogares encuentra esta búsqueda en las OTRAS listas.
	 *
	 * La búsqueda respeta la pestaña abierta, y eso hacía daño en silencio: la
	 * operadora buscaba una cédula en «Falta llamar», no salía nada, y la
	 * conclusión natural —«esta familia no está en el censo»— era falsa. El
	 * hogar estaba en «Ya se preinscribieron», que era justo lo que tenía que
	 * decirle a quien tenía al teléfono.
	 */
	let enOtrasListas = $state(0);
	let pagina = $state(1);
	let paginas = $state(1);

	let estado = $state<FiltroEstado>('pendiente');
	let busqueda = $state('');
	let cargando = $state(true);
	let error = $state('');

	/**
	 * El hogar que se está atendiendo, si hay alguno.
	 *
	 * Cuando lo hay, la lista se aparta y en su sitio queda la pantalla de la
	 * llamada. Solo uno a la vez: se llama de a un hogar, y tener dos abiertos
	 * sería tener dos guiones distintos por delante.
	 */
	let atendiendo = $state<HogarParaLlamar | null>(null);

	/**
	 * Ver el guión entero, fuera de una llamada.
	 *
	 * El guión que se usa trabajando va dentro de «Atender llamada», paso a
	 * paso. Esto es para las dos cosas que no caben ahí: leerlo de corrido antes
	 * de empezar el turno, y que el administrador lo corrija sin tener que abrir
	 * la llamada de una familia —abrirla la marca como ocupada para las demás
	 * operadoras, y eso sería mentir por el camino—.
	 */
	let verGuion = $state(false);

	/**
	 * Quién está llamando a quién, entre las tres operadoras.
	 *
	 * Se pide aparte de la lista y se refresca sola. Recargar la lista entera
	 * para esto borraría lo que la operadora esté escribiendo en su anotación —y
	 * eso ocurre justo mientras habla con alguien.
	 */
	let atenciones = $state<Map<number, AtencionEnCurso>>(new Map());
	let copiado = $state<number | null>(null);

	let latidoLista: ReturnType<typeof setInterval> | null = null;

	const hoy = new Date().toISOString().slice(0, 10);
	const puedeEditarGuion = $derived(sesion.rol === 'ADMINISTRADOR');

	onMount(() => {
		void cargar();
		void refrescarAtenciones();

		// Cada 25 segundos: lo suficiente para que una operadora vea que otra
		// tomó un hogar antes de marcarlo, sin convertir la pantalla en una
		// consulta continua contra la base durante ocho horas.
		latidoLista = setInterval(() => void refrescarAtenciones(), 25_000);
	});

	onDestroy(() => {
		if (latidoLista) clearInterval(latidoLista);
	});

	async function refrescarAtenciones() {
		try {
			const r = await callCenterApi.atenciones();
			atenciones = new Map(r.atenciones.map((a) => [a.reporte_id, a]));
		} catch {
			// Sin señal o con la sesión caída, el aviso simplemente no se
			// actualiza. No se le pone un error en pantalla a quien está
			// llamando por una cosa accesoria: la lista sigue sirviendo.
		}
	}

	/**
	 * Quién tiene abierto este hogar, si no soy yo.
	 *
	 * Verse a uno mismo en el aviso no informa de nada y hace dudar de si se
	 * está pisando a alguien.
	 */
	function otraOperadora(h: HogarParaLlamar): string | null {
		const a = atenciones.get(h.id) ?? null;
		const quien = a?.usuario_nombre ?? h.atendida?.quien ?? null;
		const quienId = a?.usuario_id ?? h.atendida?.usuario_id ?? null;

		if (quien === null) return null;
		if (quienId !== null && quienId === (sesion.usuario?.id ?? null)) return null;

		return quien;
	}

	/**
	 * Copiar el número.
	 *
	 * Las llamadas NO salen del computador: cada operadora tiene un teléfono IP
	 * sobre la mesa. Un enlace `tel:` aquí no marca nada —abre un programa que
	 * no existe o no hace nada—, y lo que de verdad hace falta es leer el número
	 * bien y tenerlo en el portapapeles.
	 */
	async function copiarTelefono(h: HogarParaLlamar) {
		if (!h.telefono) return;

		try {
			await navigator.clipboard.writeText(h.telefono);
			copiado = h.id;
			setTimeout(() => (copiado = copiado === h.id ? null : copiado), 1800);
		} catch {
			// Sin permiso de portapapeles no pasa nada: el número está en
			// pantalla, grande y separado en grupos, para marcarlo a mano.
		}
	}

	/** 3117657814 → 311 765 7814. Un número de diez cifras seguidas se marca mal. */
	function agrupar(telefono: string): string {
		const d = telefono.replace(/\D+/g, '');

		return d.length === 10 ? `${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6)}` : telefono;
	}

	let ultimaPeticion = 0;

	async function cargar() {
		const mia = ++ultimaPeticion;
		cargando = true;
		error = '';

		try {
			// El resumen va aparte de la lista porque no depende del filtro ni de
			// la página: es el estado de TODO el censo, y cambiaría al pasar de
			// página si viajara con ella.
			const [r, l] = await Promise.all([
				callCenterApi.resumen(),
				callCenterApi.hogares({ estado, q: busqueda, pagina })
			]);

			// Llegó tarde: entre tanto la operadora siguió escribiendo y ya se
			// pidió otra cosa. Se descarta entera.
			if (mia !== ultimaPeticion) return;

			resumen = r.resumen;
			hogares = l.hogares;
			resultados = l.resultados;
			total = l.paginacion.total;
			enOtrasListas = l.en_otras_listas ?? 0;
			paginas = l.paginacion.paginas;
		} catch (e) {
			if (mia !== ultimaPeticion) return;
			error = e instanceof Error ? e.message : 'No se pudo cargar la lista de llamadas.';
		} finally {
			if (mia === ultimaPeticion) cargando = false;
		}
	}

	function cambiarPestana(v: FiltroEstado) {
		estado = v;
		pagina = 1;
		void cargar();
	}

	function buscar(e: Event) {
		e.preventDefault();
		if (esperaTecleo) clearTimeout(esperaTecleo);
		pagina = 1;
		void cargar();
	}

	/**
	 * Buscar mientras se escribe.
	 *
	 * Quien opera está al teléfono: «soy fulana, me llamaron ayer». Obligarla a
	 * escribir y además oprimir un botón le cuesta un segundo de silencio en
	 * cada llamada, trescientas veces al día.
	 *
	 * Busca por nombre, cédula, teléfono y radicado. Los dos primeros los
	 * resuelve el servidor comparando CIFRAS, no texto: da igual que el número
	 * se escriba con espacios, con guiones o con el +57 delante. Ver
	 * `CallCenterController::condicionDeBusqueda`.
	 *
	 * Los 300 ms son para no mandar una consulta por tecla, y el número de orden
	 * es para que una respuesta lenta de «312» no pise a la de «3127» —que llega
	 * después pero se pidió más tarde—. Sin él, la lista muestra el resultado de
	 * lo que la operadora escribió hace dos teclas.
	 */
	let esperaTecleo: ReturnType<typeof setTimeout> | null = null;

	function alTeclear() {
		if (esperaTecleo) clearTimeout(esperaTecleo);

		esperaTecleo = setTimeout(() => {
			pagina = 1;
			void cargar();
		}, 300);
	}

	/**
	 * Abre la llamada de este hogar.
	 *
	 * Se sube la página antes de cambiar: la pantalla nueva empieza por el
	 * guión, y aparecer a media altura haría que la operadora empezara a leer
	 * por la mitad del saludo.
	 */
	function atender(h: HogarParaLlamar) {
		atendiendo = h;
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function cerrarAtencion() {
		atendiendo = null;
		void refrescarAtenciones();
	}

	async function trasGuardar() {
		atendiendo = null;
		await cargar();
		await refrescarAtenciones();
	}

	function cuando(iso: string | null): string {
		if (!iso) return '';

		return new Date(iso.replace(' ', 'T')).toLocaleDateString('es-CO', {
			day: '2-digit',
			month: 'short'
		});
	}
</script>

<div class="tarjeta">
	<!--
		El enlace del formulario, arriba y siempre a mano.

		Es la herramienta de trabajo del operador: llama, explica, y manda el
		enlace. Dentro de cada hogar está el botón que lo manda a ESE número —lo
		que hace falta casi siempre—, pero también hace falta el enlace suelto: para
		dictarlo, para abrirlo y acompañar a alguien paso a paso por teléfono, o
		para pegarlo en el chat de una junta de acción comunal.
	-->
	<div class="encabezado">
		<div class="encabezado__texto">
			<h2 class="tarjeta__titulo">Avance de la campaña</h2>
			<p class="tarjeta__nota">
				Cuánta gente del censo ha llegado ya al formulario de preinscripción, y cuánta falta por
				llamar.
			</p>
		</div>

		<CompartirFormulario />
	</div>

	{#if resumen}
		<div class="kpi-grid">
			<KpiTile
				label="Hogares en el RUFE"
				value={resumen.total}
				color="var(--color-primary)"
				icon={Users}
				sub="El universo de la campaña"
			/>
			<!-- La cifra que mide el final del camino. Antes este sitio lo ocupaba
			     «ya se preinscribieron», que mide formularios llenados, no
			     viviendas inspeccionadas. -->
			<KpiTile
				label="Inspección aprobada"
				value={resumen.terminados}
				color="var(--color-success)"
				icon={Check}
				sub="{porcentaje(resumen.terminados, resumen.total)} del censo"
			/>
			<KpiTile
				label="Esperan la inspección"
				value={resumen.preinscritos}
				color="var(--color-info)"
				icon={ClipboardCheck}
				sub="Ya pidieron el turno"
			/>
			<KpiTile
				label="Faltan por llamar"
				value={resumen.sin_llamar}
				color="var(--color-highlight-dark)"
				icon={PhoneForwarded}
				sub="Nadie los ha contactado"
			/>
			<!-- La cifra más accionable del tablero: familias que ya llenaron el
			     formulario entero y se quedaron a una foto de entrar. -->
			<KpiTile
				label="Les faltó algo"
				value={resumen.por_subsanar}
				color="var(--color-warning)"
				icon={TriangleAlert}
				sub="Hay que volver a llamarlas"
			/>
			<KpiTile
				label="Llamados, sin registrarse"
				value={resumen.contactados_sin_preinscribir}
				color="var(--color-accent)"
				icon={CircleDot}
				sub="Ya se les explicó"
			/>
			<KpiTile
				label="Volver a llamar hoy"
				value={resumen.para_hoy}
				color="var(--color-secondary-dark)"
				icon={Clock}
				sub="Quedaron para hoy"
			/>
			<KpiTile
				label="Sin teléfono"
				value={resumen.sin_telefono}
				color="var(--color-muted)"
				icon={PhoneOff}
				sub="Sin número en la ficha"
			/>
			<KpiTile
				label="No aplica"
				value={resumen.no_aplica}
				color="var(--color-muted)"
				icon={UserCheck}
				sub="Fuera de la campaña"
			/>
		</div>
	{/if}
</div>

{#if atendiendo}
	<!--
		Atendiendo a una persona: la lista se aparta y esta pantalla ocupa su
		sitio. No flota encima. Con seis bloques dentro, una ventana flotante
		obliga a desplazar dentro de otro desplazamiento, y se acaba perdiendo de
		vista o el guión o el formulario justo mientras alguien espera al
		teléfono.

		El guión no está en ninguna columna al lado: va dentro de esta pantalla,
		paso a paso. Tenerlo en los dos sitios ponía el mismo texto dos veces, en
		dos estados distintos, y obligaba a decidir cuál de los dos se lee.
	-->
	<div class="atendiendo">
		<AtenderLlamada
			hogar={atendiendo}
			{resultados}
			atendidaPorOtra={otraOperadora(atendiendo)}
			onCerrar={cerrarAtencion}
			onGuardado={trasGuardar}
		/>
	</div>
{:else if verGuion}
	<div class="atendiendo atendiendo--estrecho">
		<div class="atencion__barra">
			<button type="button" class="boton boton--suave" onclick={() => (verGuion = false)}>
				<ArrowLeft size={15} aria-hidden="true" />
				Volver a la lista
			</button>
		</div>
		<PanelGuion puedeEditar={puedeEditarGuion} />
	</div>
{:else}
<div class="tarjeta" style="margin-top:1.25rem">
	<div class="titulo-fila">
		<h2 class="tarjeta__titulo">A quién llamar</h2>
		<button type="button" class="boton boton--suave" onclick={() => (verGuion = true)}>
			<BookOpen size={15} aria-hidden="true" />
			{puedeEditarGuion ? 'Ver o editar el guión' : 'Ver el guión'}
		</button>
	</div>

	<!--
		Las pestañas abren en «Falta llamar», que es el trabajo del día. Nada se
		esconde: quitando el filtro se revisa lo ya hecho, que es lo que hace falta
		cuando un ciudadano vuelve a llamar preguntando.
	-->
	<div class="pestanas" role="tablist">
		{#each PESTANAS as p (p.valor)}
			<button
				type="button"
				role="tab"
				class="pestana"
				class:pestana--urgente={p.urgente}
				class:pestana--activa={estado === p.valor}
				aria-selected={estado === p.valor}
				onclick={() => cambiarPestana(p.valor)}
			>
				{p.etiqueta}
			</button>
		{/each}
	</div>

	<form class="buscador" onsubmit={buscar}>
		<label class="visualmente-oculto" for="cc-buscar">Buscar</label>
		<Search class="buscador__lupa" size={16} aria-hidden="true" />
		<input
			id="cc-buscar"
			class="campo__control"
			type="search"
			placeholder="Nombre, cédula, teléfono o radicado"
			bind:value={busqueda}
			oninput={alTeclear}
		/>
		{#if cargando && busqueda !== ''}
			<LoaderCircle class="girando" size={16} aria-hidden="true" />
		{/if}
	</form>

	{#if error}
		<p class="aviso aviso--error" role="alert">
			<TriangleAlert size={15} aria-hidden="true" />
			{error}
		</p>
	{/if}

	<!--
		Lo que la búsqueda encontró FUERA de esta pestaña.

		Sin esto, buscar una cédula en «Falta llamar» y no ver nada se lee como
		«esta familia no está en el censo», que es la respuesta más cara que
		puede dar esta pantalla — y la que más se parece a una respuesta buena.
	-->
	{#if enOtrasListas > 0 && !cargando}
		<p class="aviso aviso--info" role="status">
			<Info size={15} aria-hidden="true" />
			<span>
				{#if hogares.length === 0}
					Aquí no hay coincidencias, pero hay
				{:else}
					Además de {total === 1 ? 'este' : 'estos'}, hay
				{/if}
				<strong>{enOtrasListas} {enOtrasListas === 1 ? 'hogar' : 'hogares'}</strong>
				en otras listas con lo que buscó.
			</span>
			<button type="button" class="ver-todos" onclick={() => cambiarPestana('todos')}>
				Buscar en todas
			</button>
		</p>
	{/if}

	{#if cargando}
		<p class="cargando">
			<LoaderCircle size={18} class="girando" aria-hidden="true" />
			Cargando…
		</p>
	{:else if hogares.length === 0}
		<p class="vacio">
			<!--
				El ✓ dice «no queda trabajo pendiente», que es verdad cuando la
				lista está vacía por sí sola. Con una búsqueda escrita es otra
				cosa —no se encontró lo que se buscaba— y felicitar a la operadora
				por no encontrar a la familia que tiene al teléfono no ayuda.
			-->
			{#if busqueda !== ''}
				<Search size={22} aria-hidden="true" />
			{:else}
				<Check size={24} aria-hidden="true" />
			{/if}
			<span>
				{#if busqueda !== ''}
					No hay coincidencias en esta lista.
				{:else if estado === 'pendiente'}
					No queda nadie por llamar en esta lista.
				{:else}
					No hay hogares en esta lista.
				{/if}
			</span>
		</p>
	{:else}
		<p class="conteo">{total} {total === 1 ? 'hogar' : 'hogares'}</p>

		<ul class="hogares">
			{#each hogares as h (h.id)}
				{@const st = estadoDe(h)}
				{@const otra = otraOperadora(h)}
				<li class="hogar" class:hogar--nollamar={h.no_llamar}>
					<div class="hogar__cabeza">
						<div class="hogar__quien">
							<span class="hogar__nombre" class:hogar__nombre--sin={!h.nombre}>
								{h.nombre ?? 'Sin jefe de hogar registrado'}
							</span>
							<span class="hogar__lugar">
								{h.lugar} · {h.zona === 'RURAL' ? 'Rural' : 'Urbano'} · {h.radicado}
							</span>
							{#if h.ultima}
								<span class="hogar__ultima">
									Última llamada {cuando(h.ultima.creado_en)}: {h.ultima.etiqueta}
									{#if h.intentos > 1}· {h.intentos} intentos{/if}
									{#if h.ultima.nota}<em>«{h.ultima.nota}»</em>{/if}
								</span>
							{/if}
							{#if h.proxima_llamada}
								<span class="hogar__proxima" class:hogar__proxima--hoy={h.proxima_llamada <= hoy}>
									<Clock size={12} aria-hidden="true" />
									Volver a llamar el {h.proxima_llamada}
								</span>
							{/if}
						</div>

						<span class="pastilla pastilla--{st.clase}">{st.texto}</span>
					</div>

					{#if otra}
						<!-- Entre tres personas trabajando la misma cola, esto es lo que
						     evita que una familia reciba tres llamadas seguidas de la
						     Alcaldía diciéndole lo mismo. Es un aviso, no un candado: la
						     fila se puede tomar igual si hace falta. -->
						<p class="hogar__ocupado">
							<Headphones size={14} aria-hidden="true" />
							Lo está atendiendo <strong>{otra}</strong> ahora mismo.
						</p>
					{/if}

					{#if h.descarte}
						<!-- Lo que decidió el ingeniero, con lo que hay que decirle a la
						     persona. Va antes del teléfono a propósito: es lo primero que
						     la operadora tiene que leer, no algo que descubra a mitad de
						     la llamada. -->
						<p class="decidido" class:decidido--fin={!h.descarte.llamar}>
							{#if h.descarte.llamar}
								<TriangleAlert size={15} aria-hidden="true" />
							{:else}
								<PhoneOff size={15} aria-hidden="true" />
							{/if}
							<span>
								<strong>{h.descarte.etiqueta}.</strong>
								{h.descarte.decirle}
								{#if h.preinscripcion}
									<em>Solicitud {h.preinscripcion.radicado}.</em>
								{/if}
							</span>
						</p>
					{/if}

					{#if h.telefono}
						<div class="hogar__acciones">
							<!--
								El número, grande y separado en grupos, NO un enlace `tel:`.

								Las llamadas no salen del computador: cada operadora tiene un
								teléfono IP sobre la mesa. Un botón azul que dice «Llamar
								al…» y no marca nada es peor que no tenerlo — se toca, no
								pasa nada, y se pierde el turno averiguando por qué.

								Lo que hace falta es leerlo sin equivocarse de dígito y
								poder copiarlo. Eso es lo que hay aquí.
							-->
							<div class="telefono">
								<span class="telefono__numero">{agrupar(h.telefono)}</span>
								<button
									type="button"
									class="telefono__copiar"
									onclick={() => copiarTelefono(h)}
									title="Copiar el número"
								>
									{#if copiado === h.id}
										<Check size={14} aria-hidden="true" />
										Copiado
									{:else}
										<ClipboardCopy size={14} aria-hidden="true" />
										Copiar
									{/if}
								</button>
							</div>

							<!-- «Atender» y no «Anotar»: es lo que de verdad hace —abre la
							     llamada entera, con su guión— y además es lo que avisa a
							     las otras dos operadoras de que este hogar está ocupado. -->
							<button
								type="button"
								class="boton"
								class:boton--principal={!h.no_llamar}
								class:boton--suave={h.no_llamar}
								onclick={() => atender(h)}
							>
								Atender llamada
							</button>
						</div>
					{:else}
						<p class="hogar__sintel">
							<PhoneOff size={14} aria-hidden="true" />
							Esta ficha no registró teléfono. Por aquí no se le puede llegar.
						</p>
					{/if}

					{#if h.agotado && !h.preinscrita}
						<p class="hogar__agotado">
							<TriangleAlert size={14} aria-hidden="true" />
							Ya se intentó {h.intentos} veces. Conviene buscar otra vía antes de seguir marcando.
						</p>
					{/if}

				</li>
			{/each}
		</ul>

		{#if paginas > 1}
			<div class="paginacion">
				<button
					type="button"
					class="boton boton--suave"
					disabled={pagina <= 1}
					onclick={() => {
						pagina -= 1;
						void cargar();
					}}
				>
					Anterior
				</button>
				<span>Página {pagina} de {paginas}</span>
				<button
					type="button"
					class="boton boton--suave"
					disabled={pagina >= paginas}
					onclick={() => {
						pagina += 1;
						void cargar();
					}}
				>
					Siguiente
				</button>
			</div>
		{/if}
	{/if}

	<!--
		Dos cosas que quien opera tiene que saber, y que de otro modo se descubren
		mal: una por sorpresa cuando alguien reclame, y la otra marcando de más.
	-->
	<p class="pie">
		<strong>Atender una llamada no cambia el estado de la ficha del censo.</strong> Son dos
		procesos distintos.
		<br />
		«Ya se preinscribió» se detecta solo, cruzando la cédula: si la persona diligencia el
		formulario después de colgar, aparecerá en la siguiente carga sin que usted marque nada.
	</p>
</div>
{/if}

<style>
	.titulo-fila {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.8rem;
		flex-wrap: wrap;
	}

	.titulo-fila .tarjeta__titulo {
		margin: 0;
	}

	.atencion__barra {
		display: flex;
		align-items: center;
		gap: 0.6rem;
		margin-bottom: 0.85rem;
	}

	/* La pantalla de una llamada ocupa el sitio de la lista, a todo el ancho:
	   dentro ya se reparte en dos columnas —guión y datos del ciudadano—, y es
	   ahí donde se limita el ancho de la línea de texto. */
	.atendiendo {
		margin-top: 1.25rem;
	}

	/* El guión leído de corrido sí se estrecha: es una sola columna de frases, y
	   a 1900 px de ancho una línea se vuelve ilegible de lado a lado. */
	.atendiendo--estrecho {
		max-width: 62rem;
		margin-left: auto;
		margin-right: auto;
	}

	/* ── El teléfono ─────────────────────────────────────────────────────────
	   Grande y en cifra tabular: la operadora lo lee de la pantalla y lo marca
	   en un aparato aparte, y un dígito mal leído es una llamada perdida y una
	   familia que no se entera. */
	.telefono {
		display: inline-flex;
		align-items: center;
		gap: 0.6rem;
		padding: 0.35rem 0.4rem 0.35rem 0.85rem;
		border: 1px solid var(--color-border-strong);
		border-radius: 10px;
		background: var(--color-surface-alt);
	}

	.telefono__numero {
		font-size: 1.35rem;
		font-weight: 700;
		letter-spacing: 0.04em;
		font-variant-numeric: tabular-nums;
		color: var(--color-text);
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
		padding: 0.3rem 0.55rem;
		font-size: 0.75rem;
		cursor: pointer;
	}

	.telefono__copiar:hover {
		color: var(--color-text);
		border-color: var(--color-border-strong);
	}

	/* ── Lo que decidió el ingeniero ─────────────────────────────────────────
	   Va antes del teléfono y ocupa toda la fila: es lo primero que hay que
	   leer, no un detalle que se descubra a mitad de la llamada. */
	.decidido {
		display: flex;
		align-items: flex-start;
		gap: 0.45rem;
		margin: 0.6rem 0 0;
		padding: 0.55rem 0.7rem;
		border-radius: 8px;
		background: var(--color-warning-bg);
		color: var(--color-text);
		font-size: 0.84rem;
		line-height: 1.45;
	}

	.decidido--fin {
		background: var(--color-danger-bg);
		color: var(--color-danger);
	}

	.decidido em {
		opacity: 0.75;
		font-size: 0.78rem;
	}

	/* Un hogar que NO hay que llamar se apaga entero. Que siga en la lista es
	   deliberado —hace falta cuando la persona llama preguntando— pero no debe
	   competir por la atención con los que sí hay que marcar. */
	.hogar--nollamar {
		opacity: 0.62;
	}

	.hogar__ocupado {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 0.5rem 0 0;
		padding: 0.35rem 0.6rem;
		border-radius: 999px;
		background: var(--color-info-bg);
		color: var(--color-info);
		font-size: 0.78rem;
		width: fit-content;
	}

	/* La pestaña de lo que se quedó a un paso: se ve distinta sin gritar. */
	.pestana--urgente:not(.pestana--activa) {
		border-color: var(--color-warning);
		color: var(--color-warning);
	}

	.buscador :global(.buscador__lupa) {
		color: var(--color-muted);
		flex: none;
	}

	/* El título y el botón de compartir en la misma línea cuando cabe. El botón
	   no se estira: es una herramienta a mano, no el asunto de la pantalla. */
	.encabezado {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 0.9rem;
		flex-wrap: wrap;
	}

	.encabezado__texto {
		flex: 1 1 22rem;
		min-width: 0;
	}

	.encabezado__texto .tarjeta__titulo {
		margin-top: 0;
	}


	/* Las cifras de la campaña, en UNA fila.
	   Son la foto del avance: leerlas de un vistazo es lo que hace que alguien
	   note que «Les faltó algo» subió a treinta. Partidas en dos filas, la
	   segunda parece una nota al pie y deja de mirarse.

	   `grid-auto-flow: column` y NO un número de columnas escrito a mano. La
	   primera versión decía `repeat(7, …)` porque conté siete tarjetas y son
	   ocho: la octava se fue a una segunda fila ella sola. Así la rejilla no
	   necesita saber cuántas hay, y añadir una novena mañana no vuelve a
	   partirla.

	   `minmax(0, 1fr)`: sin el mínimo en cero, una etiqueta larga —«Llamados,
	   sin registrarse»— ensancha su columna y descuadra las demás.

	   Por debajo de 1250 px ocho tarjetas no se leen, así que se vuelven a
	   repartir en las filas que hagan falta. */
	.kpi-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr));
		gap: 0.55rem;
		margin-top: 1rem;
	}

	@media (min-width: 1250px) {
		.kpi-grid {
			grid-template-columns: none;
			grid-auto-flow: column;
			grid-auto-columns: minmax(0, 1fr);
		}
	}

	/* Ocho cifras donde antes había cinco: las tarjetas se aprietan un poco.
	   Solo aquí —KpiTile lo comparten el tablero y los mapas, donde son cuatro
	   y tienen sitio de sobra—. El número no se toca: es lo que se lee. */
	.kpi-grid :global(.kpi-tile) {
		padding: 10px 11px;
		gap: 3px;
	}

	.kpi-grid :global(.kpi-label) {
		font-size: 11.5px;
		gap: 5px;
		line-height: 1.25;
	}

	.kpi-grid :global(.kpi-sub) {
		font-size: 11px;
		line-height: 1.3;
	}

	.pestanas {
		display: flex;
		gap: 0.35rem;
		flex-wrap: wrap;
		margin: 1rem 0 0.8rem;
	}

	.pestana {
		padding: 0.45rem 0.8rem;
		border: 1px solid var(--color-border);
		border-radius: var(--radius-full);
		background: var(--color-surface);
		color: var(--color-muted);
		font: inherit;
		font-size: 0.84rem;
		cursor: pointer;
	}

	.pestana--activa {
		background: var(--color-primary);
		border-color: var(--color-primary);
		color: #fff;
		font-weight: 600;
	}

	.buscador {
		display: flex;
		gap: 0.4rem;
		margin-bottom: 0.9rem;
	}

	.buscador .campo__control {
		flex: 1;
		min-width: 0;
	}

	/* El aviso lleva un botón porque decirle a la operadora que hay resultados
	   en otra lista y dejarla buscar la pestaña a mano es media solución. */
	.ver-todos {
		flex: none;
		margin-left: auto;
		border: 1px solid currentColor;
		background: none;
		color: inherit;
		border-radius: 999px;
		padding: 0.25rem 0.7rem;
		font-size: 0.8rem;
		font-weight: 600;
		cursor: pointer;
	}

	.ver-todos:hover {
		background: rgb(255 255 255 / 0.08);
	}

	.conteo {
		margin: 0 0 0.6rem;
		font-size: 0.82rem;
		color: var(--color-muted);
	}

	.hogares {
		list-style: none;
		margin: 0;
		padding: 0;
		display: grid;
		gap: 0.6rem;
	}

	.hogar {
		padding: 0.85rem 0.9rem;
		border: 1px solid var(--color-border);
		border-radius: 12px;
		background: var(--color-surface);
	}

	.hogar__cabeza {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 0.7rem;
		flex-wrap: wrap;
	}

	.hogar__quien {
		display: grid;
		gap: 0.15rem;
		min-width: 0;
		flex: 1 1 16rem;
	}

	.hogar__nombre {
		font-weight: 600;
		font-size: 0.95rem;
		overflow-wrap: anywhere;
	}

	/* Sin jefe de hogar no es un nombre: en gris y cursiva, para que no se lea
	   como si la familia se llamara así. */
	.hogar__nombre--sin {
		font-weight: 500;
		font-style: italic;
		color: var(--color-muted);
	}

	.hogar__lugar,
	.hogar__ultima,
	.hogar__proxima {
		font-size: 0.78rem;
		color: var(--color-muted);
		overflow-wrap: anywhere;
	}

	.hogar__proxima {
		display: flex;
		align-items: center;
		gap: 0.25rem;
	}

	.hogar__proxima--hoy {
		color: var(--color-warning);
		font-weight: 600;
	}

	.hogar__acciones {
		display: flex;
		gap: 0.4rem;
		flex-wrap: wrap;
		margin-top: 0.7rem;
	}

	.hogar__sintel,
	.hogar__agotado {
		display: flex;
		align-items: center;
		gap: 0.35rem;
		margin: 0.7rem 0 0;
		font-size: 0.8rem;
		color: var(--color-muted);
	}

	.hogar__agotado {
		color: var(--color-warning);
	}

	.pastilla {
		flex: none;
		padding: 0.2rem 0.6rem;
		border-radius: var(--radius-full);
		font-size: 0.75rem;
		font-weight: 600;
		white-space: nowrap;
	}

	.pastilla--ok {
		background: var(--color-success-bg);
		color: var(--aviso-ok-texto);
	}

	.pastilla--espera {
		background: var(--color-warning-bg);
		color: var(--aviso-alerta-texto);
	}

	.pastilla--pendiente {
		background: var(--color-info-bg);
		color: var(--aviso-info-texto);
	}

	.pastilla--problema {
		background: var(--color-danger-bg);
		color: var(--aviso-error-texto);
	}

	.paginacion {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 0.7rem;
		margin-top: 1rem;
		font-size: 0.83rem;
		color: var(--color-muted);
	}

	.pie {
		margin: 1.2rem 0 0;
		padding-top: 0.9rem;
		border-top: 1px solid var(--color-border);
		font-size: 0.79rem;
		line-height: 1.5;
		color: var(--color-muted);
	}

	.visualmente-oculto {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
		white-space: nowrap;
	}
</style>
