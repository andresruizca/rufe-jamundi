<script lang="ts">
	// El numeral 6: qué combo de materiales corresponde y por qué.
	//
	// El combo NO se elige: sale de la evaluación técnica, siguiendo la regla
	// impresa en el formato. Por eso aquí no hay casillas que marcar salvo el kit
	// de cubierta — que sí es una decisión del profesional.
	//
	// Se muestra siempre el PORQUÉ junto al resultado. Quien revisa el
	// expediente, y sobre todo la familia que pregunta por qué le dan un combo y
	// no otro, tiene derecho a ver el razonamiento sin rehacerlo. Un número solo
	// se parece demasiado a una decisión arbitraria.
	//
	// El combo que se ve aquí lo calcula el navegador para que cambie al
	// instante mientras se llena la tabla; el que queda en el expediente lo
	// calcula el servidor. Coinciden porque las dos implementaciones ejecutan la
	// misma tabla de casos.

	import { Info, PackageCheck, TriangleAlert } from '@lucide/svelte';
	import CampoOpciones from '$lib/rufe-form/componentes/CampoOpciones.svelte';
	import type { ResultadoCombo } from '../combo';
	import type { ListaMateriales } from '../detalle';

	type Props = {
		resultado: ResultadoCombo;
		motivo: string;
		materiales: ListaMateriales | null;
		kits: { codigo: string; etiqueta: string }[];
		kitCubierta: string;
		sugerido: string | null;
		error?: string;
		alCambiar?: () => void;
	};

	let {
		resultado,
		motivo,
		materiales,
		kits,
		kitCubierta = $bindable(''),
		sugerido,
		error = '',
		alCambiar
	}: Props = $props();

	const opciones = $derived(kits.map((k) => ({ valor: k.codigo, etiqueta: k.etiqueta })));

	const totalItems = $derived(
		(materiales?.kits ?? []).reduce((suma, k) => suma + k.items.length, 0)
	);
</script>

{#if resultado.combo === null}
	<p class="aviso aviso--info" role="status">
		<Info size={15} aria-hidden="true" />
		{motivo}
	</p>
{:else}
	<div class="combo">
		<div class="combo__cabecera">
			<PackageCheck size={20} aria-hidden="true" />
			<div>
				<p class="combo__nombre">{resultado.etiqueta}</p>
				<!-- El porqué, no solo el qué. -->
				<p class="combo__motivo">{motivo}</p>
			</div>
		</div>
	</div>
{/if}

{#if kits.length > 0}
	<CampoOpciones
		id="kit_cubierta"
		etiqueta="Kit de cubierta"
		bind:valor={kitCubierta}
		opciones={opciones}
		{error}
		ayuda={sugerido
			? 'Según el material de cubierta que registró, corresponde el kit marcado. Puede cambiarlo.'
			: 'Elija el kit según la cubierta que se va a instalar.'}
		{alCambiar}
	/>
{/if}

{#if materiales}
	{#if materiales.sin_lista}
		<p class="aviso aviso--alerta" role="status">
			<TriangleAlert size={15} aria-hidden="true" />
			<!--
				El Anexo 2 solo trae columnas para leve, moderado y severo. Para el
				colapso total el formato nombra un combo pero no lista sus
				materiales. Se dice, en vez de rellenarlo con las cantidades del
				severo: son materiales públicos y una cifra inventada no se
				distingue de una correcta al imprimirla.
			-->
			{materiales.nota || 'Este combo no tiene lista de materiales en el Anexo 2.'}
		</p>
	{:else}
		<div class="materiales">
			<p class="materiales__titulo">
				Materiales del Anexo 2
				<span class="materiales__cuenta">{totalItems} renglones</span>
			</p>

			{#each materiales.kits as kit (kit.kit)}
				<div class="kit">
					<p class="kit__nombre">{kit.kit}</p>
					<table class="kit__tabla">
						<thead>
							<tr>
								<th scope="col">Descripción</th>
								<th scope="col">Und</th>
								<th scope="col" class="kit__num">Cantidad</th>
							</tr>
						</thead>
						<tbody>
							{#each kit.items as item (item.descripcion)}
								<tr>
									<td>{item.descripcion}</td>
									<td>{item.unidad}</td>
									<td class="kit__num">{item.cantidad}</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
			{/each}

			<p class="materiales__nota">
				<!--
					El cemento, por ejemplo, aparece en el kit de estructura y otra vez
					en el de mampostería, con cantidades distintas. Son partidas
					distintas del anexo y sumarlas o fundirlas cambiaría la entrega.
				-->
				Cada kit se lista por separado, tal como el Anexo 2. Un mismo material puede aparecer en
				más de un kit con cantidades distintas.
			</p>
		</div>
	{/if}
{/if}

<style>
	.combo {
		margin: 0.4rem 0 1rem;
		padding: 0.85rem;
		border: 1px solid var(--color-primary);
		border-radius: 0.6rem;
		background: var(--color-info-bg);
	}

	.combo__cabecera {
		display: flex;
		gap: 0.6rem;
		align-items: flex-start;
		color: var(--aviso-info-texto);
	}

	.combo__nombre {
		margin: 0;
		font-size: 1rem;
		font-weight: 700;
	}

	.combo__motivo {
		margin: 0.15rem 0 0;
		font-size: 0.83rem;
		line-height: 1.4;
	}

	.materiales {
		margin-top: 1rem;
	}

	.materiales__titulo {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 0.5rem;
		margin: 0 0 0.6rem;
		font-size: 0.8rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.03em;
		color: var(--color-muted);
	}

	.materiales__cuenta {
		text-transform: none;
		letter-spacing: 0;
		font-weight: 500;
	}

	.kit {
		margin-bottom: 0.9rem;
		border: 1px solid var(--color-border);
		border-radius: 0.5rem;
		overflow: hidden;
	}

	.kit__nombre {
		margin: 0;
		padding: 0.45rem 0.6rem;
		background: var(--color-surface-alt);
		border-bottom: 1px solid var(--color-border);
		font-size: 0.85rem;
		font-weight: 600;
	}

	.kit__tabla {
		width: 100%;
		border-collapse: collapse;
		font-size: 0.82rem;
	}

	.kit__tabla th,
	.kit__tabla td {
		padding: 0.35rem 0.6rem;
		text-align: left;
		border-bottom: 1px solid var(--color-border);
	}

	.kit__tabla th {
		font-size: 0.72rem;
		text-transform: uppercase;
		letter-spacing: 0.02em;
		color: var(--color-muted);
	}

	.kit__tabla tbody tr:last-child td {
		border-bottom: 0;
	}

	.kit__num {
		text-align: right;
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}

	.materiales__nota {
		margin: 0;
		font-size: 0.76rem;
		line-height: 1.45;
		color: var(--color-muted);
	}
</style>
