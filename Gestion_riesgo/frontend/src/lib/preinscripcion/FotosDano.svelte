<script lang="ts">
	// Las fotos del daño, con la cámara de la página y el mínimo a la vista.
	//
	// ── Por qué la cámara propia y no la del sistema ─────────────────────────
	//
	// Los ingenieros pidieron estas fotos apaisadas por lo mismo que los videos:
	// de pie cabe una franja de la fachada, acostado cabe la fachada entera y se
	// ve dónde empieza y dónde termina una grieta. Sobre la cámara del sistema
	// no se puede dibujar el aviso de girar el teléfono —es otra aplicación—, ni
	// se puede poner la pantalla apaisada sola. Con `getUserMedia` sí.
	//
	// Y se queda abierta entre disparo y disparo. Se piden cinco como mínimo, y
	// obligar a salir, volver a entrar y esperar el permiso de la cámara entre
	// cada una es exactamente lo que hace que la gente mande tres.
	//
	// ── Por qué el contador ──────────────────────────────────────────────────
	//
	// El mínimo se comprueba al pasar de paso (`pasos.ts`), pero enterarse de
	// que faltan dos fotos cuando ya se pulsó «Siguiente» es enterarse tarde:
	// puede que para entonces la persona ya no esté delante del muro. Aquí se ve
	// cuántas lleva mientras las está tomando.

	import { Camera, CheckCircle2 } from '@lucide/svelte';
	import CamaraFoto from '$lib/camara/CamaraFoto.svelte';
	import { pedirApaisado } from '$lib/camara/orientacion.svelte';
	import SubidaEvidencias from '$lib/rufe-form/componentes/SubidaEvidencias.svelte';
	import type { GestorEvidencias } from '$lib/rufe-form/evidencias.svelte';
	import { MIN_FOTOS_DANO, fotosUtiles } from './pasos';

	let { gestor }: { gestor: GestorEvidencias } = $props();

	let capturando = $state(false);

	const cuantas = $derived(fotosUtiles(gestor.archivosDe('PRE_DANO')));
	const faltan = $derived(Math.max(0, MIN_FOTOS_DANO - cuantas));

	/**
	 * Abrir la cámara, y de paso poner la pantalla apaisada.
	 *
	 * El giro se pide AQUÍ y no dentro de la cámara: pantalla completa solo se
	 * concede mientras dure la activación que deja este toque, y dentro de la
	 * cámara ya se ha esperado al permiso del aparato. Es una comodidad, así que
	 * no se espera el resultado ni se comprueba: si falla, la cámara se abre
	 * igual y queda el aviso de girar.
	 */
	function abrir() {
		void pedirApaisado();
		capturando = true;
	}

	async function alTomar(archivo: File) {
		await gestor.agregar([archivo], 'PRE_DANO');
	}
</script>

<SubidaEvidencias
	{gestor}
	tipo="PRE_DANO"
	titulo="Fotos del daño"
	ayuda="Al menos cinco: la fachada, cada muro afectado, el techo y el piso. Entre más se vea, mejor se prepara la visita — puede tomar hasta diez. Se reducen en su celular antes de enviarse, así que gastan pocos datos."
	textoCamara="Tomar fotos del daño"
	abrirCamara={abrir}
/>

<p class="cuenta" class:cuenta--lista={faltan === 0} role="status" aria-live="polite">
	{#if faltan === 0}
		<CheckCircle2 size={15} aria-hidden="true" />
		Lleva {cuantas} {cuantas === 1 ? 'foto' : 'fotos'}. Ya puede continuar, y entre más tome, mejor.
	{:else}
		<Camera size={15} aria-hidden="true" />
		Lleva {cuantas} de {MIN_FOTOS_DANO}. {faltan === 1
			? 'Falta una foto para poder continuar.'
			: `Faltan ${faltan} fotos para poder continuar.`}
	{/if}
</p>

{#if capturando}
	<CamaraFoto
		titulo="Fotos del daño"
		ayuda="Acerque la cámara al daño y procure que se vea entero, con algo alrededor para saber dónde está."
		textoGiro="Gire el teléfono: acostado cabe el muro entero en la foto"
		nombreBase="dano"
		varias={true}
		alTomar={alTomar}
		alCerrar={() => (capturando = false)}
	/>
{/if}

<style>
	.cuenta {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 0;
		padding: 0.55rem 0.75rem;
		border-radius: 10px;
		font-size: 0.85rem;
		line-height: 1.45;
		background: var(--color-warning-bg);
		color: var(--color-warning);
	}

	.cuenta--lista {
		background: var(--color-success-bg);
		color: var(--color-success);
	}

	.cuenta :global(svg) {
		flex: none;
	}
</style>
