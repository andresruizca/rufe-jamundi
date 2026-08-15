<script lang="ts">
	import { LogOut } from '@lucide/svelte';
	import logo from '$lib/assets/logo-jamundi.svg';
	import { menuParaRol, esActivo, ETIQUETA_ROL, type Seccion } from '$lib/navigation';
	import { sesion } from '$lib/stores/sesion.svelte';

	type Props = {
		rutaActual: string;
		abierto?: boolean;
		onNavegar?: () => void;
		onSalir?: () => void;
	};

	let { rutaActual, abierto = false, onNavegar, onSalir }: Props = $props();

	const secciones = $derived<Seccion[]>(menuParaRol(sesion.rol));

	const iniciales = $derived(
		(sesion.usuario?.nombre ?? '?')
			.split(/\s+/)
			.slice(0, 2)
			.map((p) => p.charAt(0).toUpperCase())
			.join('')
	);
</script>

<aside class="menu" class:abierto aria-label="Menú principal">
	<div class="menu__marca">
		<img class="menu__logo" src={logo} alt="" aria-hidden="true" />
		<div>
			<div class="menu__titulo">Gestión del Riesgo</div>
			<div class="menu__subtitulo">Alcaldía de Jamundí</div>
		</div>
	</div>

	<nav class="menu__nav">
		{#each secciones as seccion (seccion.type === 'group' ? seccion.group.id : seccion.item.id)}
			{#if seccion.type === 'item'}
				{@const Icono = seccion.item.icon}
				<a
					class="menu__enlace"
					href={seccion.item.href}
					aria-current={esActivo(seccion.item, rutaActual) ? 'page' : undefined}
					onclick={onNavegar}
				>
					{#if Icono}<Icono size={18} aria-hidden="true" />{/if}
					<span>{seccion.item.label}</span>
				</a>
			{:else}
				{@const IconoGrupo = seccion.group.icon}
				<div class="menu__grupo-titulo">
					{#if IconoGrupo}<IconoGrupo size={14} aria-hidden="true" />{/if}
					<span>{seccion.group.label}</span>
				</div>
				{#each seccion.items as item (item.id)}
					{@const Icono = item.icon}
					<a
						class="menu__enlace menu__enlace--hijo"
						href={item.href}
						aria-current={esActivo(item, rutaActual) ? 'page' : undefined}
						onclick={onNavegar}
					>
						{#if Icono}<Icono size={17} aria-hidden="true" />{/if}
						<span>{item.label}</span>
					</a>
				{/each}
			{/if}
		{/each}
	</nav>

	{#if sesion.usuario}
		<div class="menu__pie">
			<div class="menu__usuario">
				<div class="menu__avatar" aria-hidden="true">{iniciales}</div>
				<div>
					<div class="menu__usuario-nombre">{sesion.usuario.nombre}</div>
					<div class="menu__usuario-rol">{ETIQUETA_ROL[sesion.usuario.rol]}</div>
				</div>
			</div>
			<button class="menu__salir" type="button" onclick={onSalir}>
				<LogOut size={15} aria-hidden="true" />
				Cerrar sesión
			</button>
		</div>
	{/if}
</aside>
