<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { Menu, LoaderCircle } from '@lucide/svelte';
	import '$lib/theme.css';
	import '$lib/shell.css';
	import MenuLateral from '$lib/components/layout/MenuLateral.svelte';
	import { resolverTitulo, puedeAcceder } from '$lib/navigation';
	import { sesion } from '$lib/stores/sesion.svelte';

	let { children } = $props();

	let menuAbierto = $state(false);

	const ruta = $derived(page.url.pathname);
	const esLogin = $derived(ruta === '/login');
	const titulo = $derived(resolverTitulo(ruta));

	onMount(() => {
		void sesion.restaurar();
	});

	// Guardia de navegación. Se ejecuta cuando cambia la ruta o la sesión, así
	// que también protege al entrar directo por URL, no solo al hacer clic.
	$effect(() => {
		if (sesion.cargando) return;

		if (!sesion.autenticado && !esLogin) {
			// `replaceState` evita que el botón "atrás" devuelva a una pantalla
			// que ya no se puede ver.
			void goto('/login', { replaceState: true });

			return;
		}

		if (sesion.autenticado && esLogin) {
			void goto('/dashboard', { replaceState: true });

			return;
		}

		// Ruta existente pero fuera del alcance del rol: se manda al tablero,
		// que cualquier rol puede ver.
		if (sesion.autenticado && !esLogin && !puedeAcceder(ruta, sesion.rol)) {
			void goto('/dashboard', { replaceState: true });
		}
	});

	async function salir() {
		await sesion.cerrar();
		void goto('/login', { replaceState: true });
	}
</script>

<svelte:head>
	<title>{esLogin ? 'Iniciar sesión' : titulo} · SGR Jamundí</title>
</svelte:head>

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
			onNavegar={() => (menuAbierto = false)}
			onSalir={salir}
		/>

		{#if menuAbierto}
			<button class="velo" aria-label="Cerrar el menú" onclick={() => (menuAbierto = false)}
			></button>
		{/if}

		<div class="contenido">
			<header class="barra">
				<button
					class="barra__menu-btn"
					type="button"
					aria-label="Abrir el menú"
					onclick={() => (menuAbierto = true)}
				>
					<Menu size={20} aria-hidden="true" />
				</button>
				<h1 class="barra__titulo">{titulo}</h1>
			</header>

			<main class="pagina" class:pagina--sin-relleno={ruta === '/dashboard'}>
				{@render children?.()}
			</main>
		</div>
	</div>
{/if}
