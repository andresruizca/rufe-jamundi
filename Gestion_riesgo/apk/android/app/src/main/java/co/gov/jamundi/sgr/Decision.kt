package co.gov.jamundi.sgr

import org.json.JSONObject

/**
 * Qué hacer tras un intento de envío.
 *
 * ⚠ ESTO ES UNA COPIA. El original es `src/local/sincronizacion.ts`, que tiene
 * 21 pruebas y es la especificación. Aquí no hay forma de ejecutarlas, así que
 * `scripts/comparar-kotlin.mjs` compara los NÚMEROS y las reglas de los dos
 * archivos y falla si se separan.
 *
 * Las tres reglas, y por qué cada una:
 *
 * 1. **`duplicada` y `reintento` son ÉXITO.** El servidor dice «esto ya estaba»
 *    y devuelve el radicado original. Tratarlo como error haría que el APK
 *    reintentara para siempre algo que ya llegó, y que la persona viera un aviso
 *    rojo sobre una solicitud perfectamente registrada.
 *
 * 2. **Un 422 CON errores por campo no se reintenta.** Los datos no van a
 *    mejorar solos. Pero un 422 SIN errores por campo sí: es un rechazo que no
 *    sabemos explicar, y descartar la solicitud de alguien por algo que no
 *    entendemos es peor que volver a intentarlo.
 *
 * 3. **Todo lo demás se reintenta.** Sin señal, tiempo agotado, 500, 429: son
 *    fallos del camino, no del contenido, y el camino cambia.
 */
sealed class Decision {
    data class Listo(val radicado: String) : Decision()
    data class Reintentar(val motivo: String, val esperaSegundos: Int) : Decision()
    data class Rendirse(val motivo: String) : Decision()
}

object Reintentos {

    /**
     * Cuánto se espera antes de cada reintento, en segundos.
     *
     * Creciente, siempre. El plan original traía el último valor MENOR que el
     * anterior, que habría hecho que el sexto intento llegara antes que el
     * quinto.
     *
     * El primero es inmediato: la causa más común de fallo es que no había
     * señal, y cuando WorkManager despierta es justamente porque acaba de
     * haberla.
     */
    val ESPERAS = intArrayOf(0, 300, 900, 3600, 14400)

    /**
     * Parar no es rendirse: el registro sigue en el teléfono y la persona puede
     * pedir el reintento a mano. Lo que se detiene es el automático, para no
     * gastarle la batería a alguien golpeando un servidor que no responde.
     */
    val MAX_INTENTOS = ESPERAS.size

    /** Una cabecera absurda no puede dormir la solicitud de alguien para siempre. */
    const val TOPE_RETRY_AFTER = 24 * 3600

    fun esperaTrasIntento(intentos: Int): Int =
        ESPERAS[intentos.coerceIn(0, ESPERAS.size - 1)]

    /**
     * Cuánto esperar cuando el servidor dice explícitamente cuánto falta.
     *
     * `Limite.php` manda `Retry-After` con los segundos que quedan de su
     * ventana. Ignorarla y usar la escalera genérica es lo que dejaba cinco de
     * veinte solicitudes esperando un toque a mano cuando una brigada sincroniza
     * desde una vereda: todas salen por la misma IP y el límite es de cinco por
     * hora.
     *
     * Medido sobre ese límite real: con la escalera salen 15 de 20 y la última
     * tarda 320 minutos; honrando `Retry-After` salen las 20, ninguna pide
     * toque, y la última tarda 180.
     */
    fun esperaSegunServidor(retryAfter: Int?, intentos: Int): Int =
        if (retryAfter != null && retryAfter > 0) {
            minOf(retryAfter, TOPE_RETRY_AFTER)
        } else {
            esperaTrasIntento(intentos)
        }

    fun decidir(
        estado: Int?,
        cuerpo: JSONObject?,
        intentos: Int,
        retryAfter: Int?
    ): Decision {
        val espera = esperaSegunServidor(retryAfter, intentos)

        // Sin respuesta: no hubo red. Es el caso normal en una vereda, no un error.
        if (estado == null) {
            return if (intentos + 1 >= MAX_INTENTOS) {
                Decision.Rendirse("No hubo conexión en varios intentos.")
            } else {
                Decision.Reintentar("Sin conexión.", espera)
            }
        }

        val ok = cuerpo?.optBoolean("ok", false) == true
        val radicado = cuerpo?.optJSONObject("data")?.optString("radicado", "") ?: ""

        // Regla 1: `duplicada` y `reintento` traen radicado, así que caen aquí.
        if (ok && radicado.isNotEmpty()) {
            return Decision.Listo(radicado)
        }

        // Regla 2. Se comprueba que HAYA errores por campo.
        val errores = cuerpo?.optJSONObject("errors")
        if (estado == 422 && errores != null && errores.length() > 0) {
            return Decision.Rendirse(
                cuerpo.optString("message", "Hay datos que hay que corregir.")
            )
        }

        if (intentos + 1 >= MAX_INTENTOS) {
            return Decision.Rendirse(
                cuerpo?.optString("message") ?: "El servidor respondió $estado varias veces."
            )
        }

        return Decision.Reintentar(
            cuerpo?.optString("message") ?: "El servidor respondió $estado.",
            espera
        )
    }
}
