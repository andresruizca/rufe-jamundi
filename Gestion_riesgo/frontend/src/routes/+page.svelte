<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { LoaderCircle } from '@lucide/svelte';

	// La raíz no tiene contenido propio: el layout ya decide si hay sesión, y
	// aquí solo se manda al tablero (o al login, si el layout lo redirige).
	onMount(() => {
		void goto('/dashboard', { replaceState: true });
	});
</script>

<div class="cargando" style="min-height:60vh">
	<LoaderCircle size={20} class="girando" aria-hidden="true" />
	Abriendo el tablero…
</div>
