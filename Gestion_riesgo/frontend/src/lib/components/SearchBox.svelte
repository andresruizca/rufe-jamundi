<script lang="ts">
	let { query = $bindable(), placeholder }: { query: string; placeholder: string } = $props();
	let inputEl: HTMLInputElement;

	function clear() {
		query = '';
		inputEl?.focus();
	}
</script>

<div class="search-box" class:has-value={query.length > 0}>
	<svg
		class="icon-search"
		width="15"
		height="15"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		stroke-width="2"
		stroke-linecap="round"
		stroke-linejoin="round"
		aria-hidden="true"
	>
		<circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" />
	</svg>
	<label for="barrio-search" class="sr-only">Buscar barrio o vereda</label>
	<input
		id="barrio-search"
		bind:this={inputEl}
		bind:value={query}
		type="text"
		{placeholder}
		autocomplete="off"
	/>
	<button class="clear" type="button" aria-label="Limpiar búsqueda" onclick={clear}>✕</button>
</div>

<style>
	.search-box {
		position: relative;
		flex: 1;
		min-width: 0;
	}
	input {
		width: 100%;
		font: inherit;
		font-size: 14px;
		padding: 9px 34px;
		border-radius: 9px;
		border: 1px solid var(--color-border-strong);
		background: var(--color-bg);
		color: var(--color-text);
	}
	input:focus-visible {
		outline: 2px solid var(--color-primary);
		outline-offset: 1px;
	}
	.icon-search {
		position: absolute;
		left: 10px;
		top: 50%;
		transform: translateY(-50%);
		color: var(--color-muted);
	}
	.clear {
		position: absolute;
		right: 6px;
		top: 50%;
		transform: translateY(-50%);
		border: 0;
		background: var(--color-surface-alt);
		color: var(--color-muted);
		width: 22px;
		height: 22px;
		border-radius: 50%;
		cursor: pointer;
		font-size: 13px;
		line-height: 1;
		display: none;
		align-items: center;
		justify-content: center;
	}
	.has-value .clear {
		display: flex;
	}
	.sr-only {
		position: absolute;
		width: 1px;
		height: 1px;
		padding: 0;
		margin: -1px;
		overflow: hidden;
		clip: rect(0, 0, 0, 0);
		white-space: nowrap;
		border: 0;
	}
</style>
