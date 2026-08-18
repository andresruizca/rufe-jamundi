<script lang="ts">
	// Dónde se concentra la afectación del sismo.
	//
	// Dos capas sobre el mismo mapa: la mancha de calor, para ver de un vistazo
	// qué zonas concentran más gente afectada, y los predios uno a uno con su
	// color según cómo quedó el inmueble.
	//
	// Lo que más condiciona esta pantalla no es el dibujo sino lo que NO se puede
	// dibujar. Las direcciones del censo vienen desordenadas —la nota de los
	// planos que ya imprimió la Alcaldía lo dice— y una parte no se puede ubicar.
	// Por eso el contador de hogares sin ubicar está siempre a la vista: un mapa
	// que calla lo que ignora es un mapa que engaña, y este se usa para decidir a
	// dónde va la ayuda.

	import { onDestroy, onMount } from 'svelte';
	import { browser } from '$app/environment';
	import { LoaderCircle, MapPin, Flame, TriangleAlert, RefreshCw } from '@lucide/svelte';
	import { fetchLiveDataset } from '$lib/rufe/live';
	import type { Dataset, Hogar } from '$lib/rufe/types';
	import { mapaApi } from '$lib/api/servicios';
	import { ApiError } from '$lib/api/client';
	import {
		CENTRO_JAMUNDI,
		COLOR_ESTADO,
		calorDe,
		colorDe,
		direccionesDe,
		puntosDe,
		type PuntoHogar,
		type Ubicacion
	} from '$lib/mapa/datos';

	let contenedor = $state<HTMLDivElement | null>(null);
	let cargando = $state(true);
	let error = $state<string | null>(null);
	let paso = $state('Leyendo el censo…');

	let datos = $state<Dataset | null>(null);
	let puntos = $state<PuntoHogar[]>([]);
	let sinUbicar = $state<Hogar[]>([]);

	let verCalor = $state(true);
	let verPredios = $state(true);
	let zona = $state<'todas' | 'Urbana' | 'Rural'>('todas');
	let estado = $state('todos');

	// Leaflet no se puede tipar aquí sin importarlo, y se importa dinámicamente
	// para no cargarlo en las demás pantallas.
	/* eslint-disable @typescript-eslint/no-explicit-any */
	let L: any = null;
	let mapa: any = null;
	let capaCalor: any = null;
	let capaPredios: any = null;

	const visibles = $derived(
		puntos.filter(
			(p) =>
				(zona === 'todas' || p.zona === zona) && (estado === 'todos' || p.estadoBien === estado)
		)
	);

	const personasVisibles = $derived(visibles.reduce((n, p) => n + p.personas, 0));
	const estados = $derived([...new Set(puntos.map((p) => p.estadoBien))].sort());

	onMount(() => {
		void arrancar();
	});

	onDestroy(() => {
		mapa?.remove();
		mapa = null;
	});

	async function arrancar() {
		try {
			paso = 'Leyendo el censo…';
			const dataset = await fetchLiveDataset();
			datos = dataset;

			paso = 'Ubicando las direcciones…';
			const direcciones = direccionesDe(dataset.hogares);

			let ubicaciones: Record<string, Ubicacion> = {};
			if (direcciones.length > 0) {
				const respuesta = await mapaApi.ubicaciones(direcciones);
				ubicaciones = respuesta.ubicaciones;
			}

			const cruce = puntosDe(dataset.hogares, ubicaciones);
			puntos = cruce.puntos;
			sinUbicar = cruce.sinUbicar;

			paso = 'Dibujando el mapa…';
			await dibujar();
			cargando = false;
		} catch (e) {
			error =
				e instanceof ApiError
					? e.message
					: e instanceof Error
						? e.message
						: 'No se pudo cargar el mapa.';
			cargando = false;
		}
	}

	async function dibujar() {
		if (!browser || !contenedor) return;

		const leaflet = await import('leaflet');
		await import('leaflet/dist/leaflet.css');
		await import('leaflet.heat');
		L = leaflet.default ?? leaflet;

		mapa = L.map(contenedor, {
			center: CENTRO_JAMUNDI,
			zoom: 13,
			// El lienzo aguanta mucho mejor cientos de marcadores en un teléfono
			// modesto que el mismo número de elementos del documento.
			preferCanvas: true,
			scrollWheelZoom: false
		});

		// Se acerca con la rueda solo tras hacer clic: si no, desplazarse por la
		// página con el ratón encima del mapa lo hace saltar de escala sin querer.
		mapa.on('click', () => mapa.scrollWheelZoom.enable());
		mapa.on('mouseout', () => mapa.scrollWheelZoom.disable());

		const oscuro =
			document.documentElement.dataset.theme === 'dark' ||
			(!document.documentElement.dataset.theme &&
				window.matchMedia('(prefers-color-scheme: dark)').matches);

		L.tileLayer(
			oscuro
				? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
				: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
			{
				maxZoom: 19,
				attribution:
					'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>'
			}
		).addTo(mapa);

		refrescarCapas();
		encuadrar();
	}

	function refrescarCapas() {
		if (!mapa || !L) return;

		capaCalor?.remove();
		capaPredios?.remove();

		if (verCalor && visibles.length > 0) {
			capaCalor = (L as any).heatLayer(calorDe(visibles), {
				radius: 22,
				blur: 18,
				maxZoom: 16,
				minOpacity: 0.25
			}).addTo(mapa);
		}

		if (verPredios) {
			capaPredios = L.layerGroup(
				visibles.map((p) =>
					L.circleMarker([p.lat, p.lon], {
						radius: 6,
						color: '#ffffff',
						weight: 1.5,
						fillColor: colorDe(p.estadoBien),
						fillOpacity: 0.92
					}).bindPopup(popup(p))
				)
			).addTo(mapa);
		}
	}

	function popup(p: PuntoHogar): string {
		const escapar = (t: string) =>
			t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

		// La precisión se dice siempre: no es lo mismo un punto sobre la casa que
		// uno sobre la calle o sobre el barrio, y quien mire el mapa debe saberlo.
		const comoSeUbico = {
			EXACTA: 'ubicación exacta',
			CALLE: 'ubicado sobre la vía',
			BARRIO: 'ubicación aproximada del sector'
		}[p.precision as 'EXACTA' | 'CALLE' | 'BARRIO'];

		return `<strong>${escapar(p.direccion)}</strong><br>
			${escapar(p.barrio)} · ${escapar(p.zona)}<br>
			${p.personas} ${p.personas === 1 ? 'persona' : 'personas'} · ${escapar(p.estadoBien)}<br>
			<span style="opacity:.7">${comoSeUbico}</span>`;
	}

	function encuadrar() {
		if (!mapa || !L || visibles.length === 0) return;
		mapa.fitBounds(
			L.latLngBounds(visibles.map((p) => [p.lat, p.lon])),
			{ padding: [30, 30], maxZoom: 16 }
		);
	}

	// Al cambiar un filtro se redibuja; el encuadre no se toca para no marear a
	// quien está mirando una zona concreta.
	$effect(() => {
		void visibles;
		void verCalor;
		void verPredios;
		if (mapa) refrescarCapas();
	});
</script>

<div class="tarjeta">
	<h2 class="tarjeta__titulo">Mapa de la afectación</h2>
	<p class="tarjeta__nota">
		Dónde se concentran los hogares afectados por el sismo del 10 de agosto de 2026. Las
		ubicaciones salen de las direcciones del censo y de la ubicación que toma el censador en
		campo.
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
			{paso}
		</p>
	{:else}
		<div class="controles">
			<label class="interruptor">
				<input type="checkbox" bind:checked={verCalor} />
				<Flame size={15} aria-hidden="true" />
				Zonas de calor
			</label>

			<label class="interruptor">
				<input type="checkbox" bind:checked={verPredios} />
				<MapPin size={15} aria-hidden="true" />
				Predios
			</label>

			<div class="campo campo--linea">
				<label class="campo__etiqueta" for="mapa-zona">Zona</label>
				<select id="mapa-zona" class="campo__control" bind:value={zona}>
					<option value="todas">Urbano y rural</option>
					<option value="Urbana">Urbana</option>
					<option value="Rural">Rural</option>
				</select>
			</div>

			<div class="campo campo--linea">
				<label class="campo__etiqueta" for="mapa-estado">Estado del bien</label>
				<select id="mapa-estado" class="campo__control" bind:value={estado}>
					<option value="todos">Todos</option>
					{#each estados as e (e)}<option value={e}>{e}</option>{/each}
				</select>
			</div>

			<button type="button" class="boton boton--suave" onclick={encuadrar}>
				<RefreshCw size={14} aria-hidden="true" />
				Encuadrar
			</button>
		</div>
	{/if}

	<div class="lienzo" bind:this={contenedor} role="application" aria-label="Mapa de la afectación"></div>

	{#if !cargando}
		<div class="leyenda">
			{#each Object.entries(COLOR_ESTADO) as [nombre, color] (nombre)}
				<span class="leyenda__item">
					<span class="leyenda__punto" style="background:{color}"></span>
					{nombre}
				</span>
			{/each}
		</div>

		<p class="cobertura">
			<strong>{visibles.length}</strong>
			{visibles.length === 1 ? 'predio ubicado' : 'predios ubicados'} ·
			<strong>{personasVisibles}</strong>
			{personasVisibles === 1 ? 'persona' : 'personas'}
			{#if sinUbicar.length > 0}
				· <strong>{sinUbicar.length}</strong> sin ubicar
			{/if}
		</p>

		{#if sinUbicar.length > 0}
			<!-- Callarlo sería lo cómodo y lo peor: quien mire el mapa creería que
			     está viendo la afectación completa. -->
			<p class="aviso aviso--alerta">
				<TriangleAlert size={15} aria-hidden="true" />
				Hay {sinUbicar.length}
				{sinUbicar.length === 1 ? 'hogar que todavía no se puede ubicar' : 'hogares que todavía no se pueden ubicar'}
				en el mapa, casi siempre porque su dirección está incompleta. No aparecen aquí, pero sí
				cuentan en el tablero. Un administrador puede procesarlas desde Administración → Mapas.
			</p>
		{/if}
	{/if}
</div>

<style>
	.controles {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: 0.7rem;
		margin-bottom: 0.8rem;
	}

	.interruptor {
		display: flex;
		align-items: center;
		gap: 0.35rem;
		font-size: 0.88rem;
		font-weight: 500;
		cursor: pointer;
		white-space: nowrap;
	}

	.campo--linea {
		margin-bottom: 0;
		min-width: 9rem;
	}

	.lienzo {
		height: clamp(22rem, 62vh, 40rem);
		border: 1px solid var(--color-border);
		border-radius: 10px;
		/* Leaflet dibuja sus tejas y controles con posición absoluta; sin recorte
		   se salen de la esquina redondeada. */
		overflow: hidden;
		background: var(--color-surface-alt);
	}

	.leyenda {
		display: flex;
		flex-wrap: wrap;
		gap: 0.5rem 1rem;
		margin: 0.7rem 0 0.4rem;
		font-size: 0.8rem;
		color: var(--color-muted);
	}

	.leyenda__item {
		display: flex;
		align-items: center;
		gap: 0.35rem;
	}

	.leyenda__punto {
		width: 11px;
		height: 11px;
		border-radius: 50%;
		border: 1.5px solid #fff;
		flex: 0 0 auto;
	}

	.cobertura {
		margin: 0 0 0.6rem;
		font-size: 0.86rem;
		color: var(--color-text);
	}

	/* Los controles de Leaflet vienen con su propio tema claro; se atenúan para
	   que no griten sobre el tema oscuro del sistema. */
	.lienzo :global(.leaflet-control-attribution) {
		font-size: 0.65rem;
	}
</style>
