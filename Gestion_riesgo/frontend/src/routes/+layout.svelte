<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { browser } from '$app/environment';
	import { Menu, LoaderCircle } from '@lucide/svelte';
	import '$lib/theme.css';
	import '$lib/shell.css';
	import MenuLateral from '$lib/components/layout/MenuLateral.svelte';
	import { resolverTitulo, puedeAcceder } from '$lib/navigation';
	import { sesion } from '$lib/stores/sesion.svelte';

	let { children } = $props();

	const CLAVE_MENU = 'sgr_menu_abierto';

	let menuAbierto = $state(false);

	const ruta = $derived(page.url.pathname);
	const esLogin = $derived(ruta === '/login');
	const titulo = $derived(resolverTitulo(ruta));

	/** Pantalla estrecha: el menú se cierra al navegar para no tapar el contenido. */
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
		if (esEstrecha()) menuAbierto = false;
	}

	// Guardia de navegación. Depende de la ruta y de la sesión, así que también
	// protege al entrar directo por URL, no solo al hacer clic en el menú.
	$effect(() => {
		if (sesion.cargando) return;

		if (!sesion.autenticado && !esLogin) {
			void goto('/login', { replaceState: true });

			return;
		}

		if (sesion.autenticado && esLogin) {
			void goto('/dashboard', { replaceState: true });

			return;
		}

		if (sesion.autenticado && !esLogin && !puedeAcceder(ruta, sesion.rol)) {
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
	<title>{esLogin ? 'Iniciar sesión' : titulo} · SGR Jamundí</title>
</svelte:head>

<svelte:window onkeydown={alPulsarTecla} />

{#if sesion.cargando}
	<div class="cargando" style="min-height:100vh">
		<LoaderCircle size={20} class="girando" aria-hidden="true" />
		Cargando el sistema…
	</div>
{:else if esLogin || !sesion.autenticado}
	{@render children?.()}
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
			<header class="barra">
				<button
					class="barra__menu-btn"
					type="button"
					aria-label={menuAbierto ? 'Cerrar el menú de navegación' : 'Abrir el menú de navegación'}
					aria-expanded={menuAbierto}
					onclick={alternarMenu}
				>
					<Menu size={20} aria-hidden="true" />
				</button>

				<nav class="miga" aria-label="Ubicación">
					<span class="miga__raiz">SGR Jamundí</span>
					<span class="miga__sep" aria-hidden="true">/</span>
					<span class="miga__actual">{titulo}</span>
				</nav>
			</header>

			<main class="pagina" class:pagina--sin-relleno={ruta === '/dashboard'}>
				{@render children?.()}
			</main>
		</div>
	</div>
{/if}
