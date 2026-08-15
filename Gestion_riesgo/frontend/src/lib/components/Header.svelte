<script lang="ts">
	import logo from '$lib/assets/logo-jamundi.svg';

	let { asOf, total, hogares }: { asOf: string; total: number; hogares: number } = $props();
</script>

<header class="masthead">
	<div class="masthead-top">
		<img class="seal" src={logo} alt="Escudo de la Alcaldía de Jamundí" width="42" height="42" />
		<div>
			<p class="eyebrow">Alcaldía Municipal de Jamundí · Gestión del Riesgo</p>
			<h1>Tablero RUFE — Sismo Jamundí</h1>
		</div>
	</div>
	<div class="masthead-sub">
		<span>Evento: <b>Sismo, 10 de agosto de 2026</b></span>
		<span>Corte del registro: <b>{asOf}</b></span>
		<span>Personas registradas: <b>{total.toLocaleString('es-CO')}</b></span>
		<span>Hogares registrados: <b>{hogares.toLocaleString('es-CO')}</b></span>
	</div>
</header>

<style>
	.masthead {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding-bottom: 14px;
		border-bottom: 1px solid var(--color-border);
	}
	.masthead-top {
		display: flex;
		align-items: center;
		gap: 12px;
	}
	.seal {
		flex: none;
		border-radius: var(--radius-sm);
		background: var(--color-surface);
		border: 1px solid var(--color-border);
		box-shadow: var(--shadow-sm);
		padding: 3px;
	}
	.eyebrow {
		margin: 0 0 2px;
		font-size: 11px;
		font-weight: 700;
		letter-spacing: 0.09em;
		text-transform: uppercase;
		color: var(--color-primary-dark);
	}
	h1 {
		margin: 0;
		font-size: clamp(20px, 5.4vw, 28px);
		font-weight: 800;
		letter-spacing: -0.01em;
		color: var(--color-text);
		text-wrap: balance;
	}
	.masthead-sub {
		font-size: 12.5px;
		color: var(--color-muted);
		display: flex;
		flex-wrap: wrap;
		gap: 6px 14px;
	}
	.masthead-sub b {
		color: var(--color-text);
		font-weight: 600;
	}
</style>
