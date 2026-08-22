<script lang="ts">
	// Dónde el ciudadano ve si su solicitud ya salió.
	//
	// Es la pantalla que justifica media aplicación. Alguien llena el formulario
	// en una vereda, no pasa nada visible, y se queda sin saber si aquello sirvió
	// de algo. Sin esta pantalla la duda dura días; con ella, la respuesta cabe
	// en un renglón.
	//
	// Tres decisiones de redacción, y las tres son sobre no mentir:
	//
	//  • Nunca se dice «sincronizando», «cola» ni «pendiente de envío». Quien
	//    abre esto perdió parte de su casa; el vocabulario del programador no le
	//    ayuda a saber si tiene que volver a hacer algo.
	//  • El radicado se muestra grande y se puede copiar: es lo ÚNICO que se
	//    lleva, y lo va a tener que dictar por teléfono.
	//  • El aviso de no desinstalar va arriba y en amarillo cuando hay algo sin
	//    salir. Android no avisa al desinstalar, así que si esta pantalla no lo
	//    dice, nadie lo dice.

	import { onMount } from 'svelte';
	import { Check, Clock, Copy, RotateCw, TriangleAlert } from '@lucide/svelte';
	import { listar, reintentar, cuantasEsperan, type RegistroGuardado } from '$local/registros';
	import { comoSeDice, type EstadoRegistro } from '$local/sincronizacion';

	let registros = $state<RegistroGuardado[]>([]);
	let esperando = $state(0);
	let cargando = $state(true);
	let error = $state('');
	let copiado = $state<string | null>(null);

	onMount(cargar);

	async function cargar() {
		cargando = true;
		error = '';

		try {
			registros = await listar();
			esperando = await cuantasEsperan();
		} catch (e) {
			error = 'No se pudieron leer sus registros en este teléfono.';
		} finally {
			cargando = false;
		}
	}

	async function volverAIntentar(id: string) {
		await reintentar(id);
		await cargar();
	}

	async function copiar(radicado: string) {
		try {
			await navigator.clipboard.writeText(radicado);
			copiado = radicado;
			setTimeout(() => (copiado = null), 2500);
		} catch {
			// Sin portapapeles: el número está a la vista y se puede leer en voz
			// alta, que es para lo que sirve.
		}
	}

	function fecha(iso: string): string {
		return new Date(iso).toLocaleString('es-CO', {
			dateStyle: 'medium',
			timeStyle: 'short'
		});
	}

	function proximo(r: RegistroGuardado): Date | null {
		return r.proximo_intento_en ? new Date(r.proximo_intento_en) : null;
	}
</script>

<svelte:head><title>Mis registros</title></svelte:head>

<main>
	<h1>Mis registros</h1>

	{#if esperando > 0}
		<!--
			Android no pregunta nada al desinstalar. Si esto no lo dice, alguien
			borra la aplicación creyendo que ya mandó su solicitud y se lleva por
			delante fotos que no volverá a tomar.
		-->
		<p class="aviso" role="status">
			<TriangleAlert size={17} aria-hidden="true" />
			<span>
				{esperando === 1
					? 'Tiene 1 registro sin enviar.'
					: `Tiene ${esperando} registros sin enviar.`}
				Se enviarán solos cuando haya internet. <strong>No desinstale la aplicación</strong> hasta
				que aparezcan como enviados.
			</span>
		</p>
	{/if}

	{#if cargando}
		<p class="tenue">Leyendo…</p>
	{:else if error}
		<p class="aviso aviso--malo" role="alert">{error}</p>
	{:else if registros.length === 0}
		<p class="tenue">Todavía no ha registrado ninguna vivienda.</p>
	{:else}
		<ul class="lista">
			{#each registros as r (r.id)}
				<li class="ficha" class:ficha--lista={r.estado === 'SINCRONIZADO'}>
					<p class="ficha__nombre">{r.nombre_completo}</p>
					<p class="ficha__lugar">{r.direccion}</p>
					<p class="ficha__cuando">
						Registrada el {fecha(r.creado_en)}
						{#if r.adjuntos > 0}
							· {r.adjuntos}
							{r.adjuntos === 1 ? 'archivo' : 'archivos'}
						{/if}
					</p>

					<p class="ficha__estado">
						{#if r.estado === 'SINCRONIZADO'}
							<Check size={16} aria-hidden="true" />
						{:else if r.estado === 'ERROR' || r.estado === 'ERROR_VALIDACION'}
							<TriangleAlert size={16} aria-hidden="true" />
						{:else}
							<Clock size={16} aria-hidden="true" />
						{/if}
						<span>
							{comoSeDice(r.estado as EstadoRegistro, {
								radicado: null,
								proximoIntento: proximo(r)
							})}
						</span>
					</p>

					{#if r.estado === 'SINCRONIZADO' && r.radicado}
						<!-- El radicado es lo único que se lleva de todo esto. Grande,
						     y con un botón para copiarlo: lo va a tener que dictar. -->
						<div class="radicado">
							<code>{r.radicado}</code>
							<button type="button" onclick={() => copiar(r.radicado!)}>
								<Copy size={14} aria-hidden="true" />
								{copiado === r.radicado ? 'Copiado' : 'Copiar'}
							</button>
						</div>
					{/if}

					{#if r.error_ultimo && r.estado !== 'SINCRONIZADO'}
						<p class="ficha__motivo">{r.error_ultimo}</p>
					{/if}

					{#if r.estado === 'ERROR' || r.estado === 'ERROR_VALIDACION'}
						<button class="reintentar" type="button" onclick={() => volverAIntentar(r.id)}>
							<RotateCw size={14} aria-hidden="true" />
							Reintentar ahora
						</button>
					{/if}
				</li>
			{/each}
		</ul>
	{/if}
</main>

<style>
	main {
		padding: 1.4rem 1.1rem 3rem;
		color: #16243f;
	}

	h1 {
		margin: 0 0 1rem;
		font-size: 1.25rem;
	}

	.tenue {
		color: #647189;
		font-size: 0.9rem;
	}

	.aviso {
		display: flex;
		align-items: flex-start;
		gap: 0.5rem;
		margin: 0 0 1.2rem;
		padding: 0.8rem 0.9rem;
		border-radius: 10px;
		background: #fcefd9;
		border: 1px solid #e3b455;
		font-size: 0.85rem;
		line-height: 1.5;
	}

	.aviso--malo {
		background: #fbe7e4;
		border-color: #d23b2c;
	}

	.lista {
		list-style: none;
		margin: 0;
		padding: 0;
		display: grid;
		gap: 0.8rem;
	}

	.ficha {
		padding: 0.9rem;
		border: 1px solid #e1e8f2;
		border-left: 4px solid #c9d5ea;
		border-radius: 12px;
		background: #fff;
	}

	.ficha--lista {
		border-left-color: #1e8c5e;
	}

	.ficha__nombre {
		margin: 0;
		font-weight: 600;
		font-size: 0.95rem;
	}

	.ficha__lugar {
		margin: 0.15rem 0 0;
		font-size: 0.85rem;
		line-height: 1.35;
	}

	.ficha__cuando {
		margin: 0.3rem 0 0;
		font-size: 0.75rem;
		color: #647189;
	}

	.ficha__estado {
		display: flex;
		align-items: flex-start;
		gap: 0.4rem;
		margin: 0.7rem 0 0;
		font-size: 0.85rem;
		line-height: 1.4;
	}

	.ficha__motivo {
		margin: 0.35rem 0 0;
		font-size: 0.78rem;
		color: #647189;
	}

	.radicado {
		display: flex;
		align-items: center;
		gap: 0.6rem;
		flex-wrap: wrap;
		margin-top: 0.6rem;
		padding-top: 0.6rem;
		border-top: 1px solid #e1e8f2;
	}

	.radicado code {
		font-family: ui-monospace, monospace;
		font-size: 1.05rem;
		font-weight: 700;
		letter-spacing: 0.03em;
	}

	.radicado button,
	.reintentar {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		padding: 0.4rem 0.7rem;
		border: 1px solid #c9d5ea;
		border-radius: 8px;
		background: none;
		font: inherit;
		font-size: 0.8rem;
		color: #16243f;
	}

	.reintentar {
		margin-top: 0.7rem;
		min-height: 40px;
	}
</style>
