<script lang="ts">
	// Detalle de una solicitud de quien no aparece en el censo.
	//
	// Aquí se decide si el caso es real y, si lo es, se convierte en una ficha
	// RUFE nueva. «Convertir» no llena esa ficha sola —eso sigue siendo trabajo
	// de campo, de personas y daños que nadie más puede levantar—: arma un
	// borrador con lo que la persona ya dejó (nombre, teléfono, ubicación) y
	// lleva al funcionario a `/riesgo/reportar` a terminarlo.
	//
	// Marcar la solicitud como CONVERTIDA es un paso aparte, después: solo
	// cuando la ficha nueva ya existe y se sabe su radicado.

	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { ArrowLeft, FilePlus2, LoaderCircle, TriangleAlert } from '@lucide/svelte';
	import { ApiError } from '$lib/api/client';
	import { rufeApi, sinCensoApi } from '$lib/api/servicios';
	import { fechaHora } from '$lib/formato';
	import { crearBorradorDesdeSolicitud } from '$lib/rufe-form/borrador.svelte';
	import { formularioVacio, personaVacia } from '$lib/rufe-form/esquema';

	type Solicitud = Awaited<ReturnType<typeof sinCensoApi.ver>>['solicitud'];

	const id = $derived(Number(page.params.id));

	let solicitud = $state<Solicitud | null>(null);
	let estados = $state<Record<string, string>>({});
	let cargando = $state(true);
	let error = $state<string | null>(null);
	let exito = $state<string | null>(null);

	let nuevoEstado = $state('');
	let guardandoEstado = $state(false);

	let radicadoRufe = $state('');
	let vinculando = $state(false);
	let erroresVinculo = $state<Record<string, string>>({});

	let creandoBorrador = $state(false);

	onMount(cargar);

	async function cargar() {
		cargando = true;
		error = null;

		try {
			const r = await sinCensoApi.ver(id);
			solicitud = r.solicitud;
			estados = r.estados;
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudo cargar la solicitud.';
		} finally {
			cargando = false;
		}
	}

	function lugar(s: Solicitud): string {
		return (
			[s.direccion, s.vereda_sector_barrio, s.corregimiento].filter(Boolean).join(' · ') || '—'
		);
	}

	async function guardarEstado() {
		if (!nuevoEstado || guardandoEstado) return;

		guardandoEstado = true;
		error = null;
		exito = null;

		try {
			await sinCensoApi.cambiarEstado(id, nuevoEstado);
			exito = 'El estado se actualizó.';
			nuevoEstado = '';
			await cargar();
		} catch (e) {
			error = e instanceof ApiError ? e.message : 'No se pudo actualizar el estado.';
		} finally {
			guardandoEstado = false;
		}
	}

	async function vincularConvertida() {
		if (vinculando) return;

		vinculando = true;
		error = null;
		exito = null;
		erroresVinculo = {};

		try {
			await sinCensoApi.cambiarEstado(id, 'CONVERTIDA', radicadoRufe.trim());
			exito = 'La solicitud quedó vinculada a esa ficha.';
			radicadoRufe = '';
			await cargar();
		} catch (e) {
			if (e instanceof ApiError) {
				error = e.message;
				erroresVinculo = e.errors;
			} else {
				error = 'No se pudo vincular la ficha.';
			}
		} finally {
			vinculando = false;
		}
	}

	/**
	 * Arma un borrador de RUFE con lo que ya se sabe y lleva al formulario.
	 *
	 * La solicitud pide nombres y apellidos por separado —los mismos dos
	 * campos de `Persona`— justamente para que esto sea una copia directa y
	 * no una adivinanza de dónde corta el nombre completo.
	 */
	async function convertirARufe() {
		if (!solicitud || creandoBorrador) return;

		creandoBorrador = true;
		error = null;

		try {
			const catalogos = await rufeApi.catalogos();
			const datos = formularioVacio();

			datos.zona = solicitud.zona;
			datos.corregimiento = solicitud.corregimiento ?? '';
			datos.vereda_sector_barrio = solicitud.vereda_sector_barrio ?? '';
			datos.direccion = solicitud.direccion ?? '';
			datos.contacto_telefono = solicitud.telefono;

			const jefe = personaVacia(catalogos.parentesco_jefe);
			jefe.nombres = solicitud.nombres;
			jefe.apellidos = solicitud.apellidos;
			jefe.numero_documento = solicitud.documento ?? '';
			jefe.telefono = solicitud.telefono;
			datos.personas = [jefe];

			if (solicitud.descripcion) {
				datos.observaciones = `Motivo reportado al no aparecer en el censo: ${solicitud.descripcion}`;
			}

			crearBorradorDesdeSolicitud(datos);
			await goto('/riesgo/reportar');
		} catch {
			error = 'No se pudo preparar la ficha. Intente de nuevo.';
			creandoBorrador = false;
		}
	}
</script>

<svelte:head><title>Solicitud · SGR Jamundí</title></svelte:head>

<a class="volver" href="/riesgo/sin-censo">
	<ArrowLeft size={15} aria-hidden="true" />
	Volver a la lista
</a>

{#if cargando}
	<p class="cargando"><LoaderCircle size={18} class="girando" aria-hidden="true" /> Cargando…</p>
{:else if error && !solicitud}
	<p class="aviso aviso--error" role="alert">{error}</p>
{:else if solicitud}
	{#if error}<p class="aviso aviso--error" role="alert">{error}</p>{/if}
	{#if exito}<p class="aviso aviso--exito" role="status">{exito}</p>{/if}

	<div class="tarjeta">
		<header class="encabezado">
			<div>
				<p class="numero">{solicitud.radicado}</p>
				<h1 class="tarjeta__titulo">{solicitud.nombres} {solicitud.apellidos}</h1>
				<p class="fecha">Recibida el {fechaHora(solicitud.creado_en)}</p>
			</div>
			<span class="pastilla">{estados[solicitud.estado] ?? solicitud.estado}</span>
		</header>

		<dl class="datos">
			<div><dt>Teléfono</dt><dd>{solicitud.telefono}</dd></div>
			<div><dt>Cédula que escribió</dt><dd>{solicitud.documento ?? 'No la dio o no era clara'}</dd></div>
			<div><dt>Zona</dt><dd>{solicitud.zona === 'RURAL' ? 'Rural' : 'Urbana'}</dd></div>
			<div><dt>Ubicación</dt><dd>{lugar(solicitud)}</dd></div>
		</dl>

		{#if solicitud.descripcion}
			<div class="relato">
				<h2 class="relato__titulo">Qué dijo que le pasó</h2>
				<p>{solicitud.descripcion}</p>
			</div>
		{/if}

		{#if solicitud.estado === 'CONVERTIDA'}
			<p class="aviso aviso--exito">
				Convertida en la ficha
				{#if solicitud.rufe_reporte_id}
					<a href="/riesgo/reportes/{solicitud.rufe_reporte_id}">{solicitud.rufe_radicado}</a>
				{:else}
					{solicitud.rufe_radicado}
				{/if}.
			</p>
		{:else}
			<section class="accion">
				<h2 class="accion__titulo">¿El caso es real?</h2>
				<p class="accion__ayuda">
					Arma una ficha RUFE nueva con el nombre, el teléfono y la ubicación que ya dejó. Lo
					demás —personas del hogar, daños, fotos— se completa en la visita o por teléfono.
				</p>
				<button
					type="button"
					class="boton boton--principal"
					disabled={creandoBorrador}
					onclick={convertirARufe}
				>
					{#if creandoBorrador}
						<LoaderCircle size={15} class="girando" aria-hidden="true" />
					{:else}
						<FilePlus2 size={15} aria-hidden="true" />
					{/if}
					Convertir a ficha RUFE
				</button>

				<h3 class="accion__subtitulo">Cambiar estado</h3>
				<div class="fila-accion">
					<select bind:value={nuevoEstado} disabled={guardandoEstado}>
						<option value="">Elegir…</option>
						{#each Object.entries(estados).filter(([c]) => c !== 'CONVERTIDA') as [codigo, etiqueta] (codigo)}
							<option value={codigo}>{etiqueta}</option>
						{/each}
					</select>
					<button
						type="button"
						class="boton boton--suave"
						disabled={!nuevoEstado || guardandoEstado}
						onclick={guardarEstado}
					>
						Guardar
					</button>
				</div>

				<h3 class="accion__subtitulo">Ya la convirtió y tiene el radicado de la ficha</h3>
				<div class="fila-accion">
					<input
						class="campo__control"
						bind:value={radicadoRufe}
						placeholder="RUFE-2026-XXXXXXXX"
						disabled={vinculando}
					/>
					<button
						type="button"
						class="boton boton--suave"
						disabled={!radicadoRufe.trim() || vinculando}
						onclick={vincularConvertida}
					>
						Vincular y marcar convertida
					</button>
				</div>
				{#if erroresVinculo.rufe_radicado}
					<span class="campo__error" role="alert">{erroresVinculo.rufe_radicado}</span>
				{/if}
			</section>
		{/if}
	</div>
{/if}

<style>
	.volver {
		display: inline-flex;
		align-items: center;
		gap: 0.4rem;
		margin-bottom: 0.8rem;
		font-size: 0.86rem;
		color: var(--color-muted);
		text-decoration: none;
	}

	.encabezado {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 1rem;
		margin-bottom: 1rem;
	}

	.numero {
		margin: 0;
		font-size: 0.8rem;
		color: var(--color-muted);
		font-variant-numeric: tabular-nums;
	}

	.fecha {
		margin: 0.15rem 0 0;
		font-size: 0.82rem;
		color: var(--color-muted);
	}

	.pastilla {
		display: inline-block;
		padding: 0.2rem 0.6rem;
		border-radius: 999px;
		background: var(--color-info-bg);
		color: var(--aviso-info-texto);
		font-size: 0.78rem;
		font-weight: 600;
		white-space: nowrap;
	}

	.datos {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
		gap: 0.8rem;
		margin: 0 0 1rem;
	}

	.datos dt {
		font-size: 0.72rem;
		text-transform: uppercase;
		letter-spacing: 0.03em;
		color: var(--color-muted);
	}

	.datos dd {
		margin: 0.1rem 0 0;
	}

	.relato {
		margin: 0 0 1rem;
		padding: 0.8rem;
		border-radius: 0.6rem;
		background: var(--color-surface-alt);
	}

	.relato__titulo {
		margin: 0 0 0.3rem;
		font-size: 0.85rem;
	}

	.relato p {
		margin: 0;
		white-space: pre-wrap;
	}

	.accion {
		border-top: 1px solid var(--color-border);
		padding-top: 1rem;
	}

	.accion__titulo {
		margin: 0 0 0.3rem;
		font-size: 1rem;
	}

	.accion__ayuda {
		margin: 0 0 0.8rem;
		font-size: 0.86rem;
		color: var(--color-muted);
	}

	.accion__subtitulo {
		margin: 1.2rem 0 0.4rem;
		font-size: 0.82rem;
		color: var(--color-muted);
	}

	.fila-accion {
		display: flex;
		flex-wrap: wrap;
		gap: 0.6rem;
	}

	.fila-accion select,
	.fila-accion input {
		min-height: 2.4rem;
		padding: 0.4rem 0.6rem;
		border: 1px solid var(--color-border);
		border-radius: 0.45rem;
		background: var(--color-surface);
		color: var(--color-text);
		font-size: 0.9rem;
	}

	.fila-accion input {
		flex: 1 1 14rem;
	}
</style>
