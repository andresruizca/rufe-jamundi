// La aplicación se comporta como SPA: qué ve cada persona depende de su sesión,
// que solo existe en el navegador. Pre-renderizar en el build produciría HTML
// de un usuario que no existe, así que se desactivan SSR y prerender y se sirve
// el 200.html de respaldo (ver `fallback` en vite.config.ts).
export const ssr = false;
export const prerender = false;
export const trailingSlash = 'never';
