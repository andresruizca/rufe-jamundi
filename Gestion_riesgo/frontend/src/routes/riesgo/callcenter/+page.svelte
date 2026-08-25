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

	import { onMount } from 'svelte';
	import {
		Check,
		CircleDot,
		Clock,
		LoaderCircle,
		PhoneCall,
		PhoneOff,
		Search,
		TriangleAlert,
		Users
	} from '@lucide/svelte';
	import { callCenterApi } from '$lib/api/servicios';
	import KpiTile from '$lib/components/KpiTile.svelte';
	import CompartirPreinscripcion from '$lib/components/CompartirPreinscripcion.svelte';
	import CompartirFormulario from '$lib/components/CompartirFormulario.svelte';
	import {
		PESTANAS,
		estadoDe,
		porcentaje,
		type FiltroEstado,
		type HogarParaLlamar,
		type ResumenCallCenter
	} from '$lib/callcenter/tipos';

	let resumen = $state<ResumenCallCenter | null>(null);
	let hogares = $state<HogarParaLlamar[]>([]);
	let resultados = $state<Record<string, string>>({});
	let total = $state(0);
	let pagina = $state(1);
	let paginas = $state(1);

	let estado = $state<FiltroEstado>('pendiente');
	let busqueda = $state('');
	let cargando = $state(true);
	let error = $state('');

	/** Cuál se está anotando. Solo una a la vez: se llama de a un hogar. */
	let anotando = $state<number | null>(null);
	let formulario = $state({ resultado: '', nota: '', proxima_llamada: '', enlace_enviado: false });
	let guardando = $state(false);
	let errorForm = $state<Record<string, string>>({});

	const hoy = new Date().toISOString().slice(0, 10);

	onMount(() => {
		void cargar();
	});

	async function cargar() {
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

			resumen = r.resumen;
			hogares = l.hogares;
			resultados = l.resultados;
			total = l.paginacion.total;
			paginas = l.paginacion.paginas;
		} catch (e) {
			error = e instanceof Error ? e.message : 'No se pudo cargar la lista de llamadas.';
		} finally {
			cargando = false;
		}
	}

	function cambiarPestana(v: FiltroEstado) {
		estado = v;
		pagina = 1;
		anotando = null;
		void cargar();
	}

	function buscar(e: Event) {
		e.preventDefault();
		pagina = 1;
		void cargar();
	}

	function abrirAnotacion(h: HogarParaLlamar) {
		anotando = anotando === h.id ? null : h.id;
		errorForm = {};
		formulario = { resultado: '', nota: '', proxima_llamada: '', enlace_enviado: false };
	}

	async function anotar(h: HogarParaLlamar) {
		guardando = true;
		errorForm = {};

		try {
			await callCenterApi.registrar(h.id, formulario);
			anotando = null;
			await cargar();
		} catch (e) {
			const err = e as { errors?: Record<string, string>; message?: string };
			errorForm = err.errors ?? {};
			if (Object.keys(errorForm).length === 0) {
				errorForm = { resultado: err.message ?? 'No se pudo guardar.' };
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
			<KpiTile
				label="Ya se preinscribieron"
				value={resumen.preinscritos}
				color="var(--color-success)"
				icon={Check}
				sub="{porcentaje(resumen.preinscritos, resumen.total)} del censo"
			/>
			<KpiTile
				label="Faltan por llamar"
				value={resumen.sin_llamar}
				color="var(--color-highlight-dark)"
				icon={PhoneCall}
				sub="Nadie los ha contactado"
			/>
			<KpiTile
				label="Llamados, sin registrarse"
				value={resumen.contactados_sin_preinscribir}
				color="var(--color-accent)"
				icon={CircleDot}
				sub="Se les explicó y aún no lo hacen"
			/>
			<KpiTile
				label="Volver a llamar hoy"
				value={resumen.para_hoy}
				color="var(--color-secondary-dark)"
				icon={Clock}
				sub="Quedaron para hoy o antes"
			/>
			<KpiTile
				label="Sin teléfono"
				value={resumen.sin_telefono}
				color="var(--color-muted)"
				icon={PhoneOff}
				sub="Por teléfono no se les llega"
			/>
		</div>
	{/if}
</div>

<div class="tarjeta" style="margin-top:1.25rem">
	<h2 class="tarjeta__titulo">A quién llamar</h2>

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
		<input
			id="cc-buscar"
			class="campo__control"
			type="search"
			placeholder="Nombre, teléfono o radicado"
			bind:value={busqueda}
		/>
		<button type="submit" class="boton boton--suave">
			<Search size={15} aria-hidden="true" />
			Buscar
		</button>
	</form>

	{#if error}
		<p class="aviso aviso--error" role="alert">
			<TriangleAlert size={15} aria-hidden="true" />
			{error}
		</p>
	{/if}

	{#if cargando}
		<p class="cargando">
			<LoaderCircle size={18} class="girando" aria-hidden="true" />
			Cargando…
		</p>
	{:else if hogares.length === 0}
		<p class="vacio">
			<Check size={24} aria-hidden="true" />
			<span>
				{estado === 'pendiente'
					? 'No queda nadie por llamar en esta lista.'
					: 'No hay hogares en esta lista.'}
			</span>
		</p>
	{:else}
		<p class="conteo">{total} {total === 1 ? 'hogar' : 'hogares'}</p>

		<ul class="hogares">
			{#each hogares as h (h.id)}
				{@const st = estadoDe(h)}
				<li class="hogar">
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

					{#if h.telefono}
						<div class="hogar__acciones">
							<!-- `tel:` abre el marcador del teléfono o el softphone del
							     equipo. Es la acción principal: esto es un call center. -->
							<a class="boton boton--principal" href="tel:{h.telefono}">
								<PhoneCall size={15} aria-hidden="true" />
								Llamar al {h.telefono}
							</a>
							<button type="button" class="boton boton--suave" onclick={() => abrirAnotacion(h)}>
								{anotando === h.id ? 'Cerrar' : 'Anotar la llamada'}
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

					{#if anotando === h.id}
						<div class="anotar">
							<!--
								Mandarle el enlace es parte de la llamada, así que va dentro
								de ella: se le explica por teléfono y se le manda mientras
								sigue al aparato. El componente es el mismo de siempre, con
								el teléfono de ESTE hogar.
							-->
							{#if h.telefono}
								<CompartirPreinscripcion
									nombre={h.nombre ?? ''}
									telefono={h.telefono}
									titulo="Mandarle el enlace ahora"
								/>
							{/if}

							<h4 class="anotar__titulo">¿Cómo terminó la llamada?</h4>

							<div class="anotar__opciones">
								{#each Object.entries(resultados) as [valor, etiqueta] (valor)}
									<label class="opcion" class:opcion--activa={formulario.resultado === valor}>
										<input type="radio" bind:group={formulario.resultado} value={valor} />
										<span>{etiqueta}</span>
									</label>
								{/each}
							</div>

							{#if errorForm.resultado}
								<p class="anotar__error">{errorForm.resultado}</p>
							{/if}

							<label class="campo">
								<span class="campo__etiqueta">Cuándo volver a llamar</span>
								<input
									class="campo__control"
									type="date"
									min={hoy}
									bind:value={formulario.proxima_llamada}
								/>
								{#if errorForm.proxima_llamada}
									<span class="anotar__error">{errorForm.proxima_llamada}</span>
								{/if}
							</label>

							<label class="campo">
								<span class="campo__etiqueta">Nota</span>
								<input
									class="campo__control"
									maxlength="500"
									placeholder="Ej.: pidió que se le llame después de las 5"
									bind:value={formulario.nota}
								/>
							</label>

							<label class="opcion opcion--suelta">
								<input type="checkbox" bind:checked={formulario.enlace_enviado} />
								<span>Le mandé el enlace</span>
							</label>

							<div class="anotar__acciones">
								<button
									type="button"
									class="boton boton--principal"
									onclick={() => anotar(h)}
									disabled={guardando || formulario.resultado === ''}
								>
									{guardando ? 'Guardando…' : 'Guardar la llamada'}
								</button>
								<button type="button" class="boton boton--suave" onclick={() => (anotando = null)}>
									Cancelar
								</button>
							</div>
						</div>
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
		<strong>Anotar una llamada no cambia el estado de la ficha del censo.</strong> Son dos procesos
		distintos.
		<br />
		«Ya se preinscribió» se detecta solo, cruzando la cédula: si la persona diligencia el
		formulario después de colgar, aparecerá en la siguiente carga sin que usted marque nada.
	</p>
</div>

<style>
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


	.kpi-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
		gap: 0.7rem;
		margin-top: 1rem;
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

	.anotar {
		margin-top: 0.85rem;
		padding-top: 0.85rem;
		border-top: 1px solid var(--color-border);
		display: grid;
		gap: 0.6rem;
	}

	.anotar__titulo {
		margin: 0.3rem 0 0;
		font-size: 0.88rem;
	}

	.anotar__opciones {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
		gap: 0.35rem;
	}

	.opcion--suelta {
		max-width: 16rem;
	}

	.anotar__error {
		margin: 0;
		font-size: 0.79rem;
		color: var(--color-danger);
	}

	.anotar__acciones {
		display: flex;
		gap: 0.4rem;
		flex-wrap: wrap;
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
