<script lang="ts">
	// Quienes la puerta de la cédula rechazó, pero pueden necesitar ayuda igual.
	//
	// Separada de «Solicitudes ciudadanas» a propósito: ninguna de estas tiene
	// una ficha RUFE detrás todavía, y mezclarlas confundiría los conteos de las
	// dos bandejas. Aquí se decide, caso por caso, si de verdad nace una.

	import { onMount } from 'svelte';
	import { LoaderCircle, TriangleAlert, UserPlus } from '@lucide/svelte';
	import { ApiError } from '$lib/api/client';
	import { sinCensoApi } from '$lib/api/servicios';
	import { fechaHora } from '$lib/formato';

	type Fila = Awaited<ReturnType<typeof sinCensoApi.listar>>['solicitudes'][number];

	let filas = $state<Fila[]>([]);
	let estados = $state<Record<string, string>>({});
	let filtroEstado = $state('');
	let cargando = $state(true);
	let error = $state('');

	onMount(() => void cargar());

	async function cargar() {
		cargando = true;
		error = '';

		try {
			const r = await sinCensoApi.listar(filtroEstado);
			filas = r.solicitudes;
			estados = r.estados;
		} catch (e) {
			error =
				e instanceof ApiError && e.status === 0
					? 'No hay conexión con el servidor. Esta sección consulta datos y necesita señal.'
					: 'No se pudieron cargar las solicitudes.';
		} finally {
			cargando = false;
		}
	}

	function lugar(f: Fila): string {
		return [f.direccion, f.vereda_sector_barrio, f.corregimiento].filter(Boolean).join(' · ') || '—';
	}
</script>

<svelte:head><title>No aparecen en el censo · SGR Jamundí</title></svelte:head>

<div class="tarjeta">
	<h2 class="tarjeta__titulo">Quienes no aparecen en el censo</h2>
	<p class="tarjeta__nota">
		Dejaron sus datos al no encontrar su cédula en el RUFE. Revise cada caso y, si aplica, conviértalo
		en una ficha nueva.
	</p>

	<form class="filtros" onsubmit={(e) => (e.preventDefault(), cargar())}>
		<label>
			<span class="filtros__etiqueta">Estado</span>
			<select bind:value={filtroEstado} onchange={cargar}>
				<option value="">Todos</option>
				{#each Object.entries(estados) as [codigo, etiqueta] (codigo)}
					<option value={codigo}>{etiqueta}</option>
				{/each}
			</select>
		</label>
	</form>

	{#if error}
		<p class="aviso aviso--error" role="alert">
			<TriangleAlert size={15} aria-hidden="true" />
			{error}
		</p>
	{:else if cargando}
		<p class="cargando">
			<LoaderCircle size={18} class="girando" aria-hidden="true" />
			Cargando…
		</p>
	{:else if filas.length === 0}
		<p class="vacio">
			<UserPlus size={26} aria-hidden="true" />
			<span>No hay solicitudes que coincidan.</span>
		</p>
	{:else}
		<p class="cuenta">{filas.length} {filas.length === 1 ? 'solicitud' : 'solicitudes'}</p>

		<div class="tabla-envoltura">
			<table class="tabla">
				<thead>
					<tr>
						<th scope="col">Radicado</th>
						<th scope="col">Nombre</th>
						<th scope="col">Teléfono</th>
						<th scope="col">Ubicación</th>
						<th scope="col">Estado</th>
						<th scope="col">Recibida</th>
					</tr>
				</thead>
				<tbody>
					{#each filas as f (f.id)}
						<tr>
							<td class="numero"><a href="/riesgo/sin-censo/{f.id}">{f.radicado}</a></td>
							<td>{f.nombres} {f.apellidos}</td>
							<td class="numero">{f.telefono}</td>
							<td>{lugar(f)}</td>
							<td>
								<span class="marca" class:marca--convertida={f.estado === 'CONVERTIDA'}
									class:marca--sin={f.estado === 'DESCARTADA'}>
									{estados[f.estado] ?? f.estado}
								</span>
							</td>
							<td class="fecha">{fechaHora(f.creado_en)}</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}
</div>

<style>
	.filtros {
		display: flex;
		flex-wrap: wrap;
		gap: 0.7rem;
		align-items: flex-end;
		margin-bottom: 1rem;
	}

	.filtros label {
		display: flex;
		flex-direction: column;
		gap: 0.25rem;
	}

	.filtros__etiqueta {
		font-size: 0.75rem;
		font-weight: 600;
		color: var(--color-muted);
	}

	.filtros select {
		min-height: 2.5rem;
		padding: 0.4rem 0.6rem;
		border: 1px solid var(--color-border);
		border-radius: 0.45rem;
		background: var(--color-surface);
		color: var(--color-text);
		font-size: 0.9rem;
	}

	.cuenta {
		margin: 0 0 0.6rem;
		font-size: 0.8rem;
		color: var(--color-muted);
	}

	.tabla-envoltura {
		overflow-x: auto;
	}

	.tabla {
		width: 100%;
		border-collapse: collapse;
		font-size: 0.86rem;
	}

	.tabla th,
	.tabla td {
		padding: 0.5rem 0.6rem;
		text-align: left;
		border-bottom: 1px solid var(--color-border);
		vertical-align: top;
	}

	.tabla th {
		font-size: 0.72rem;
		text-transform: uppercase;
		letter-spacing: 0.03em;
		color: var(--color-muted);
	}

	.numero,
	.fecha {
		white-space: nowrap;
		font-variant-numeric: tabular-nums;
	}

	.marca {
		display: inline-block;
		padding: 0.12rem 0.45rem;
		border-radius: 999px;
		background: var(--color-info-bg);
		color: var(--aviso-info-texto);
		font-size: 0.74rem;
		font-weight: 600;
		white-space: nowrap;
	}

	.marca--convertida {
		background: var(--color-success-bg);
		color: var(--color-success);
	}

	.marca--sin {
		background: var(--color-surface-alt);
		color: var(--color-muted);
	}
</style>
