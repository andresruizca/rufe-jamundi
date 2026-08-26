<script lang="ts">
	// Ver un PDF sin descargarlo.
	//
	// Antes solo se podía descargar. Quien revisa una bandeja abre muchas para
	// mirar una cosa concreta —si la firma está, si el barrio quedó bien
	// escrito— y acababa con la carpeta de descargas llena de fichas con datos
	// de familias damnificadas, que ahí se quedan hasta que alguien las borre.
	//
	// ── Por qué un `iframe` y no una pestaña nueva ───────────────────────────
	//
	// `window.open()` después de un `await` pierde el gesto del usuario y los
	// navegadores lo bloquean: el botón parece no hacer nada. Y el PDF se genera
	// aquí mismo, así que siempre hay un `await` de por medio.
	//
	// El `iframe` no depende de eso. A cambio, en Safari de iPhone el visor
	// incrustado no es de fiar —enseña la primera página o nada—, así que ahí
	// dentro van también «Abrir en otra pestaña» y «Descargar»: desde un enlace
	// de verdad, que ya no lo bloquea nadie.

	import { Download, ExternalLink, X } from '@lucide/svelte';
	import { esWebKitDeApple } from '$lib/offline/plataforma';

	type Props = {
		/** El PDF ya generado. */
		url: string;
		titulo: string;
		/** Con el que se guarda si deciden descargarlo. */
		nombre: string;
		onCerrar: () => void;
	};

	let { url, titulo, nombre, onCerrar }: Props = $props();

	const enApple = esWebKitDeApple();

	// Con el visor abierto, la rueda del ratón movía la tabla de detrás y al
	// cerrarlo uno aparecía en otro punto del listado sin saber por qué.
	$effect(() => {
		const previo = document.body.style.overflow;
		document.body.style.overflow = 'hidden';

		return () => {
			document.body.style.overflow = previo;
		};
	});
</script>

<div
	class="velo-pdf"
	role="dialog"
	aria-modal="true"
	aria-label={titulo}
	tabindex="-1"
	onkeydown={(e) => {
		if (e.key === 'Escape') onCerrar();
	}}
>
	<div class="visor">
		<div class="visor__barra">
			<h2 class="visor__titulo">{titulo}</h2>

			<div class="visor__acciones">
				<!--
					Enlaces de verdad, no botones con guion: un `download` o un
					`target="_blank"` puestos por código después de un `await` los
					bloquea el navegador.
				-->
				<a class="boton boton--suave" href={url} download={nombre}>
					<Download size={14} aria-hidden="true" />
					Descargar
				</a>

				<a class="boton boton--suave" href={url} target="_blank" rel="noopener noreferrer">
					<ExternalLink size={14} aria-hidden="true" />
					Otra pestaña
				</a>

				<button type="button" class="visor__cerrar" onclick={onCerrar} aria-label="Cerrar">
					<X size={16} aria-hidden="true" />
				</button>
			</div>
		</div>

		{#if enApple}
			<!--
				Solo donde hace falta. Un aviso permanente sobre un visor que en
				Chrome funciona sería ruido; aquí evita que alguien crea que el
				documento salió en blanco.
			-->
			<p class="visor__ojo">
				En iPhone y iPad el visor incrustado no siempre muestra el documento completo. Si lo ve
				cortado, ábralo en otra pestaña.
			</p>
		{/if}

		<!--
			`title` no es opcional: sin él, un lector de pantalla anuncia «marco» y
			no dice de qué documento se trata.
		-->
		<iframe class="visor__marco" src={url} title={titulo}></iframe>
	</div>
</div>

<style>
	.velo-pdf {
		position: fixed;
		inset: 0;
		z-index: 90;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 1rem;
		background: rgb(6 20 40 / 62%);
		backdrop-filter: blur(2px);
	}

	/* Casi toda la pantalla: es un formato oficial de página entera, y en una
	   ventana pequeña habría que desplazarlo para leer cualquier cosa. */
	.visor {
		display: flex;
		flex-direction: column;
		width: min(60rem, 100%);
		height: min(92vh, 100%);
		border-radius: 12px;
		overflow: hidden;
		background: var(--color-surface);
		box-shadow: var(--shadow-lg);
	}

	@supports (height: 92dvh) {
		.visor {
			height: min(92dvh, 100%);
		}
	}

	.visor__barra {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.7rem;
		flex-wrap: wrap;
		padding: 0.7rem 0.85rem;
		border-bottom: 1px solid var(--color-border);
	}

	.visor__titulo {
		margin: 0;
		font-size: 0.95rem;
		font-weight: 600;
		overflow-wrap: anywhere;
	}

	.visor__acciones {
		display: flex;
		align-items: center;
		gap: 0.4rem;
		flex-wrap: wrap;
	}

	.visor__cerrar {
		display: grid;
		place-items: center;
		width: 30px;
		height: 30px;
		border: 0;
		border-radius: 8px;
		background: transparent;
		color: var(--color-muted);
		cursor: pointer;
	}

	.visor__cerrar:hover {
		background: var(--color-surface-alt);
		color: var(--color-text);
	}

	.visor__ojo {
		margin: 0;
		padding: 0.55rem 0.85rem;
		border-bottom: 1px solid var(--color-border);
		background: var(--aviso-alerta-fondo);
		color: var(--aviso-alerta-texto);
		font-size: 0.79rem;
		line-height: 1.45;
	}

	.visor__marco {
		flex: 1;
		width: 100%;
		border: 0;
		background: var(--color-surface-alt);
	}
</style>
