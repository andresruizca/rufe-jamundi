<script lang="ts">
	import '$lib/theme.css';
	import logo from '$lib/assets/logo-jamundi.svg';

	let { children } = $props();
</script>

<svelte:head>
	<link rel="icon" href={logo} />
</svelte:head>

{@render children()}

<style>
	:global(html) {
		background: var(--color-bg);
	}
	:global(body) {
		margin: 0;
		background: var(--color-bg);
		color: var(--color-text);
		font-family: var(--font-sans);
		font-size: 14px;
		line-height: 1.45;
		-webkit-font-smoothing: antialiased;
	}
	:global(*) {
		box-sizing: border-box;
	}
	:global(h1, h2, h3) {
		text-wrap: balance;
	}
	:global(a) {
		color: var(--color-primary-dark);
	}
	:global(:focus-visible) {
		outline: 2px solid var(--color-primary);
		outline-offset: 2px;
	}
</style>
