/**
 * Hoja de cálculo del RUFE (consolidado del sismo, FR-1703-SMD-69),
 * compartida como "cualquiera con el enlace puede ver". El export directo a
 * CSV de Google Sheets acepta fetch() de cualquier origen (confirmado:
 * responde `access-control-allow-origin` permisivo), así que el navegador
 * puede leerlo directamente sin backend propio.
 */
export const SHEET_ID = '1bBOHvSd9taQohbtF-c6l3NVvV6KHe0gK';
export const SHEET_CSV_URL = `https://docs.google.com/spreadsheets/d/${SHEET_ID}/export?format=csv`;
