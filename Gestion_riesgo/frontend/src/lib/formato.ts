/** Formatos de fecha en español de Colombia, usados en varias pantallas. */

const ZONA = 'America/Bogota';

export function fechaHora(valor: string | null | undefined): string {
	if (!valor) return '—';
	const d = new Date(valor.includes('T') ? valor : valor.replace(' ', 'T'));
	if (Number.isNaN(d.getTime())) return '—';

	return d.toLocaleString('es-CO', {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
		timeZone: ZONA
	});
}

export function soloFecha(valor: string | null | undefined): string {
	if (!valor) return '—';
	const d = new Date(valor.includes('T') ? valor : valor.replace(' ', 'T'));
	if (Number.isNaN(d.getTime())) return '—';

	return d.toLocaleDateString('es-CO', {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: ZONA
	});
}

/** "hace 3 días", para la línea de tiempo de actualizaciones. */
export function haceCuanto(valor: string | null | undefined): string {
	if (!valor) return '';
	const d = new Date(valor);
	if (Number.isNaN(d.getTime())) return '';

	const segundos = Math.floor((Date.now() - d.getTime()) / 1000);
	if (segundos < 60) return 'hace instantes';

	const tramos: [number, Intl.RelativeTimeFormatUnit][] = [
		[60, 'minute'],
		[3600, 'hour'],
		[86400, 'day'],
		[604800, 'week'],
		[2592000, 'month'],
		[31536000, 'year']
	];

	let unidad: Intl.RelativeTimeFormatUnit = 'minute';
	let divisor = 60;
	for (const [limite, u] of tramos) {
		if (segundos >= limite) {
			divisor = limite;
			unidad = u;
		}
	}

	const rtf = new Intl.RelativeTimeFormat('es-CO', { numeric: 'auto' });

	return rtf.format(-Math.floor(segundos / divisor), unidad);
}
