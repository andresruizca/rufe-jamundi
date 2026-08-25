<script lang="ts">
	// «Envíele el enlace a esta persona».
	//
	// El caso real: el profesional termina de inspeccionar una casa y el vecino
	// se acerca a preguntar por la suya. Hasta ahora la respuesta era «vaya a la
	// Alcaldía» o dictarle una dirección web de memoria, de pie y en la calle.
	//
	// Va atado a UN número de teléfono a propósito, no al menú de compartir del
	// sistema. Ese menú pregunta «¿a quién?» después, con la lista entera de
	// contactos: cuatro toques y la posibilidad de mandárselo a quien no era. Con
	// el número ya escrito en la ficha, WhatsApp abre directo en esa
	// conversación.

	import { page } from '$app/state';
	import { Check, Copy, MessageCircle, Share2 } from '@lucide/svelte';
	import { aNumeroDeWhatsapp, enlaceDePreinscripcion, mensajePara } from '$lib/compartir';

	type Props = {
		/** A quién se le manda. Solo para saludarle por su nombre. */
		nombre?: string;
		/** Su teléfono, tal como esté escrito en la ficha. */
		telefono?: string;
		/** Texto de encima, para adaptarlo al sitio donde se ponga. */
		titulo?: string;
	};

	let { nombre = '', telefono = '', titulo = 'Enviarle el enlace para registrarse' }: Props =
		$props();

	let copiado = $state(false);
	let editando = $state(false);
	let aMano = $state('');

	const enlace = $derived(enlaceDePreinscripcion(page.url.origin));

	// El de la ficha manda; si no sirve —o no hay— se usa el que se escriba aquí.
	const numero = $derived(aNumeroDeWhatsapp(aMano || telefono));
	const texto = $derived(mensajePara(nombre, enlace));

	const aQuien = $derived(nombre.trim() || 'esta persona');

	const urlWhatsapp = $derived(
		numero ? `https://wa.me/${numero}?text=${encodeURIComponent(texto)}` : null
	);

	async function copiar() {
		try {
			await navigator.clipboard.writeText(`${texto}`);
			copiado = true;
			setTimeout(() => (copiado = false), 2500);
		} catch {
			// Sin permiso de portapapeles queda el menú del sistema y el enlace a la
			// vista, que se puede seleccionar a mano.
		}
	}

	async function compartir() {
		try {
			await navigator.share({ title: 'Registro de vivienda afectada', text: texto });
		} catch {
			// Cancelar el menú del sistema lanza; no es un error que contar.
		}
	}
</script>

<section class="compartir">
	<h3 class="compartir__titulo">
		<Share2 size={15} aria-hidden="true" />
		{titulo}
	</h3>

	<p class="compartir__nota">
		Se le manda el formulario ciudadano para que registre su vivienda por su cuenta, con fotos
		del daño. Al terminar recibe su número de radicado.
	</p>

	{#if urlWhatsapp}
		<!--
			Un enlace y no un botón con `window.open`: los navegadores bloquean las
			ventanas que abre un guion, y en un teléfono eso se ve como que el botón
			no hace nada. `target="_blank"` sobre un enlace real siempre pasa.
		-->
		<a
			class="boton boton--principal compartir__wa"
			href={urlWhatsapp}
			target="_blank"
			rel="noopener noreferrer"
		>
			<MessageCircle size={16} aria-hidden="true" />
			Enviar por WhatsApp a {aQuien}
		</a>
		<p class="compartir__destino">
			Se abre la conversación con el <strong>{aMano || telefono}</strong>, con el mensaje ya
			escrito. Usted decide si lo envía.
		</p>
	{:else}
		<!--
			Sin número utilizable. Se dice por qué —un fijo no recibe WhatsApp— en vez
			de esconder el botón sin explicación, y se ofrece escribir otro: casi
			siempre el vecino que pregunta no es el propietario de la ficha.
		-->
		<p class="compartir__sinnumero">
			{#if telefono.trim() !== ''}
				El teléfono de la ficha no recibe WhatsApp. Escriba un celular:
			{:else}
				Escriba el celular de la persona:
			{/if}
		</p>
		<div class="compartir__numero">
			<label class="visualmente-oculto" for="compartir-celular">Celular</label>
			<input
				id="compartir-celular"
				class="campo__control"
				type="tel"
				inputmode="tel"
				placeholder="Ej.: 315 772 9890"
				bind:value={aMano}
				onfocus={() => (editando = true)}
			/>
		</div>
		{#if editando && aMano.replace(/\D/g, '').length >= 10 && !numero}
			<p class="compartir__aviso">Reviselo: un celular colombiano son 10 dígitos y empieza por 3.</p>
		{/if}
	{/if}

	<div class="compartir__otras">
		<button type="button" class="boton boton--suave" onclick={copiar}>
			{#if copiado}
				<Check size={14} aria-hidden="true" />
				Copiado
			{:else}
				<Copy size={14} aria-hidden="true" />
				Copiar el mensaje
			{/if}
		</button>

		<!--
			El menú del sistema, para mandarlo por donde no se previó: correo,
			Telegram, o el propio WhatsApp cuando la persona no dio su número. Solo
			donde existe — en un computador de escritorio no suele estar.
		-->
		{#if typeof navigator !== 'undefined' && 'share' in navigator}
			<button type="button" class="boton boton--suave" onclick={compartir}>
				<Share2 size={14} aria-hidden="true" />
				Compartir por otro medio
			</button>
		{/if}
	</div>

	<p class="compartir__ojo">
		La persona necesita internet para abrir el formulario. Sin señal, tome usted la ficha aquí
		mismo.
	</p>
</section>

<style>
	.compartir {
		margin-top: 1.1rem;
		padding: 0.9rem;
		border: 1px solid var(--color-border);
		border-radius: 12px;
		background: var(--color-surface-alt);
		text-align: left;
	}

	.compartir__titulo {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		margin: 0 0 0.35rem;
		font-size: 0.92rem;
		font-weight: 600;
		color: var(--color-text);
	}

	.compartir__nota,
	.compartir__destino,
	.compartir__ojo,
	.compartir__sinnumero {
		margin: 0;
		font-size: 0.81rem;
		line-height: 1.45;
		color: var(--color-muted);
	}

	.compartir__wa {
		width: 100%;
		justify-content: center;
		margin: 0.7rem 0 0.4rem;
	}

	.compartir__destino strong {
		color: var(--color-text);
		white-space: nowrap;
	}

	.compartir__sinnumero {
		margin-top: 0.7rem;
	}

	.compartir__numero {
		margin: 0.4rem 0;
	}

	.compartir__aviso {
		margin: 0 0 0.4rem;
		font-size: 0.79rem;
		color: var(--color-warning);
	}

	.compartir__otras {
		display: flex;
		gap: 0.4rem;
		flex-wrap: wrap;
		margin-top: 0.6rem;
	}

	.compartir__ojo {
		margin-top: 0.7rem;
		padding-top: 0.6rem;
		border-top: 1px solid var(--color-border);
		font-size: 0.77rem;
	}

	.visualmente-oculto {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
		white-space: nowrap;
	}
</style>
