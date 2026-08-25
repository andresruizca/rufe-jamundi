<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { LoaderCircle } from '@lucide/svelte';
	import { inicioPara } from '$lib/navigation';
	import { sesion } from '$lib/stores/sesion.svelte';

	// La raíz no tiene contenido propio: el layout ya decide si hay sesión, y
	// aquí solo se manda a donde ese rol pueda entrar.
	//
	// Antes mandaba al tablero escrito a mano, y el tablero está vedado al
	// inspector de vivienda y al operador de call center: los mandaba allí, allí
	// los rechazaban, y vuelta a empezar.
	onMount(() => {
		void goto(inicioPara(sesion.rol), { replaceState: true });
	});
</script>

<div class="cargando" style="min-height:60vh">
	<LoaderCircle size={20} class="girando" aria-hidden="true" />
	Abriendo el tablero…
</div>
