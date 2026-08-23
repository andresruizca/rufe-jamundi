<script lang="ts">
	// Las mismas hojas que la web, copiadas tal cual.
	//
	// Sin esto el formulario se dibuja SIN ESTILOS: las piezas copiadas usan
	// clases globales —`.boton`, `.campo`, `.opcion`, `.tarjeta`— y variables de
	// color que solo existen en estos dos archivos. Fue exactamente lo que pasó:
	// el APK compilaba, arrancaba, y se veía roto.
	import '$formulario/estilos/theme.css';
	import '$formulario/estilos/shell.css';

	// Lo que el APK necesita y la web no: zonas seguras del borde a borde de
	// Android 15, y los detalles que distinguen una aplicación de una página.
	import '$formulario/estilos/apk.css';

	import { onMount } from 'svelte';
	import { escucharParaSincronizar } from '$local/sincronizar';

	let { children } = $props();

	// Se engancha una sola vez, para toda la vida de la aplicación: los avisos de
	// red y de vuelta al frente son los que evitan el cuarto de hora de espera
	// con señal delante.
	onMount(() => {
		void escucharParaSincronizar();
	});
</script>

{@render children()}
