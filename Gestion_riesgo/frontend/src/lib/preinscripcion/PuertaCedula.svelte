<script lang="ts">
	// La primera pantalla: la cédula, antes que nada.
	//
	// La pre-inscripción dejó de ser un formulario abierto. Es la CONTINUACIÓN
	// del proceso de quien ya fue censado en campo, así que aquí se comprueba
	// que la cédula esté en el RUFE y solo entonces se abre el formulario.
	//
	// ── Lo que manda sobre esta pantalla ─────────────────────────────────────
	//
	// Es la única del sistema que puede decirle que NO a una familia
	// damnificada. Eso obliga a tres cosas:
	//
	//  1. Que el «no» nunca sea un callejón. Siempre sale el teléfono, marcable
	//     de un toque, porque quien lo lee está en un celular.
	//  2. Que se distinga «su cédula no aparece» de «no pudimos consultar».
	//     Confundirlos manda a la línea de atención a alguien que solo se quedó
	//     sin señal, y llena el conmutador de llamadas que no eran.
	//  3. Que se pueda reintentar sin recargar. La causa más probable de un «no»
	//     es un dígito mal tecleado, y la segunda, que la casa quedara censada a
	//     nombre de otra persona del hogar.

	import { CircleAlert, LoaderCircle, Phone, ShieldCheck } from '@lucide/svelte';
	import { ApiError } from '$lib/api/client';
	import { preinscripcionApi } from '$lib/api/servicios';
	import { LINEA_ATENCION, normalizar, revisarCedula } from './puerta';

	type Props = {
		/** Se llama con la cédula ya normalizada cuando el censo la reconoce. */
		onEntrar: (documento: string) => void;
	};

	let { onEntrar }: Props = $props();

	let cedula = $state('');
	let consultando = $state(false);
	let error = $state('');
	/** El «no» del censo, que no es un error del formulario y se ve distinto. */
	let negado = $state(false);

	async function consultar(evento: SubmitEvent) {
		evento.preventDefault();

		const aviso = revisarCedula(cedula);
		if (aviso !== '') {
			error = aviso;
			negado = false;

			return;
		}

		consultando = true;
		error = '';
		negado = false;

		const documento = normalizar(cedula);

		try {
			const r = await preinscripcionApi.verificar(documento);

			if (r.habilitado) {
				onEntrar(documento);

				return;
			}

			negado = true;
		} catch (e) {
			// Sin conexión NO es «su cédula no aparece». Se dice lo que pasó, y se
			// deja el botón para volver a intentarlo cuando vuelva la señal.
			if (e instanceof ApiError && e.status === 0) {
				error =
					'No hay conexión en este momento y necesitamos verificar su cédula. Inténtelo de nuevo cuando tenga señal.';
			} else if (e instanceof ApiError) {
				error = e.message;
			} else {
				error = 'No se pudo verificar su cédula. Inténtelo de nuevo en unos minutos.';
			}
		} finally {
			consultando = false;
		}
	}

	function volverAIntentar() {
		negado = false;
		error = '';
		cedula = '';
	}
</script>

{#if negado}
	<!-- El «no». Ocupa la pantalla entera porque es lo único que esta persona
	     tiene que leer, y termina en un número de teléfono, nunca en un punto. -->
	<section class="tarjeta puerta puerta--negada">
		<CircleAlert size={38} aria-hidden="true" />
		<h2 class="puerta__titulo">Su cédula no aparece en el censo</h2>

		<p class="puerta__texto">
			Este formulario es para las familias que ya fueron registradas en el censo de afectados
			(RUFE). No encontramos esa cédula, y por eso no podemos continuar por aquí.
		</p>

		<a class="linea" href="tel:{LINEA_ATENCION.marcar}">
			<Phone size={20} aria-hidden="true" />
			<span>
				<strong class="linea__numero">{LINEA_ATENCION.legible}</strong>
				<span class="linea__extension">Extensión {LINEA_ATENCION.extension}</span>
			</span>
		</a>

		<p class="puerta__texto">
			Llame a la línea de atención de {LINEA_ATENCION.entidad}. Allí revisan su caso y le dicen qué
			sigue. <strong>Que no aparezca aquí no significa que su caso esté cerrado.</strong>
		</p>

		<p class="puerta__pista">
			Antes de llamar, vale la pena probar otra vez. Lo más común es un dígito equivocado, y lo
			segundo, que cuando el funcionario visitó la casa la registrara a nombre de otra persona del
			hogar: pruebe con la cédula de esa persona.
		</p>

		<button type="button" class="boton boton--suave" onclick={volverAIntentar}>
			Probar con otra cédula
		</button>
	</section>
{:else}
	<section class="tarjeta puerta">
		<ShieldCheck size={30} aria-hidden="true" />
		<h2 class="puerta__titulo">Antes de empezar</h2>

		<p class="puerta__texto">
			Este formulario continúa el proceso de las familias que ya fueron registradas en el censo de
			afectados (RUFE), cuando un funcionario visitó la vivienda. Escriba su cédula para verificar
			que está registrada.
		</p>

		<form onsubmit={consultar}>
			<label class="campo campo--grande">
				<span class="campo__etiqueta">Número de cédula</span>
				<input
					class="campo__control"
					inputmode="numeric"
					autocomplete="off"
					bind:value={cedula}
					placeholder="Sin puntos ni espacios"
					disabled={consultando}
				/>
				{#if error}<span class="campo__error" role="alert">{error}</span>{/if}
			</label>

			<button type="submit" class="boton puerta__continuar" disabled={consultando}>
				{#if consultando}
					<LoaderCircle size={16} class="girando" aria-hidden="true" /> Verificando…
				{:else}
					Continuar
				{/if}
			</button>
		</form>

		<p class="puerta__pista">
			Solo se usa para buscarla en el censo. Si no está registrada, le indicaremos a dónde llamar.
		</p>
	</section>
{/if}

<style>
	.puerta {
		display: grid;
		justify-items: center;
		text-align: center;
		gap: 0.6rem;
	}

	.puerta :global(svg) {
		color: var(--color-primary);
	}

	.puerta--negada :global(svg) {
		color: var(--color-danger);
	}

	.puerta__titulo {
		margin: 0;
		font-size: 1.15rem;
	}

	.puerta__texto {
		margin: 0;
		max-width: 34rem;
		line-height: 1.55;
	}

	.puerta__pista {
		margin: 0;
		max-width: 34rem;
		font-size: 0.86rem;
		line-height: 1.5;
		color: var(--color-muted);
	}

	form {
		width: 100%;
		max-width: 22rem;
		display: grid;
		gap: 0.75rem;
		margin: 0.6rem 0 0.2rem;
		text-align: left;
	}

	.puerta__continuar {
		justify-content: center;
		width: 100%;
	}

	/*
		El teléfono es un enlace `tel:` y no un texto: quien lee esto lo hace en un
		celular, y copiar un número a mano mientras se está alterado es justo la
		clase de fricción que hace que la llamada no se haga.
	*/
	.linea {
		display: inline-flex;
		align-items: center;
		gap: 0.7rem;
		margin: 0.3rem 0;
		padding: 0.7rem 1.1rem;
		border: 1px solid var(--color-border);
		border-radius: 12px;
		background: var(--color-surface-alt);
		color: inherit;
		text-decoration: none;
	}

	.linea span {
		display: grid;
		text-align: left;
	}

	.linea__numero {
		font-size: 1.25rem;
		letter-spacing: 0.02em;
		font-variant-numeric: tabular-nums;
	}

	.linea__extension {
		font-size: 0.82rem;
		color: var(--color-muted);
	}
</style>
