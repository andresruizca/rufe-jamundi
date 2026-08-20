<script lang="ts">
	// El numeral 5.4: la evaluación técnica, elemento por elemento.
	//
	// En el papel es una tabla de siete filas por seis columnas. En un teléfono
	// de 360 px eso no cabe, y forzarlo produce celdas de cinco milímetros que se
	// marcan mal — justo donde un error cambia el combo de materiales. Aquí es
	// una tarjeta por elemento: primero la pregunta grande («¿fue afectado?») y,
	// solo si la respuesta es sí, los niveles.
	//
	// Lo que este componente aporta y el papel no: los criterios del Anexo 1
	// junto a cada nivel. Hoy el profesional los consulta en una hoja impresa que
	// se queda en la camioneta; tenerlos donde se toma la decisión es la
	// diferencia entre clasificar con el criterio de la norma y clasificar de
	// memoria.
	//
	// Los niveles que el Anexo 1 no define para un elemento —las casillas «N/A»
	// del formato— sencillamente no se muestran. No se dibujan en gris: una
	// opción visible pero inerte invita a intentar pulsarla.

	import { ChevronDown, TriangleAlert } from '@lucide/svelte';
	import type { DanoElemento, ElementoEvaluable } from '../tipos';

	type Props = {
		elementos: ElementoEvaluable[];
		danos: Record<string, DanoElemento>;
		errores: Record<string, string>;
		alCambiar?: () => void;
	};

	let { elementos, danos = $bindable(), errores, alCambiar }: Props = $props();

	/** Qué criterios están desplegados. Se recuerda por elemento y nivel. */
	let abiertos = $state<Record<string, boolean>>({});

	function responder(codigo: string, afectado: boolean) {
		const previo = danos[codigo] ?? { afectado: null, nivel: null };

		danos = {
			...danos,
			// Pasar de «sí» a «no» borra el nivel: dejarlo escondido haría que el
			// expediente guardara un daño que el profesional ya descartó.
			[codigo]: { afectado, nivel: afectado ? previo.nivel : null }
		};

		alCambiar?.();
	}

	function elegirNivel(codigo: string, nivel: string) {
		danos = { ...danos, [codigo]: { afectado: true, nivel } };
		alCambiar?.();
	}

	function alternar(clave: string) {
		abiertos = { ...abiertos, [clave]: !abiertos[clave] };
	}
</script>

<div class="evaluacion">
	{#each elementos as elemento (elemento.codigo)}
		{@const dano = danos[elemento.codigo] ?? { afectado: null, nivel: null }}
		{@const errorAfectado = errores[`danos.${elemento.codigo}.afectado`]}
		{@const errorNivel = errores[`danos.${elemento.codigo}.nivel`]}

		<fieldset class="elemento" class:elemento--error={errorAfectado || errorNivel}>
			<legend class="elemento__titulo">
				{elemento.etiqueta}
				{#if elemento.estructural}
					<!-- Se marca porque de estos dos sale el combo, y conviene que
					     quien evalúa sepa cuáles pesan sobre la entrega. -->
					<span class="elemento__marca" title="De los elementos estructurales sale el combo de materiales">
						estructural
					</span>
				{/if}
			</legend>

			<div class="afectado" role="group" aria-label="¿{elemento.etiqueta} resultó afectado?">
				<button
					type="button"
					class="afectado__btn"
					class:afectado__btn--activo={dano.afectado === true}
					aria-pressed={dano.afectado === true}
					onclick={() => responder(elemento.codigo, true)}
				>
					Sí, fue afectado
				</button>
				<button
					type="button"
					class="afectado__btn"
					class:afectado__btn--activo={dano.afectado === false}
					aria-pressed={dano.afectado === false}
					onclick={() => responder(elemento.codigo, false)}
				>
					No
				</button>
			</div>

			{#if errorAfectado}
				<p class="elemento__error" role="alert">
					<TriangleAlert size={13} aria-hidden="true" />
					{errorAfectado}
				</p>
			{/if}

			{#if dano.afectado === true}
				<div class="niveles">
					<p class="niveles__titulo">Nivel de daño</p>

					{#each elemento.niveles as nivel (nivel.codigo)}
						{@const clave = `${elemento.codigo}.${nivel.codigo}`}
						<div class="nivel" class:nivel--activo={dano.nivel === nivel.codigo}>
							<button
								type="button"
								class="nivel__elegir"
								aria-pressed={dano.nivel === nivel.codigo}
								onclick={() => elegirNivel(elemento.codigo, nivel.codigo)}
							>
								<span class="nivel__nombre nivel__nombre--{nivel.codigo.toLowerCase()}">
									{nivel.etiqueta}
								</span>
								<span class="nivel__alcance">{nivel.alcance}</span>
							</button>

							{#if nivel.criterios.length > 0}
								<button
									type="button"
									class="nivel__ver"
									aria-expanded={!!abiertos[clave]}
									onclick={() => alternar(clave)}
								>
									<ChevronDown size={14} aria-hidden="true" class={abiertos[clave] ? 'girado' : ''} />
									{abiertos[clave] ? 'Ocultar criterios' : 'Ver criterios del Anexo 1'}
								</button>

								{#if abiertos[clave]}
									<ul class="criterios">
										{#each nivel.criterios as criterio (criterio)}
											<li>{criterio}</li>
										{/each}
									</ul>
								{/if}
							{/if}
						</div>
					{/each}

					{#if errorNivel}
						<p class="elemento__error" role="alert">
							<TriangleAlert size={13} aria-hidden="true" />
							{errorNivel}
						</p>
					{/if}
				</div>
			{/if}
		</fieldset>
	{/each}
</div>

<style>
	.evaluacion {
		display: flex;
		flex-direction: column;
		gap: 0.9rem;
	}

	.elemento {
		margin: 0;
		padding: 0.85rem;
		border: 1px solid var(--color-border);
		border-radius: 0.6rem;
		background: var(--color-surface);
	}

	.elemento--error {
		border-color: var(--aviso-error-borde);
	}

	.elemento__titulo {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		flex-wrap: wrap;
		padding: 0 0.3rem;
		font-weight: 600;
		font-size: 0.95rem;
	}

	.elemento__marca {
		padding: 0.08rem 0.4rem;
		border-radius: 999px;
		background: var(--color-info-bg);
		color: var(--aviso-info-texto);
		font-size: 0.68rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.02em;
	}

	.afectado {
		display: flex;
		gap: 0.5rem;
		margin-top: 0.6rem;
	}

	/* Botones grandes: esto se marca de pie, con guantes y a veces con lluvia. */
	.afectado__btn {
		flex: 1;
		min-height: 2.9rem;
		padding: 0.5rem 0.6rem;
		border: 1px solid var(--color-border);
		border-radius: 0.5rem;
		background: var(--color-surface-alt);
		color: var(--color-text);
		font-size: 0.9rem;
		font-weight: 500;
		cursor: pointer;
	}

	.afectado__btn--activo {
		border-color: var(--color-primary);
		background: var(--color-info-bg);
		color: var(--aviso-info-texto);
		font-weight: 600;
	}

	.niveles {
		margin-top: 0.8rem;
		padding-top: 0.7rem;
		border-top: 1px dashed var(--color-border);
	}

	.niveles__titulo {
		margin: 0 0 0.5rem;
		font-size: 0.78rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.03em;
		color: var(--color-muted);
	}

	.nivel {
		border: 1px solid var(--color-border);
		border-radius: 0.5rem;
		margin-bottom: 0.45rem;
		overflow: hidden;
	}

	.nivel--activo {
		border-color: var(--color-primary);
	}

	.nivel__elegir {
		display: flex;
		align-items: baseline;
		gap: 0.5rem;
		width: 100%;
		min-height: 2.6rem;
		padding: 0.45rem 0.6rem;
		border: 0;
		background: transparent;
		color: var(--color-text);
		text-align: left;
		cursor: pointer;
	}

	.nivel--activo .nivel__elegir {
		background: var(--color-info-bg);
	}

	.nivel__nombre {
		font-weight: 600;
		font-size: 0.9rem;
	}

	/* Los mismos colores del anexo impreso: amarillo, naranja, rojo y gris.
	   Quien viene del papel reconoce la escala sin leerla. */
	.nivel__nombre--leve {
		color: #8a6d00;
	}
	.nivel__nombre--moderado {
		color: #a85700;
	}
	.nivel__nombre--severo {
		color: var(--color-danger);
	}
	.nivel__nombre--colapso_total {
		color: var(--color-muted);
	}

	.nivel__alcance {
		font-size: 0.78rem;
		color: var(--color-muted);
	}

	.nivel__ver {
		display: flex;
		align-items: center;
		gap: 0.3rem;
		width: 100%;
		padding: 0.35rem 0.6rem;
		border: 0;
		border-top: 1px solid var(--color-border);
		background: var(--color-surface-alt);
		color: var(--color-muted);
		font-size: 0.76rem;
		text-align: left;
		cursor: pointer;
	}

	.nivel__ver :global(.girado) {
		transform: rotate(180deg);
	}

	.criterios {
		margin: 0;
		padding: 0.5rem 0.7rem 0.6rem 1.5rem;
		background: var(--color-surface-alt);
		font-size: 0.8rem;
		line-height: 1.45;
		color: var(--color-text);
	}

	.criterios li + li {
		margin-top: 0.35rem;
	}

	.elemento__error {
		display: flex;
		align-items: center;
		gap: 0.25rem;
		margin: 0.45rem 0 0;
		font-size: 0.78rem;
		color: var(--aviso-error-texto);
	}
</style>
