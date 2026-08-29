<script lang="ts">
	// Una cifra del tablero. Y, donde haga falta, el control que la abre.
	//
	// ── Por qué el botón es opcional ─────────────────────────────────────────
	//
	// Este componente lo comparten el tablero de riesgo, las instituciones
	// educativas y los equipamientos, donde las cifras no filtran nada: ahí un
	// botón sería mentir sobre lo que la pantalla hace, y se pulsaría esperando
	// algo que no va a pasar.
	//
	// Sin `alPulsar` se dibuja exactamente lo de siempre, un bloque de texto.
	// Con `alPulsar` es un <button> de verdad —no un <div> con onclick—, porque
	// esa diferencia es la que decide si la pantalla se puede usar con teclado,
	// y no se nota nunca desde el ratón de quien la programa.

	import type { LucideIcon } from '@lucide/svelte';

	let {
		label,
		value,
		color,
		sub,
		icon,
		alPulsar,
		activa = false
	}: {
		label: string;
		value: number;
		color: string;
		sub: string;
		icon?: LucideIcon;
		/** Si se pasa, la tarjeta es un control. Si no, es solo una cifra. */
		alPulsar?: () => void;
		/** La cola que esta tarjeta abre es la que se está viendo. */
		activa?: boolean;
	} = $props();
</script>

{#snippet contenido()}
	<div class="kpi-label">
		{#if icon}
			{@const Icon = icon}
			<Icon size={15} strokeWidth={2.4} {color} aria-hidden="true" />
		{:else}
			<span class="dot" style:background={color}></span>
		{/if}
		{label}
	</div>
	<div class="kpi-value">{value.toLocaleString('es-CO')}</div>
	<div class="kpi-sub">{sub}</div>
{/snippet}

{#if alPulsar}
	<button
		type="button"
		class="kpi-tile kpi-tile--boton"
		class:kpi-tile--activa={activa}
		style:--kpi-color={color}
		aria-pressed={activa}
		onclick={alPulsar}
	>
		{@render contenido()}
	</button>
{:else}
	<div class="kpi-tile">
		{@render contenido()}
	</div>
{/if}

<style>
	.kpi-tile {
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		border-radius: var(--radius);
		padding: 12px 13px;
		display: flex;
		flex-direction: column;
		gap: 5px;
		box-shadow: var(--shadow-sm);
	}
	.kpi-label {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 12px;
		font-weight: 600;
		color: var(--color-muted);
	}
	.dot {
		width: 9px;
		height: 9px;
		border-radius: 50%;
		flex: none;
	}
	.kpi-value {
		font-size: clamp(21px, 6vw, 27px);
		font-weight: 800;
		letter-spacing: -0.01em;
		color: var(--color-text);
		font-variant-numeric: tabular-nums;
	}
	.kpi-sub {
		font-size: 11.5px;
		color: var(--color-muted);
	}

	/* ── Cuando además es un control ──────────────────────────────────────── */

	.kpi-tile--boton {
		/* Alineado a la izquierda como el resto: un botón hereda `text-align:
		   center` del navegador y las tres líneas quedarían centradas, que es
		   justo lo que delata una tarjeta convertida en botón a última hora. */
		text-align: left;
		font: inherit;
		cursor: pointer;
		transition:
			border-color 0.12s ease,
			transform 0.12s ease;
	}

	.kpi-tile--boton:hover {
		border-color: var(--kpi-color);
		transform: translateY(-1px);
	}

	/* Sin `transform` para quien pidió que la pantalla no se mueva. El color sí
	   se queda: es la única señal de que la tarjeta responde. */
	@media (prefers-reduced-motion: reduce) {
		.kpi-tile--boton {
			transition: none;
		}
		.kpi-tile--boton:hover {
			transform: none;
		}
	}

	.kpi-tile--boton:focus-visible {
		outline: 2px solid var(--kpi-color);
		outline-offset: 2px;
	}

	/* La cola abierta. El borde del color de la tarjeta y no un fondo: sobre
	   nueve tarjetas seguidas, un relleno tapa la cifra, que es lo que se viene
	   a leer. */
	.kpi-tile--activa {
		border-color: var(--kpi-color);
		box-shadow:
			var(--shadow-sm),
			inset 3px 0 0 var(--kpi-color);
	}
</style>
