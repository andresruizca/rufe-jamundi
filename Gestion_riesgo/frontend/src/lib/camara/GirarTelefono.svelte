<script lang="ts">
	// «Gire el teléfono»: la animación que se pone encima de la cámara.
	//
	// Las dos cosas que este formulario captura se toman apaisadas, y por
	// motivos distintos:
	//
	//  • La cédula es un rectángulo apaisado. De pie, entra pequeña en el centro
	//    y el texto sale borroso al ampliarlo.
	//  • El video de la vivienda lo pidieron así los ingenieros: apaisado cabe
	//    una fachada entera en el cuadro; de pie, cabe una franja.
	//
	// ── Avisa, no bloquea ────────────────────────────────────────────────────
	//
	// El botón de debajo sigue funcionando. Quien tenga el giro de pantalla
	// bloqueado en su celular —muy común— no puede girar la página aunque gire
	// el aparato, y dejarlo sin poder mandar su solicitud por eso sería mucho
	// peor que una foto de pie.

	import { RotateCw, Smartphone } from '@lucide/svelte';

	let { texto = 'Gire el teléfono para tomar la foto' }: { texto?: string } = $props();
</script>

<div class="girar" role="status" aria-live="polite">
	<div class="girar__figura" aria-hidden="true">
		<span class="girar__telefono"><Smartphone size={44} /></span>
		<span class="girar__flecha"><RotateCw size={22} /></span>
	</div>
	<p class="girar__texto">{texto}</p>
</div>

<style>
	.girar {
		position: absolute;
		inset: 0;
		z-index: 3;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 1rem;
		/* Oscurece sin tapar: quien ya está encuadrando tiene que seguir viendo
		   lo que la cámara ve, o no sabrá si giró bien. */
		background: rgb(0 0 0 / 0.55);
		backdrop-filter: blur(1px);
		pointer-events: none;
		padding: 1rem;
		text-align: center;
	}

	.girar__figura {
		position: relative;
		display: grid;
		place-items: center;
		width: 5.5rem;
		height: 5.5rem;
	}

	.girar__telefono {
		display: grid;
		place-items: center;
		color: #fff;
		animation: girar-aparato 2.4s ease-in-out infinite;
	}

	.girar__flecha {
		position: absolute;
		top: -0.2rem;
		right: -0.2rem;
		color: var(--color-primary, #4f8ef7);
		animation: latir 2.4s ease-in-out infinite;
	}

	.girar__texto {
		margin: 0;
		color: #fff;
		font-size: 1rem;
		font-weight: 600;
		max-width: 16rem;
		line-height: 1.4;
		text-shadow: 0 1px 3px rgb(0 0 0 / 0.6);
	}

	/* De pie → acostado, y vuelta a empezar. La pausa en el 90° es lo que hace
	   que se lea como una instrucción y no como un adorno que da vueltas. */
	@keyframes girar-aparato {
		0%,
		20% {
			transform: rotate(0deg);
		}
		45%,
		80% {
			transform: rotate(-90deg);
		}
		100% {
			transform: rotate(0deg);
		}
	}

	@keyframes latir {
		0%,
		20%,
		100% {
			opacity: 0.35;
		}
		45%,
		80% {
			opacity: 1;
		}
	}

	/* Con el movimiento reducido, el mensaje se queda quieto. Sigue diciendo lo
	   mismo: quien lo pidió es porque el movimiento le molesta o le marea. */
	@media (prefers-reduced-motion: reduce) {
		.girar__telefono,
		.girar__flecha {
			animation: none;
		}

		.girar__telefono {
			transform: rotate(-90deg);
		}

		.girar__flecha {
			opacity: 1;
		}
	}
</style>
