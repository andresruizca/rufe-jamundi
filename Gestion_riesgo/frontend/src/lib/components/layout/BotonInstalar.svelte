<script lang="ts">
	// Instalar el sistema como aplicación del teléfono.
	//
	// No es un adorno. Instalada, Android le concede al sistema garantías de
	// almacenamiento mucho mejores: deja de ser una pestaña más que el navegador
	// puede desalojar cuando le falte espacio, y con ella se irían las fichas
	// levantadas que aún no se han enviado.
	//
	// Además arranca a pantalla completa y directamente en el formulario, que es
	// lo que necesita quien está censando en la calle.
	//
	// El botón solo aparece cuando el navegador ofrece instalar. En iPhone no
	// existe ese evento —se hace desde «Compartir → Añadir a inicio»—, así que
	// ahí no se muestra nada en vez de dar instrucciones que no llevan a ningún
	// sitio.

	import { onDestroy, onMount } from 'svelte';
	import { Download } from '@lucide/svelte';

	type EventoInstalar = Event & {
		prompt: () => Promise<void>;
		userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
	};

	let pendiente: EventoInstalar | null = $state(null);
	let instalando = $state(false);

	let alOfrecer: ((e: Event) => void) | null = null;
	let alInstalar: (() => void) | null = null;

	onMount(() => {
		alOfrecer = (e: Event) => {
			// Sin esto Chrome muestra su propia barra, que en esta aplicación
			// aparece encima del formulario y estorba más de lo que ayuda.
			e.preventDefault();
			pendiente = e as EventoInstalar;
		};

		alInstalar = () => (pendiente = null);

		window.addEventListener('beforeinstallprompt', alOfrecer);
		window.addEventListener('appinstalled', alInstalar);
	});

	onDestroy(() => {
		if (alOfrecer) window.removeEventListener('beforeinstallprompt', alOfrecer);
		if (alInstalar) window.removeEventListener('appinstalled', alInstalar);
	});

	async function instalar() {
		if (!pendiente || instalando) return;

		instalando = true;

		try {
			await pendiente.prompt();
			await pendiente.userChoice;
		} finally {
			// El evento solo sirve una vez, se acepte o no.
			pendiente = null;
			instalando = false;
		}
	}
</script>

{#if pendiente}
	<button type="button" class="boton boton--suave instalar" onclick={instalar} disabled={instalando}>
		<Download size={15} aria-hidden="true" />
		Instalar en este teléfono
	</button>
{/if}

<style>
	.instalar {
		width: 100%;
		justify-content: center;
	}
</style>
