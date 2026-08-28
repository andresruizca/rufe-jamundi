<script lang="ts">
	// El barrio: una lista con buscador, no un campo de texto.
	//
	// ── Por qué ──────────────────────────────────────────────────────────────
	//
	// El censo se levantó escribiendo el barrio a mano y salieron 249 grafías
	// distintas para 117 barrios reales: «Bocas Del Palo» y «Bocas del Palo»,
	// «TERRANOVA» y «Terranova». La clase `Barrios` del servidor existe solo
	// para volver a juntarlos al sumar, y esa tabla es la que decide a dónde
	// sale una brigada. Elegir de una lista corta el problema en el origen.
	//
	// La lista son los 165 del POT que entregó Planeación. Ya sirvió para
	// descubrir que el nombre oficial de «Terranova» es «Ciudadela Terranova».
	//
	// ── Y por qué se puede escribir algo que no esté ─────────────────────────
	//
	// Porque la lista es de 2021, y en un municipio que crece por invasión y por
	// urbanizaciones nuevas siempre va a faltar alguno. Cerrar el campo dejaría
	// fuera a una familia damnificada por un problema de catálogo, que es
	// exactamente la clase de error que este sistema no puede permitirse.
	//
	// Así que se ofrece la lista, se busca dentro, y quien no se encuentre
	// escribe lo suyo — y se le dice, sin regañarlo, que quedará para revisión.
	//
	// ── Por qué no un <select> ───────────────────────────────────────────────
	//
	// Un desplegable nativo con 165 opciones en un celular es una rueda
	// interminable, y no se puede teclear para buscar. Aquí se escriben tres
	// letras y quedan tres opciones.

	import { Check, ChevronDown, Search, X } from '@lucide/svelte';
	import { estaEnLaLista, filtrarBarrios, llano } from '$lib/preinscripcion/barrios';

	let {
		id,
		etiqueta,
		valor = $bindable(''),
		opciones = [],
		error = '',
		requerido = false,
		ayuda = '',
		alCambiar
	}: {
		id: string;
		etiqueta: string;
		valor?: string;
		/** Los barrios del catálogo. Vacío mientras cargan: entonces es texto libre. */
		opciones?: string[];
		error?: string;
		requerido?: boolean;
		ayuda?: string;
		alCambiar?: () => void;
	} = $props();

	let abierto = $state(false);
	let resaltada = $state(-1);
	let caja = $state<HTMLDivElement | null>(null);
	let entrada = $state<HTMLInputElement | null>(null);

	// La búsqueda vive en `$lib/preinscripcion/barrios` y no aquí: de ella
	// depende que alguien encuentre su propio barrio, y eso hay que poder
	// probarlo sin montar un componente.
	const filtradas = $derived(filtrarBarrios(opciones, valor));
	const enLaLista = $derived(estaEnLaLista(opciones, valor));

	function elegir(barrio: string) {
		valor = barrio;
		abierto = false;
		resaltada = -1;
		alCambiar?.();
	}

	function alTeclear() {
		abierto = true;
		resaltada = -1;
		alCambiar?.();
	}

	function alTecla(e: KeyboardEvent) {
		if (e.key === 'Escape') {
			abierto = false;
			resaltada = -1;

			return;
		}

		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			e.preventDefault();

			if (!abierto) {
				abierto = true;

				return;
			}

			const paso = e.key === 'ArrowDown' ? 1 : -1;
			const total = filtradas.length;
			if (total === 0) return;

			resaltada = (resaltada + paso + total) % total;

			return;
		}

		// Enter solo elige si hay algo resaltado. Sin esto, quien escribió su
		// barrio a mano y pulsa Enter para seguir se encontraría con otro
		// distinto puesto por el sistema.
		if (e.key === 'Enter' && abierto && resaltada >= 0) {
			e.preventDefault();
			elegir(filtradas[resaltada]);
		}
	}

	/**
	 * Cerrar al tocar fuera.
	 *
	 * Con `pointerdown` y no `click`: en un celular, tocar otro campo dispara el
	 * foco antes que el click, y la lista se quedaba abierta encima.
	 */
	$effect(() => {
		if (!abierto) return;

		const fuera = (e: Event) => {
			if (caja && !caja.contains(e.target as Node)) abierto = false;
		};

		document.addEventListener('pointerdown', fuera);

		return () => document.removeEventListener('pointerdown', fuera);
	});
</script>

<div class="campo" bind:this={caja}>
	<label class="campo__etiqueta" for={id}>
		{etiqueta}{#if requerido}<span aria-hidden="true"> *</span>{/if}
	</label>

	<div class="caja" class:caja--abierta={abierto}>
		<Search class="caja__lupa" size={15} aria-hidden="true" />

		<input
			bind:this={entrada}
			{id}
			class="campo__control caja__entrada"
			type="text"
			autocomplete="off"
			role="combobox"
			aria-expanded={abierto}
			aria-controls="{id}-lista"
			aria-autocomplete="list"
			aria-invalid={error !== ''}
			placeholder={opciones.length > 0 ? 'Escriba para buscar en la lista' : 'Escriba el barrio'}
			bind:value={valor}
			oninput={alTeclear}
			onfocus={() => (abierto = true)}
			onkeydown={alTecla}
		/>

		{#if valor !== ''}
			<button
				type="button"
				class="caja__boton"
				onclick={() => {
					valor = '';
					abierto = true;
					entrada?.focus();
					alCambiar?.();
				}}
				aria-label="Borrar el barrio escrito"
			>
				<X size={15} aria-hidden="true" />
			</button>
		{:else if opciones.length > 0}
			<button
				type="button"
				class="caja__boton"
				onclick={() => {
					abierto = !abierto;
					entrada?.focus();
				}}
				aria-label="Ver la lista de barrios"
			>
				<ChevronDown size={16} aria-hidden="true" />
			</button>
		{/if}
	</div>

	{#if abierto && opciones.length > 0}
		<ul class="lista" id="{id}-lista" role="listbox" aria-label="Barrios de Jamundí">
			{#each filtradas.slice(0, 60) as barrio, i (barrio)}
				<li>
					<button
						type="button"
						class="opcion"
						class:opcion--resaltada={i === resaltada}
						role="option"
						aria-selected={llano(barrio) === llano(valor)}
						onclick={() => elegir(barrio)}
						onmouseenter={() => (resaltada = i)}
					>
						{barrio}
						{#if llano(barrio) === llano(valor)}
							<Check size={14} aria-hidden="true" />
						{/if}
					</button>
				</li>
			{:else}
				<li class="vacia">
					Ningún barrio de la lista coincide. Puede escribirlo usted y seguir.
				</li>
			{/each}

			{#if filtradas.length > 60}
				<li class="vacia">
					Hay {filtradas.length - 60} más. Escriba unas letras para acortar la lista.
				</li>
			{/if}
		</ul>
	{/if}

	{#if error}
		<span class="campo__error" role="alert">{error}</span>
	{:else if valor.trim() !== '' && opciones.length > 0 && !enLaLista}
		<!-- Aviso, no error. Su barrio puede no estar en una lista de 2021, y eso
		     no es culpa suya ni motivo para detenerla. -->
		<span class="campo__ayuda campo__ayuda--nota">
			Ese barrio no está en la lista oficial. Puede continuar: quedará anotado para que Planeación
			lo revise.
		</span>
	{:else if ayuda}
		<span class="campo__ayuda">{ayuda}</span>
	{/if}
</div>

<style>
	.campo {
		position: relative;
		display: grid;
		gap: 0.25rem;
	}

	.caja {
		position: relative;
		display: flex;
		align-items: center;
	}

	.caja :global(.caja__lupa) {
		position: absolute;
		left: 0.6rem;
		color: var(--color-muted);
		pointer-events: none;
	}

	.caja__entrada {
		width: 100%;
		padding-left: 2.1rem;
		padding-right: 2.2rem;
	}

	.caja__boton {
		position: absolute;
		right: 0.35rem;
		display: grid;
		place-items: center;
		width: 1.8rem;
		height: 1.8rem;
		border: none;
		border-radius: 6px;
		background: none;
		color: var(--color-muted);
		cursor: pointer;
	}

	.caja__boton:hover {
		color: var(--color-text);
		background: var(--color-surface-alt);
	}

	/* La lista flota: si empujara el contenido, cada tecla movería el resto del
	   formulario y el campo se saldría de la pantalla en un celular. */
	.lista {
		position: absolute;
		z-index: 30;
		top: 100%;
		left: 0;
		right: 0;
		margin: 0.25rem 0 0;
		padding: 0.25rem;
		list-style: none;
		max-height: 15rem;
		overflow-y: auto;
		border: 1px solid var(--color-border-strong);
		border-radius: 10px;
		background: var(--color-surface);
		box-shadow: 0 12px 28px rgb(0 0 0 / 0.28);
	}

	.opcion {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.5rem;
		width: 100%;
		border: none;
		background: none;
		color: var(--color-text);
		text-align: left;
		font: inherit;
		font-size: 0.9rem;
		/* Alto de dedo: esto se usa de pie, en el patio de una casa. */
		padding: 0.55rem 0.6rem;
		border-radius: 7px;
		cursor: pointer;
	}

	.opcion--resaltada {
		background: var(--color-surface-alt);
	}

	.vacia {
		padding: 0.6rem;
		font-size: 0.84rem;
		line-height: 1.4;
		color: var(--color-muted);
	}

	.campo__ayuda--nota {
		color: var(--color-warning);
	}
</style>
