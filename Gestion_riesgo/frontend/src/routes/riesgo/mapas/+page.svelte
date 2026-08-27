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
	import {
		Check,
		Crosshair,
		Flame,
		Info,
		Layers,
		LoaderCircle,
		Link2,
		MapPin,
		Maximize2,
		Minimize2,
		Minus,
		Plus,
		RefreshCw,
		TriangleAlert,
		X
	} from '@lucide/svelte';
	import type { Dataset, Hogar } from '$lib/rufe/types';
	import { mapaApi, rufeApi } from '$lib/api/servicios';
	import { ApiError } from '$lib/api/client';
	import {
		CENTRO_JAMUNDI,
		COLOR_ESTADO,
		calorDe,
		colorDe,
		direccionesDe,
		puntosDe,
		puntosDeFichas,
		type FichaMapa,
		type PuntoHogar,
		type Ubicacion
	} from '$lib/mapa/datos';

	let contenedor = $state<HTMLDivElement | null>(null);
	let cargando = $state(true);
	let error = $state<string | null>(null);
	let paso = $state('Leyendo el censo…');

	let datos = $state<Dataset | null>(null);
	let puntos = $state<PuntoHogar[]>([]);
	let sinUbicar = $state<(Hogar | FichaMapa)[]>([]);
	let delSistema = $state(0);
	/** Fuentes que no se pudieron leer, para decirlo en vez de callarlo. */
	let problemas = $state<string[]>([]);

	let verCalor = $state(true);
	let verPredios = $state(true);

	// ── Los controles del mapa ───────────────────────────────────────────────
	//
	// Van sobre el mapa y a la derecha, como en OpenStreetMap. No es una copia
	// por gusto: quien usa esta pantalla en la Alcaldía ya sabe manejar el mapa
	// de OSM, y encontrarse los mismos botones en el mismo sitio ahorra
	// explicaciones. Además libera la fila de filtros de arriba, que mezclaba
	// dos cosas distintas —qué se filtra y cómo se ve.
	//
	// Están escritos en Svelte y no como controles de Leaflet para que compartan
	// los colores y las medidas del resto del sistema, y para que su estado sea
	// el mismo que ya maneja la pantalla.

	/** Qué panel lateral está abierto, si alguno. */
	let panel = $state<'capas' | 'leyenda' | null>(null);

	/** El fondo del mapa. «Estándar» es el de openstreetmap.org. */
	let fondo = $state<'estandar' | 'voyager'>('estandar');

	let pantallaCompleta = $state(false);
	let ubicando = $state(false);
	let avisoUbicacion = $state<string | null>(null);
	let enlaceCopiado = $state(false);

	let capaFondo: any = null;
	let marcaUbicacion: any = null;
	let zona = $state<'todas' | 'Urbana' | 'Rural'>('todas');
	let estado = $state('todos');

	// Leaflet no se puede tipar aquí sin importarlo, y se importa dinámicamente
	// para no cargarlo en las demás pantallas.
	/* eslint-disable @typescript-eslint/no-explicit-any */
	let L: any = null;
	let mapa: any = null;
	let capaCalor: any = null;
	let capaPredios: any = null;
	let observador: ResizeObserver | null = null;

	const visibles = $derived(
		puntos.filter(
			(p) =>
				(zona === 'todas' || p.zona === zona) && (estado === 'todos' || p.estadoBien === estado)
		)
	);

	const personasVisibles = $derived(visibles.reduce((n, p) => n + p.personas, 0));
	const conGps = $derived(visibles.filter((p) => p.ubicadoPor === 'gps').length);
	const porSector = $derived(visibles.filter((p) => p.ubicadoPor === 'sector').length);
	const estados = $derived([...new Set(puntos.map((p) => p.estadoBien))].sort());

	onMount(() => {
		void arrancar();
	});

	onDestroy(() => {
		// Sin desconectar el observador y destruir el mapa, cada visita a esta
		// pantalla dejaría atrás un mapa vivo escuchando eventos.
		observador?.disconnect();
		observador = null;
		mapa?.remove();
		mapa = null;
	});

	async function arrancar() {
		try {
			paso = 'Leyendo el censo y las fichas…';

			// Las dos fuentes van por separado y NINGUNA puede tumbar a la otra.
			// Antes iban en un Promise.all: si la lectura de las hojas de Google
			// fallaba —van por internet, a veces tardan o responden mal— se caía el
			// mapa entero, incluidas las fichas del sistema que sí estaban bien.
			//
			// Y si algo falla se dice cuál: callarlo dejaba un mapa vacío sin
			// ninguna pista de por qué.
			const [resCenso, resFichas] = await Promise.allSettled([
				rufeApi.tablero(),
				mapaApi.fichas()
			]);

			const avisos: string[] = [];

			let hogares: Hogar[] = [];
			if (resCenso.status === 'fulfilled') {
				datos = resCenso.value;
				hogares = resCenso.value.hogares;
			} else {
				avisos.push('No se pudo leer el censo de las hojas de cálculo.');
			}

			let fichas: FichaMapa[] = [];
			if (resFichas.status === 'fulfilled') {
				fichas = resFichas.value.fichas;
			} else {
				avisos.push('No se pudieron leer las fichas registradas en el sistema.');
			}

			problemas = avisos;

			if (hogares.length === 0 && fichas.length === 0 && avisos.length > 0) {
				throw new Error(avisos.join(' '));
			}

			paso = 'Ubicando las direcciones…';
			const direcciones = direccionesDe(hogares, fichas);

			let ubicaciones: Record<string, Ubicacion> = {};
			if (direcciones.length > 0) {
				const respuesta = await mapaApi.ubicaciones(direcciones);
				ubicaciones = respuesta.ubicaciones;
			}

			const delCenso = puntosDe(hogares, ubicaciones);
			const deFichas = puntosDeFichas(fichas, ubicaciones);

			puntos = [...delCenso.puntos, ...deFichas.puntos];
			sinUbicar = [...delCenso.sinUbicar, ...deFichas.sinUbicar];
			delSistema = deFichas.puntos.length;

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

		// Guarda contra una segunda inicialización. Leaflet lanza «Map container is
		// already initialized» y deja el contenedor inservible, así que más vale no
		// llegar ahí.
		if (mapa) return;

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
			scrollWheelZoom: false,
			// Los botones van a la derecha y son los de esta pantalla, no los de
			// Leaflet: así comparten colores y medidas con el resto del sistema.
			zoomControl: false,
			attributionControl: true
		});

		// Se acerca con la rueda solo tras hacer clic: si no, desplazarse por la
		// página con el ratón encima del mapa lo hace saltar de escala sin querer.
		mapa.on('click', () => mapa.scrollWheelZoom.enable());
		mapa.on('mouseout', () => mapa.scrollWheelZoom.disable());

		aplicarFondo();

		// La escala es lo que convierte una mancha en una magnitud: sin ella no se
		// sabe si el foco abarca una manzana o media vereda. El plano impreso la
		// lleva, y quien compare los dos necesita la misma referencia.
		L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(mapa);

		refrescarCapas();

		// Si el enlace trae una vista —la que compartió alguien—, se respeta. Si
		// no, se encuadra sobre lo que haya.
		const compartida = vistaDelEnlace();

		if (compartida) {
			mapa.setView([compartida.lat, compartida.lon], compartida.z);
		} else {
			encuadrar();
		}

		// Leaflet calcula la posición de cada teja y de cada marcador a partir del
		// tamaño que tenía el contenedor al crearse. Si ese tamaño cambia después
		// —al cargar la tipografía, al aparecer un aviso encima, al girar el
		// teléfono— todo queda desplazado respecto al fondo: los puntos aparecen
		// corridos de donde deberían estar.
		//
		// `invalidateSize` le hace recalcular. Se llama tras ceder el hilo, cuando
		// el navegador ya asentó la maquetación.
		requestAnimationFrame(() => mapa?.invalidateSize());

		// Y lo mismo cada vez que el contenedor cambie de tamaño mientras se usa.
		observador = new ResizeObserver(() => mapa?.invalidateSize());
		observador.observe(contenedor);
	}

	/**
	 * Los dos fondos disponibles.
	 *
	 * «Estándar» es exactamente el de openstreetmap.org: la misma vista que la
	 * gente de la Alcaldía ya reconoce, con la jerarquía de vías, el agua en azul
	 * y los nombres de barrio que se usan para ubicarse. Va por omisión.
	 *
	 * «Voyager» de CARTO se conserva como alternativa porque es más sobrio y la
	 * mancha de calor se lee mejor encima cuando hay mucha concentración.
	 *
	 * Ninguno necesita clave ni cuenta. La política de uso de las tejas de OSM
	 * pide identificarse y no descargar en masa: esto es una pantalla interna
	 * con unas pocas decenas de usuarios, que es justo el uso que contempla.
	 */
	const FONDOS = {
		estandar: {
			nombre: 'Estándar',
			pista: 'El mismo mapa de openstreetmap.org',
			url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			maxZoom: 19,
			credito: '&copy; <a href="https://www.openstreetmap.org/copyright">Colaboradores de OpenStreetMap</a>'
		},
		voyager: {
			nombre: 'Sobrio',
			pista: 'Más apagado: la mancha de calor resalta',
			url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
			maxZoom: 20,
			credito:
				'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>'
		}
	} as const;

	function aplicarFondo() {
		if (!mapa || !L) return;

		const elegido = FONDOS[fondo];

		capaFondo?.remove();
		capaFondo = L.tileLayer(elegido.url, {
			maxZoom: elegido.maxZoom,
			attribution: elegido.credito
		}).addTo(mapa);

		// El fondo va debajo de todo: sin esto, cambiar de mapa deja las tejas
		// nuevas encima de la mancha de calor y de los predios.
		capaFondo.bringToBack();
	}

	/**
	 * La vista que venga en el enlace, si trae una completa.
	 *
	 * Se exige que las tres estén y sean números: media coordenada mandaría el
	 * mapa al Golfo de Guinea, que es donde caen las coordenadas a medio leer.
	 */
	function vistaDelEnlace(): { lat: number; lon: number; z: number } | null {
		if (!browser) return null;

		const p = new URLSearchParams(window.location.search);
		const lat = Number(p.get('lat'));
		const lon = Number(p.get('lon'));
		const z = Number(p.get('z'));

		if (!Number.isFinite(lat) || !Number.isFinite(lon) || !Number.isFinite(z)) return null;
		if (!p.get('lat') || !p.get('lon') || !p.get('z')) return null;

		const zonaEnlace = p.get('zona');
		const estadoEnlace = p.get('estado');

		if (zonaEnlace === 'todas' || zonaEnlace === 'Urbana' || zonaEnlace === 'Rural') {
			zona = zonaEnlace;
		}

		if (estadoEnlace) {
			estado = estadoEnlace;
		}

		return { lat, lon, z: Math.min(Math.max(z, 3), 19) };
	}

	function acercar(pasos: number) {
		if (!mapa) return;
		mapa.setZoom(mapa.getZoom() + pasos);
	}

	/**
	 * Centra el mapa donde está quien lo mira.
	 *
	 * Sirve en campo: el funcionario que está parado en una vereda quiere ver
	 * qué hogares tiene alrededor, no buscarlos por nombre. Si el navegador
	 * niega el permiso se dice y no pasa nada más — el mapa sigue sirviendo.
	 */
	function ubicarme() {
		if (!mapa || !browser || !navigator.geolocation) {
			avisoUbicacion = 'Este navegador no permite ubicarle.';

			return;
		}

		ubicando = true;
		avisoUbicacion = null;

		navigator.geolocation.getCurrentPosition(
			(pos) => {
				ubicando = false;
				const punto: [number, number] = [pos.coords.latitude, pos.coords.longitude];
				mapa.setView(punto, 16);

				marcaUbicacion?.remove();
				marcaUbicacion = L.circleMarker(punto, {
					radius: 8,
					color: '#1d6fe0',
					weight: 3,
					fillColor: '#4c9aff',
					fillOpacity: 0.9
				})
					.addTo(mapa)
					.bindPopup('Usted está aquí');
			},
			() => {
				ubicando = false;
				avisoUbicacion = 'No se pudo obtener su ubicación. Revise el permiso del navegador.';
			},
			{ enableHighAccuracy: true, timeout: 10_000 }
		);
	}

	/**
	 * Copia un enlace a ESTA vista: centro, acercamiento y filtros.
	 *
	 * Es lo que convierte el mapa en algo que se puede pasar por teléfono: «mira
	 * este foco» deja de ser una explicación de dos minutos.
	 */
	async function compartirVista() {
		if (!mapa || !browser) return;

		const centro = mapa.getCenter();
		const url = new URL(window.location.href);
		url.searchParams.set('lat', centro.lat.toFixed(5));
		url.searchParams.set('lon', centro.lng.toFixed(5));
		url.searchParams.set('z', String(mapa.getZoom()));
		url.searchParams.set('zona', zona);
		url.searchParams.set('estado', estado);

		try {
			await navigator.clipboard.writeText(url.toString());
			enlaceCopiado = true;
			setTimeout(() => (enlaceCopiado = false), 2500);
		} catch {
			// Sin portapapeles —contexto no seguro, permiso negado— se deja el
			// enlace en la barra de direcciones para que se copie a mano.
			window.history.replaceState({}, '', url);
			avisoUbicacion = 'No se pudo copiar. El enlace quedó en la barra de direcciones.';
		}
	}

	/** Pantalla completa: un mapa de decisión se mira grande. */
	async function alternarPantallaCompleta() {
		if (!contenedor?.parentElement) return;

		try {
			if (document.fullscreenElement) {
				await document.exitFullscreen();
			} else {
				await contenedor.parentElement.requestFullscreen();
			}
		} catch {
			avisoUbicacion = 'Este navegador no permite pantalla completa aquí.';
		}
	}

	function refrescarCapas() {
		if (!mapa || !L) return;

		capaCalor?.remove();
		capaPredios?.remove();

		if (verCalor && visibles.length > 0) {
			capaCalor = (L as any).heatLayer(calorDe(visibles), {
				radius: 26,
				blur: 20,
				maxZoom: 16,
				minOpacity: 0.3,
				// El degradado de fábrica arranca en azul, que sobre este fondo se
				// confunde con el río y con las zonas de agua. Se sustituye por uno
				// que solo recorre los cálidos: así la mancha nunca se puede leer
				// como un accidente geográfico.
				gradient: {
					0.2: '#ffd166',
					0.45: '#f7a440',
					0.7: '#ef6c3a',
					1.0: '#c62d1f'
				}
			}).addTo(mapa);
		}

		if (verPredios) {
			capaPredios = L.layerGroup(
				visibles.map((p) =>
					L.circleMarker([p.lat, p.lon], {
						radius: 7,
						// Anillo blanco grueso: separa un predio de otro cuando se
						// amontonan y despega el punto de un fondo que ahora tiene
						// color propio. Es lo mismo que hacen los alfileres del plano
						// impreso.
						color: '#ffffff',
						weight: 2,
						fillColor: colorDe(p.estadoBien),
						fillOpacity: 1
					}).bindPopup(popup(p))
				)
			).addTo(mapa);
		}
	}

	function popup(p: PuntoHogar): string {
		const escapar = (t: string) =>
			t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

		// Cómo se ubicó se dice siempre. No es lo mismo el GPS que tomó el censador
		// delante de la casa que el centro de una vereda: ambos sirven para ver
		// dónde se concentra la afectación, pero solo el primero sirve para ir a
		// buscar el predio, y quien mire el mapa tiene que poder distinguirlo.
		const comoSeUbico = {
			gps: 'ubicación tomada en campo con GPS',
			direccion: 'ubicado por la dirección escrita',
			sector: 'ubicación aproximada del sector, no del predio'
		}[p.ubicadoPor];

		const fuente =
			p.origen === 'sistema'
				? `Ficha ${escapar(p.hogar)} · registrada en el sistema`
				: 'Censo en papel digitalizado';

		return `<strong>${escapar(p.direccion)}</strong><br>
			${escapar(p.barrio)} · ${escapar(p.zona)}<br>
			${p.personas} ${p.personas === 1 ? 'persona' : 'personas'} · ${escapar(p.estadoBien)}<br>
			<span style="opacity:.7">${fuente} · ${comoSeUbico}</span>`;
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

	// Cambiar de fondo es cambiar UNA capa, no rehacer el mapa: los predios y la
	// mancha de calor se quedan donde están y no parpadean.
	$effect(() => {
		void fondo;
		if (mapa) aplicarFondo();
	});

	// La pantalla completa puede salirse con Escape sin pasar por el botón, así
	// que el estado se lee del navegador y no se supone.
	$effect(() => {
		if (!browser) return;

		const alCambiar = () => (pantallaCompleta = document.fullscreenElement !== null);
		document.addEventListener('fullscreenchange', alCambiar);

		return () => document.removeEventListener('fullscreenchange', alCambiar);
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

	{#each problemas as aviso (aviso)}
		<p class="aviso aviso--alerta" role="status">
			<TriangleAlert size={15} aria-hidden="true" />
			{aviso}
		</p>
	{/each}

	{#if cargando}
		<p class="cargando">
			<LoaderCircle size={18} class="girando" aria-hidden="true" />
			{paso}
		</p>
	{:else}
		<div class="controles">
			<!-- Las capas se movieron ENCIMA del mapa, al botón de capas: aquí
			     mezclaban dos cosas distintas —qué se filtra y cómo se ve— en una
			     sola fila que además se desbordaba en un teléfono. -->
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

	<!--
		El mapa y sus controles.
		El envoltorio existe para dos cosas: colgar de él la columna de botones
		—que va SOBRE el mapa, no al lado— y ser el elemento que entra en pantalla
		completa, para que los controles se vayan con él.
	-->
	<div class="mapa" class:mapa--completa={pantallaCompleta}>
		<div class="lienzo" bind:this={contenedor} role="application" aria-label="Mapa de la afectación"></div>

		{#if !cargando}
			<!--
				A la derecha y en este orden, como en openstreetmap.org: quien maneja
				esta pantalla en la Alcaldía ya sabe usar ese mapa, y encontrarse los
				mismos botones en el mismo sitio ahorra explicaciones.
			-->
			<div class="mando">
				<div class="mando__grupo">
					<button type="button" class="mando__boton" onclick={() => acercar(1)} title="Acercar" aria-label="Acercar">
						<Plus size={18} aria-hidden="true" />
					</button>
					<button type="button" class="mando__boton" onclick={() => acercar(-1)} title="Alejar" aria-label="Alejar">
						<Minus size={18} aria-hidden="true" />
					</button>
				</div>

				<div class="mando__grupo">
					<button
						type="button"
						class="mando__boton"
						onclick={ubicarme}
						title="Centrar donde estoy"
						aria-label="Centrar donde estoy"
					>
						{#if ubicando}
							<LoaderCircle size={17} class="girando" aria-hidden="true" />
						{:else}
							<Crosshair size={17} aria-hidden="true" />
						{/if}
					</button>
				</div>

				<div class="mando__grupo">
					<button
						type="button"
						class="mando__boton"
						class:mando__boton--activo={panel === 'capas'}
						onclick={() => (panel = panel === 'capas' ? null : 'capas')}
						title="Capas y fondo"
						aria-label="Capas y fondo"
						aria-expanded={panel === 'capas'}
					>
						<Layers size={17} aria-hidden="true" />
					</button>

					<button
						type="button"
						class="mando__boton"
						class:mando__boton--activo={panel === 'leyenda'}
						onclick={() => (panel = panel === 'leyenda' ? null : 'leyenda')}
						title="Qué significa cada color"
						aria-label="Qué significa cada color"
						aria-expanded={panel === 'leyenda'}
					>
						<Info size={17} aria-hidden="true" />
					</button>

					<button
						type="button"
						class="mando__boton"
						onclick={compartirVista}
						title="Copiar el enlace a esta vista"
						aria-label="Copiar el enlace a esta vista"
					>
						{#if enlaceCopiado}
							<Check size={17} aria-hidden="true" />
						{:else}
							<Link2 size={17} aria-hidden="true" />
						{/if}
					</button>

					<button
						type="button"
						class="mando__boton"
						onclick={alternarPantallaCompleta}
						title={pantallaCompleta ? 'Salir de pantalla completa' : 'Pantalla completa'}
						aria-label={pantallaCompleta ? 'Salir de pantalla completa' : 'Pantalla completa'}
					>
						{#if pantallaCompleta}
							<Minimize2 size={17} aria-hidden="true" />
						{:else}
							<Maximize2 size={17} aria-hidden="true" />
						{/if}
					</button>
				</div>
			</div>

			{#if panel !== null}
				<div class="panel">
					<div class="panel__cabecera">
						<strong>{panel === 'capas' ? 'Capas y fondo' : 'De dónde salen estos puntos'}</strong>
						<button type="button" class="panel__cerrar" onclick={() => (panel = null)} aria-label="Cerrar">
							<X size={15} aria-hidden="true" />
						</button>
					</div>

					{#if panel === 'capas'}
						<p class="panel__rotulo">Fondo</p>
						{#each Object.entries(FONDOS) as [clave, f] (clave)}
							<label class="panel__opcion">
								<input type="radio" name="fondo" value={clave} bind:group={fondo} />
								<span>
									{f.nombre}
									<small>{f.pista}</small>
								</span>
							</label>
						{/each}

						<p class="panel__rotulo">Datos sobre el mapa</p>
						<label class="panel__opcion">
							<input type="checkbox" bind:checked={verCalor} />
							<span>
								<Flame size={14} aria-hidden="true" /> Zonas de calor
								<small>Dónde se concentra la gente afectada</small>
							</span>
						</label>
						<label class="panel__opcion">
							<input type="checkbox" bind:checked={verPredios} />
							<span>
								<MapPin size={14} aria-hidden="true" /> Predios
								<small>Cada hogar, con el color de cómo quedó</small>
							</span>
						</label>
					{:else}
						<p class="panel__texto">
							Cada punto es un hogar del censo, con el color de cómo quedó el inmueble —la
							leyenda está debajo del mapa—. La mancha de calor no cuenta hogares: muestra
							dónde se concentra la gente afectada.
						</p>

						<p class="panel__rotulo">De dónde sale la ubicación</p>
						<p class="panel__texto">
							De la dirección escrita en el censo y del punto que toma el censador en campo,
							que es el más preciso de los dos.
						</p>

						<p class="panel__nota">
							Los hogares cuya dirección no se pudo ubicar <strong>no están dibujados
							aquí</strong>. Se cuentan debajo del mapa, y esa cifra es tan importante como
							la mancha: un mapa que calla lo que ignora es un mapa que engaña.
						</p>
					{/if}
				</div>
			{/if}

			{#if avisoUbicacion}
				<p class="mapa__aviso" role="status">{avisoUbicacion}</p>
			{/if}
		{/if}
	</div>

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
			{#if delSistema > 0}
				· <strong>{delSistema}</strong>
				{delSistema === 1 ? 'del formulario' : 'del formulario'}
			{/if}
			{#if sinUbicar.length > 0}
				· <strong>{sinUbicar.length}</strong> sin ubicar
			{/if}
		</p>

		{#if conGps > 0 || porSector > 0}
			<!-- Decir con qué se ubicó cada grupo evita que el mapa se lea como si
			     todos los puntos tuvieran la misma fiabilidad. -->
			<p class="detalle-ubicacion">
				{#if conGps > 0}
					<strong>{conGps}</strong> con GPS tomado en campo.
				{/if}
				{#if porSector > 0}
					<strong>{porSector}</strong> ubicados solo por su sector: el punto señala la vereda o el
					barrio, no el predio.
				{/if}
			</p>
		{/if}

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
		gap: 0.55rem 0.8rem;
		margin-bottom: 0.9rem;
	}

	.campo--linea {
		margin-bottom: 0;
		/* Piden lo justo para su opción más larga y no más: el ancho sobrante es
		   del mapa. */
		flex: 0 1 11rem;
		min-width: 8.5rem;
	}

	/* El botón se separa del grupo de filtros y se va al final de la fila cuando
	   hay sitio, que es donde se espera una acción. */
	.controles > :global(.boton) {
		margin-left: auto;
	}

	/* El envoltorio del mapa: es lo que entra en pantalla completa y de lo que
	   cuelgan los botones, para que se vayan con él. */
	.mapa {
		position: relative;
		margin-bottom: 0.9rem;
	}

	.mapa--completa {
		display: flex;
		flex-direction: column;
		background: var(--color-surface);
	}

	.mapa--completa .lienzo {
		flex: 1;
		height: 100%;
		border-radius: 0;
	}

	.lienzo {
		height: clamp(22rem, 62vh, 40rem);
		border: 1px solid var(--color-border);
		border-radius: 10px;

		/*
		 * Encierra a Leaflet en su propio contexto de apilamiento.
		 *
		 * Leaflet numera sus capas internas de 400 a 800 —tejas, marcadores,
		 * globos, controles— contando con ser lo único de la página. En este
		 * sistema el menú lateral está en 60 y su velo en 55, así que el mapa se
		 * dibujaba por encima de ambos: al abrir el menú, las tejas lo tapaban.
		 *
		 * Aislarlo hace que toda esa numeración se resuelva puertas adentro y que
		 * el mapa entero cuente como un solo elemento frente al resto de la
		 * página. Es preferible a rebajarle los números a Leaflet uno por uno,
		 * que habría que rehacer con cada actualización suya.
		 */
		isolation: isolate;
		position: relative;
		z-index: 0;
		/* Un poco de profundidad separa el mapa —que ahora tiene color propio— de
		   la tarjeta que lo contiene. */
		box-shadow: inset 0 0 0 1px rgb(0 0 0 / 6%);
		/* Leaflet dibuja sus tejas y controles con posición absoluta; sin recorte
		   se salen de la esquina redondeada. */
		overflow: hidden;
		/* Color fijo, no del tema: es lo que se ve mientras cargan las tejas, y
		   debe ser del tono del mapa que viene detrás, no del de la aplicación. */
		background: #eaedf0;
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
		width: 13px;
		height: 13px;
		border-radius: 50%;
		/* El mismo anillo blanco que llevan los puntos del mapa, para que la
		   leyenda se lea como una muestra y no como otra cosa. */
		border: 2px solid #fff;
		box-shadow: 0 0 0 1px rgb(0 0 0 / 18%);
		flex: 0 0 auto;
	}

	.detalle-ubicacion {
		margin: -0.3rem 0 0.6rem;
		font-size: 0.82rem;
		color: var(--color-muted);
	}

	.cobertura {
		margin: 0 0 0.6rem;
		font-size: 0.86rem;
		color: var(--color-text);
	}


	/* ── El mando del mapa ───────────────────────────────────────────────────
	   A la derecha y sobre el mapa, como en openstreetmap.org. Va por encima de
	   las tejas —que Leaflet numera hasta 800— sin salirse del contexto de
	   apilamiento que aísla al mapa del resto de la página. */
	.mando {
		position: absolute;
		top: 0.7rem;
		right: 0.7rem;
		z-index: 900;
		display: grid;
		gap: 0.5rem;
		justify-items: center;
	}

	.mando__grupo {
		display: grid;
		border-radius: 10px;
		overflow: hidden;
		background: rgb(255 255 255 / 94%);
		border: 1px solid rgb(0 0 0 / 14%);
		/* La sombra es lo que despega los botones del mapa: sin ella se confunden
		   con un rótulo del propio plano. */
		box-shadow: 0 2px 8px rgb(0 0 0 / 18%);
		backdrop-filter: blur(3px);
	}

	.mando__boton {
		display: grid;
		place-items: center;
		width: 2.15rem;
		height: 2.15rem;
		border: 0;
		background: none;
		/* Color fijo y no del tema: estos botones viven sobre el mapa, que siempre
		   es claro, aunque el sistema esté en oscuro. */
		color: #26313d;
		cursor: pointer;
		transition: background 120ms ease;
	}

	.mando__boton + .mando__boton {
		border-top: 1px solid rgb(0 0 0 / 10%);
	}

	.mando__boton:hover {
		background: rgb(0 0 0 / 7%);
	}

	.mando__boton:focus-visible {
		outline: 2px solid var(--color-primary);
		outline-offset: -2px;
	}

	.mando__boton--activo {
		background: var(--color-primary);
		color: #fff;
	}

	/* ── El panel que abren «capas» y «leyenda» ──────────────────────────────
	   Debajo del mando y no encima del mapa entero: tapar el mapa para explicar
	   el mapa es justo lo que no se puede hacer. */
	.panel {
		position: absolute;
		top: 0.7rem;
		right: 3.4rem;
		z-index: 900;
		width: min(17rem, calc(100% - 4.5rem));
		max-height: calc(100% - 1.4rem);
		overflow-y: auto;
		padding: 0.7rem 0.85rem 0.85rem;
		border-radius: 10px;
		border: 1px solid rgb(0 0 0 / 14%);
		background: rgb(255 255 255 / 97%);
		box-shadow: 0 4px 14px rgb(0 0 0 / 20%);
		color: #26313d;
		font-size: 0.84rem;
		line-height: 1.4;
	}

	.panel__cabecera {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.5rem;
		margin-bottom: 0.5rem;
	}

	.panel__cerrar {
		display: grid;
		place-items: center;
		width: 1.5rem;
		height: 1.5rem;
		border: 0;
		border-radius: 6px;
		background: none;
		color: inherit;
		cursor: pointer;
	}

	.panel__cerrar:hover {
		background: rgb(0 0 0 / 8%);
	}

	.panel__rotulo {
		margin: 0.7rem 0 0.35rem;
		font-size: 0.72rem;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: #5c6b7a;
	}

	.panel__rotulo:first-of-type {
		margin-top: 0;
	}

	.panel__opcion {
		display: flex;
		align-items: flex-start;
		gap: 0.5rem;
		padding: 0.3rem 0;
		cursor: pointer;
	}

	.panel__opcion input {
		margin: 0.15rem 0 0;
		accent-color: var(--color-primary);
		width: 0.95rem;
		height: 0.95rem;
		flex: none;
	}

	.panel__opcion small {
		display: block;
		color: #5c6b7a;
		font-size: 0.75rem;
		line-height: 1.35;
	}

	.panel__texto {
		margin: 0 0 0.4rem;
		font-size: 0.8rem;
		line-height: 1.45;
	}

	.panel__nota {
		margin: 0.7rem 0 0;
		padding-top: 0.6rem;
		border-top: 1px solid rgb(0 0 0 / 10%);
		font-size: 0.78rem;
		color: #5c6b7a;
	}

	/* El aviso de ubicación o de portapapeles: abajo a la izquierda, donde no
	   tapa ni la escala ni la atribución. */
	.mapa__aviso {
		position: absolute;
		left: 0.7rem;
		bottom: 2.4rem;
		z-index: 900;
		margin: 0;
		max-width: min(22rem, calc(100% - 1.4rem));
		padding: 0.45rem 0.7rem;
		border-radius: 8px;
		background: rgb(38 49 61 / 92%);
		color: #fff;
		font-size: 0.78rem;
		line-height: 1.4;
	}

	/* Los controles de Leaflet vienen con su propio tema claro; se atenúan para
	   que no griten sobre el tema oscuro del sistema. */
	.lienzo :global(.leaflet-control-attribution) {
		font-size: 0.65rem;
	}

	/* Leaflet dibuja sus globos y controles en claro. Con el tema oscuro del
	   sistema heredaban el color de texto y quedaban blanco sobre blanco. */
	.lienzo :global(.leaflet-popup-content),
	.lienzo :global(.leaflet-control-attribution),
	.lienzo :global(.leaflet-control-scale-line),
	.lienzo :global(.leaflet-control-zoom a) {
		color: #1b2430;
	}

	.lienzo :global(.leaflet-popup-content) {
		font-size: 0.83rem;
		line-height: 1.45;
		margin: 0.7rem 0.85rem;
	}

	.lienzo :global(.leaflet-control-scale-line) {
		border-color: #5c6b7a;
		background: rgb(255 255 255 / 78%);
		font-size: 0.68rem;
	}
</style>
