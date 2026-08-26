// Punto de integración con el backend del Sistema de Gestión del Riesgo.
//
// Hoy son STUBS. Cuando el bot deba consultar o registrar algo del SGR, va acá.
//
// Regla heredada de la referencia y que conviene mantener: **el bot no calcula
// nada por su cuenta**, delega en el servicio que ya usa el resto del sistema.
// Si el bot calculara, tarde o temprano diría un dato distinto al del panel, y
// el ciudadano tendría razón al reclamar.
//
// SOLO LECTURA sobre datos de negocio, salvo que se decida lo contrario de
// forma explícita: un bot que escribe en producción sin identidad probada es
// una superficie de ataque.

/**
 * Consulta al SGR. Sin implementar.
 * @param {string} _phone
 */
export async function consultarSGR(_phone) {
  throw new Error('sin implementar: pendiente de definir los flujos de Gestión del Riesgo');
}

/**
 * Registra un reporte en el SGR. Sin implementar.
 * @param {object} _datos
 */
export async function registrarReporte(_datos) {
  throw new Error('sin implementar: pendiente de definir los flujos de Gestión del Riesgo');
}
