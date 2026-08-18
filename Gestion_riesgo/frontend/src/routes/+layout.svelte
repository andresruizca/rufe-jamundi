<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { browser } from '$app/environment';
	import { Menu, LoaderCircle } from '@lucide/svelte';
	import '$lib/theme.css';
	import '$lib/shell.css';
	import MenuLateral from '$lib/components/layout/MenuLateral.svelte';
	import BotonArriba from '$lib/components/layout/BotonArriba.svelte';
	import escudo from '$lib/assets/logo-jamundi.svg';
	import { resolverTitulo, puedeAcceder, esRutaPublica } from '$lib/navigation';
	import { sesion } from '$lib/stores/sesion.svelte';

	let { children } = $props();

	const CLAVE_MENU = 'sgr_menu_abierto';

	let menuAbierto = $state(false);

	const ruta = $derived(page.url.pathname);
	const esLogin = $derived(ruta === '/login');

	// El login y el formulario ciudadano se sirven sin sesión y sin armazón. La
	// lista vive en $lib/navigation para que sumar una ruta pública sea una
	// decisión visible en un solo archivo y no un `if` escondido aquí.
	const esPublica = $derived(esRutaPublica(ruta));

	const titulo = $derived(resolverTitulo(ruta));

	/** Pantalla estrecha: ahí el menú nunca se abre solo al entrar. */
	function esEstrecha(): boolean {
		return browser && window.matchMedia('(max-width: 1100px)').matches;
	}

	onMount(() => {
		void sesion.restaurar();

		// El estado del menú se recuerda entre visitas, pero nunca se abre solo
		// en pantallas estrechas: ahí tapa todo el contenido.
		if (!esEstrecha() && window.localStorage.getItem(CLAVE_MENU) === '1') {
			menuAbierto = true;
		}
	});

	function alternarMenu() {
		menuAbierto = !menuAbierto;
		if (browser && !esEstrecha()) {
			window.localStorage.setItem(CLAVE_MENU, menuAbierto ? '1' : '0');
		}
	}

	function cerrarMenu() {
		menuAbierto = false;
		if (browser && !esEstrecha()) window.localStorage.setItem(CLAVE_MENU, '0');
	}

	function alNavegar() {
		// Se cierra siempre, no solo en pantallas estrechas. El menú es un panel
		// superpuesto en cualquier tamaño —con su velo oscuro encima del
		// contenido—, así que dejarlo abierto tras elegir una sección obligaba a
		// hacer un clic más, en cualquier sitio, para poder ver la página que ya
		// se había cargado detrás.
		cerrarMenu();

		// El foco no puede quedarse en un enlace del panel que acaba de ocultarse:
		// queda dentro de un contenedor `aria-hidden` y quien navega con teclado se
		// queda sin punto de partida. Se lleva al principio de la página nueva.
		document.getElementById('inicio-de-pagina')?.focus();
	}

	// Guardia de navegación. Depende de la ruta y de la sesión, así que también
	// protege al entrar directo por URL, no solo al hacer clic en el menú.
	$effect(() => {
		if (sesion.cargando) return;

		// Quien ya tiene sesión no debe quedarse en el login. Va antes que la
		// excepción pública porque /login también está en esa lista.
		if (sesion.autenticado && esLogin) {
			void goto('/dashboard', { replaceState: true });

			return;
		}

		// El resto de rutas públicas no se redirige nunca: un ciudadano sin cuenta
		// no debe acabar en el login, y un funcionario con sesión abierta debe
		// poder abrir el formulario ciudadano para revisarlo.
		if (esPublica) return;

		if (!sesion.autenticado) {
			void goto('/login', { replaceState: true });

			return;
		}

		if (!puedeAcceder(ruta, sesion.rol)) {
			void goto('/dashboard', { replaceState: true });
		}
	});

	async function salir() {
		await sesion.cerrar();
		void goto('/login', { replaceState: true });
	}

	function alPulsarTecla(evento: KeyboardEvent) {
		if (evento.key === 'Escape' && menuAbierto) cerrarMenu();
	}
</script>

<svelte:head>
	<!-- El formulario ciudadano pone su propio título: no se le antepone "SGR
	     Jamundí" porque quien lo abre no es usuario del sistema y ese nombre no
	     le dice nada. -->
	{#if !esPublica || esLogin}
		<title>{esLogin ? 'Iniciar sesión' : titulo} · SGR Jamundí</title>
	{/if}
</svelte:head>

<svelte:window onkeydown={alPulsarTecla} />

{#if sesion.cargando}
	<div class="cargando" style="min-height:100vh">
		<LoaderCircle size={20} class="girando" aria-hidden="true" />
		Cargando el sistema…
	</div>
{:else if esPublica}
	{@render children?.()}
{:else if !sesion.autenticado}
	<!--
		Sin sesión NO se renderiza el contenido de la página, ni siquiera un
		instante. La redirección al login la hace `goto` de forma asíncrona, así
		que si aquí se pintaran los children, cualquiera que abriera /dashboard
		vería el tablero con datos reales del RUFE durante esa ventana — y si la
		navegación se demora o falla, indefinidamente. Fue exactamente lo que
		ocurrió en producción el 15 de agosto de 2026.
	-->
	<div class="cargando" style="min-height:100vh">
		<LoaderCircle size={20} class="girando" aria-hidden="true" />
		Redirigiendo al inicio de sesión…
	</div>
{:else}
	<div class="app">
		<MenuLateral
			rutaActual={ruta}
			abierto={menuAbierto}
			onNavegar={alNavegar}
			onCerrar={cerrarMenu}
			onSalir={salir}
		/>

		{#if menuAbierto}
			<button class="velo" aria-label="Cerrar el menú" onclick={cerrarMenu}></button>
		{/if}

		<div class="contenido">
			<!-- `tabindex="-1"` no lo mete en el orden de tabulación: solo permite
			     que «Volver arriba» pueda poner aquí el foco. -->
			<header class="barra" id="inicio-de-pagina" tabindex="-1">
				<button
					class="barra__menu-btn"
					type="button"
					aria-label={menuAbierto ? 'Cerrar el menú de navegación' : 'Abrir el menú de navegación'}
					aria-expanded={menuAbierto}
					onclick={alternarMenu}
				>
					<Menu size={20} aria-hidden="true" />
				</button>

				<!-- Decorativo: el nombre de la entidad va escrito al lado, así que
				     un texto alternativo lo haría anunciarse dos veces. -->
				<img class="barra__escudo" src={escudo} alt="" aria-hidden="true" />

				<nav class="miga" aria-label="Ubicación">
					<span class="miga__raiz">SGR Jamundí</span>
					<span class="miga__sep" aria-hidden="true">/</span>
					<span class="miga__actual">{titulo}</span>
				</nav>
			</header>

			<main class="pagina" class:pagina--sin-relleno={ruta === '/dashboard'}>
				{@render children?.()}
			</main>

			<!-- Con el menú abierto no se muestra: quedaría flotando sobre el velo y
			     sería alcanzable con el tabulador por detrás del panel. -->
			{#if !menuAbierto}
				<BotonArriba />
			{/if}
		</div>
	</div>
{/if}
