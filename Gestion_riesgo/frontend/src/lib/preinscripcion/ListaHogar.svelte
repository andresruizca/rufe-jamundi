<script lang="ts">
	// Quiénes viven en la vivienda, según el censo y según la familia.
	//
	// Solo aparece cuando la cédula estaba en el RUFE y la persona subió la foto
	// para traer sus datos. Lo que se ve es lo que un funcionario levantó con la
	// casa delante; lo que la familia haga aquí es una PROPUESTA que después
	// revisa alguien. `rufe_personas` no cambia por esto.
	//
	// ── Por qué no se puede borrar a nadie ───────────────────────────────────
	//
	// A quien vino del censo se le marca «ya no vive aquí», no se le quita de la
	// lista. Borrar de un clic a una persona del censo de damnificados —y perder
	// que alguna vez estuvo— no debería poder hacerse sin que nadie mire. A
	// quien agregó la propia familia en esta pantalla sí se le puede quitar:
	// todavía no lo ha revisado nadie.

	import { ChevronDown, Pencil, Plus, Trash2, UserPlus, Undo2 } from '@lucide/svelte';
	import {
		estaCorregida,
		personaVacia,
		sePuedeQuitar,
		type PersonaCenso,
		type PersonaHogar
	} from './hogar';

	let {
		personas = $bindable(),
		censo,
		catalogos
	}: {
		personas: PersonaHogar[];
		/** Como venían del censo, para poder decir qué cambió. */
		censo: PersonaCenso[];
		catalogos: {
			parentescos: Record<string, string>;
			generos: Record<string, string>;
			tipos_documento: Record<string, string>;
		};
	} = $props();

	/** Cuál se está editando. Solo una a la vez: es un formulario en un celular. */
	let abierta = $state<string | null>(null);

	const vigentes = $derived(personas.filter((p) => !p.no_vive_aqui).length);

	function agregar() {
		const nueva = personaVacia();
		personas = [...personas, nueva];
		abierta = nueva.uid;
	}

	function quitar(uid: string) {
		personas = personas.filter((p) => p.uid !== uid);

		if (abierta === uid) abierta = null;
	}

	function etiqueta(mapa: Record<string, string>, codigo: number | null): string {
		return codigo === null ? '' : (mapa[String(codigo)] ?? '');
	}

	function resumen(p: PersonaHogar): string {
		return [etiqueta(catalogos.parentescos, p.parentesco), p.numero_documento]
			.filter((x) => x !== '')
			.join(' · ');
	}
</script>

<section class="tarjeta">
	<h3 class="hogar__titulo">Quiénes viven en la vivienda</h3>
	<p class="hogar__ayuda">
		Esto es lo que quedó registrado cuando visitaron su casa. Revise que esté bien: puede corregir
		un dato, agregar a quien falte, o marcar a quien ya no viva ahí.
		<strong>Nada de esto cambia solo</strong> — lo revisa un funcionario.
	</p>

	<ul class="hogar">
		{#each personas as p (p.uid)}
			{@const corregida = estaCorregida(p, censo)}
			<li class="persona" class:persona--fuera={p.no_vive_aqui}>
				<div class="persona__cabeza">
					<div class="persona__quien">
						<span class="persona__nombre">
							{p.nombres || p.apellidos ? `${p.nombres} ${p.apellidos}`.trim() : 'Sin nombre'}
						</span>
						<span class="persona__meta">{resumen(p)}</span>
					</div>

					<div class="persona__marcas">
						{#if p.no_vive_aqui}
							<span class="marca marca--fuera">Ya no vive aquí</span>
						{:else if p.rufe_persona_id === null}
							<span class="marca marca--nueva">Agregada por usted</span>
						{:else if corregida}
							<span class="marca marca--corregida">Corregida</span>
						{/if}
					</div>

					<button
						type="button"
						class="persona__abrir"
						aria-expanded={abierta === p.uid}
						onclick={() => (abierta = abierta === p.uid ? null : p.uid)}
					>
						{#if abierta === p.uid}
							<ChevronDown size={15} aria-hidden="true" />
							Cerrar
						{:else}
							<Pencil size={14} aria-hidden="true" />
							Revisar
						{/if}
					</button>
				</div>

				{#if abierta === p.uid}
					<div class="persona__campos">
						<label class="campo">
							<span class="campo__etiqueta">Nombres</span>
							<input class="campo__control" maxlength="120" bind:value={p.nombres} />
						</label>

						<label class="campo">
							<span class="campo__etiqueta">Apellidos</span>
							<input class="campo__control" maxlength="120" bind:value={p.apellidos} />
						</label>

						<label class="campo">
							<span class="campo__etiqueta">Parentesco</span>
							<select class="campo__control" bind:value={p.parentesco}>
								<option value={null}>Sin indicar</option>
								{#each Object.entries(catalogos.parentescos) as [codigo, texto] (codigo)}
									<option value={Number(codigo)}>{texto}</option>
								{/each}
							</select>
						</label>

						<label class="campo">
							<span class="campo__etiqueta">Tipo de documento</span>
							<select class="campo__control" bind:value={p.tipo_documento}>
								<option value={null}>Sin indicar</option>
								{#each Object.entries(catalogos.tipos_documento) as [codigo, texto] (codigo)}
									<option value={Number(codigo)}>{texto}</option>
								{/each}
							</select>
						</label>

						<label class="campo">
							<span class="campo__etiqueta">Número de documento</span>
							<input
								class="campo__control"
								inputmode="numeric"
								maxlength="30"
								bind:value={p.numero_documento}
							/>
							<span class="campo__ayuda">Déjelo en blanco si todavía no tiene.</span>
						</label>

						<label class="campo">
							<span class="campo__etiqueta">Sexo</span>
							<select class="campo__control" bind:value={p.genero}>
								<option value={null}>Sin indicar</option>
								{#each Object.entries(catalogos.generos) as [codigo, texto] (codigo)}
									<option value={Number(codigo)}>{texto}</option>
								{/each}
							</select>
						</label>

						<label class="campo">
							<span class="campo__etiqueta">Fecha de nacimiento</span>
							<input
								class="campo__control"
								type="date"
								max={new Date().toISOString().slice(0, 10)}
								bind:value={p.fecha_nacimiento}
							/>
						</label>

						<div class="persona__acciones">
							{#if sePuedeQuitar(p)}
								<!-- La agregó la familia hace un momento y no la ha visto
								     nadie: quitarla no pierde nada. -->
								<button type="button" class="quitar" onclick={() => quitar(p.uid)}>
									<Trash2 size={14} aria-hidden="true" />
									Quitar de la lista
								</button>
							{:else if p.no_vive_aqui}
								<button
									type="button"
									class="quitar"
									onclick={() => (p.no_vive_aqui = false)}
								>
									<Undo2 size={14} aria-hidden="true" />
									Sí vive aquí
								</button>
							{:else}
								<button
									type="button"
									class="quitar"
									onclick={() => {
										p.no_vive_aqui = true;
										abierta = null;
									}}
								>
									<UserPlus size={14} aria-hidden="true" />
									Esta persona ya no vive aquí
								</button>
							{/if}
						</div>
					</div>
				{/if}
			</li>
		{/each}
	</ul>

	<button type="button" class="boton boton--suave agregar" onclick={agregar}>
		<Plus size={16} aria-hidden="true" />
		Agregar otra persona
	</button>

	<p class="hogar__cuenta">
		{vigentes}
		{vigentes === 1 ? 'persona vive' : 'personas viven'} en la vivienda.
	</p>
</section>

<style>
	.hogar__titulo {
		margin: 0 0 0.3rem;
		font-size: 1.05rem;
		font-weight: 700;
	}

	.hogar__ayuda {
		margin: 0 0 0.9rem;
		font-size: 0.88rem;
		line-height: 1.5;
		color: var(--color-muted);
	}

	.hogar {
		list-style: none;
		margin: 0 0 0.8rem;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 0.5rem;
	}

	.persona {
		border: 1px solid var(--color-border);
		border-radius: 10px;
		padding: 0.7rem 0.8rem;
		background: var(--color-surface-alt);
	}

	/* Quien ya no vive ahí se apaga pero NO desaparece: sigue en la lista
	   porque es una afirmación que alguien tiene que revisar. */
	.persona--fuera {
		opacity: 0.6;
	}

	.persona__cabeza {
		display: flex;
		align-items: center;
		gap: 0.6rem;
		flex-wrap: wrap;
	}

	.persona__quien {
		display: flex;
		flex-direction: column;
		min-width: 0;
		flex: 1 1 10rem;
	}

	.persona__nombre {
		font-weight: 600;
		font-size: 0.95rem;
	}

	.persona__meta {
		font-size: 0.78rem;
		color: var(--color-muted);
	}

	.persona__marcas {
		display: flex;
		gap: 0.3rem;
		flex-wrap: wrap;
	}

	.marca {
		border-radius: 999px;
		padding: 0.15rem 0.55rem;
		font-size: 0.72rem;
		font-weight: 600;
	}

	.marca--corregida {
		background: var(--color-warning-bg);
		color: var(--color-warning);
	}

	.marca--nueva {
		background: var(--color-info-bg);
		color: var(--color-info);
	}

	.marca--fuera {
		background: var(--color-danger-bg);
		color: var(--color-danger);
	}

	.persona__abrir {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		border: 1px solid var(--color-border);
		background: var(--color-surface);
		color: var(--color-muted);
		border-radius: 7px;
		padding: 0.3rem 0.6rem;
		font-size: 0.8rem;
		cursor: pointer;
	}

	.persona__abrir:hover {
		color: var(--color-text);
		border-color: var(--color-border-strong);
	}

	.persona__campos {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
		gap: 0.6rem;
		margin-top: 0.75rem;
		padding-top: 0.75rem;
		border-top: 1px solid var(--color-border);
	}

	.persona__acciones {
		grid-column: 1 / -1;
	}

	.quitar {
		display: inline-flex;
		align-items: center;
		gap: 0.35rem;
		border: none;
		background: none;
		color: var(--color-muted);
		font-size: 0.82rem;
		text-decoration: underline;
		cursor: pointer;
		padding: 0.3rem 0;
	}

	.quitar:hover {
		color: var(--color-danger);
	}

	.agregar {
		width: 100%;
		justify-content: center;
	}

	.hogar__cuenta {
		margin: 0.7rem 0 0;
		font-size: 0.82rem;
		color: var(--color-muted);
	}
</style>
