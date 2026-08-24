package co.gov.jamundi.sgr

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import org.json.JSONObject
import java.io.File
import java.util.concurrent.TimeUnit

/**
 * Manda al servidor las solicitudes que esperan en el teléfono.
 *
 * Corre con la aplicación CERRADA. Esa es toda la razón de que exista Kotlin
 * aquí: el ciudadano llena el formulario en una vereda, guarda, cierra y se
 * olvida. Cuando el teléfono ve señal —al día siguiente, camino al pueblo—
 * Android despierta esto y la solicitud sale sola.
 *
 * ── El orden importa, y no es negociable ────────────────────────────────────
 *
 * Fotos y videos ANTES del formulario. El paso 5 adopta los archivos de la
 * carga: si el formulario sale primero, los archivos quedan huérfanos y la purga
 * se los lleva en dos horas. Y los trozos de un video, TODOS antes del
 * formulario: uno incompleto el servidor lo BORRA.
 *
 * ── Lo que NO hace ──────────────────────────────────────────────────────────
 *
 * No borra el registro al terminar. Lo marca `SINCRONIZADO` y guarda el
 * radicado, porque eso es lo único que la familia se lleva y lo va a tener que
 * dictar por teléfono. Lo que sí se borra son los ARCHIVOS: son fotos de cédula
 * y de la casa de alguien, y una vez en el servidor no tienen por qué seguir en
 * un aparato que se presta y se pierde.
 */
class SyncWorker(
    contexto: Context,
    parametros: WorkerParameters
) : CoroutineWorker(contexto, parametros) {

    companion object {
        private const val TRABAJO = "sgr-sincronizar"

        /**
         * Android no ejecuta trabajo periódico más seguido que esto. Pedir menos
         * no lo acelera: lo redondea en silencio.
         */
        private const val CADA_MINUTOS = 15L

        fun programar(contexto: Context) {
            val condiciones = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                // En una emergencia no se espera a que el teléfono cargue. La
                // solicitud pesa poco y la batería es de quien la necesita.
                .setRequiresBatteryNotLow(false)
                .setRequiresCharging(false)
                .build()

            val trabajo = PeriodicWorkRequestBuilder<SyncWorker>(
                CADA_MINUTOS, TimeUnit.MINUTES
            ).setConstraints(condiciones).build()

            // KEEP y no REPLACE: con REPLACE, cada arranque de la aplicación
            // reiniciaría el reloj y en un teléfono que se abre a menudo el
            // trabajo periódico no llegaría a ejecutarse nunca.
            WorkManager.getInstance(contexto).enqueueUniquePeriodicWork(
                TRABAJO,
                ExistingPeriodicWorkPolicy.KEEP,
                trabajo
            )
        }
    }

    override suspend fun doWork(): Result {
        if (!BaseDatos.existe(applicationContext)) return Result.success()

        val db = BaseDatos.abrir(applicationContext)

        try {
            val base = ajuste(db, "api_base") ?: return Result.success()
            val dispositivo = ajuste(db, "dispositivo_id") ?: ""
            val api = ApiCliente(base)

            var quedaAlgo = false

            for (id in pendientes(db)) {
                if (!sincronizar(db, api, id, dispositivo)) quedaAlgo = true
            }

            // `retry()` y no `success()` cuando algo quedó: deja que WorkManager
            // aplique su propio backoff en vez de esperar los quince minutos del
            // periódico. Si no queda nada, `success()` — insistir sobre una cola
            // vacía es batería regalada.
            return if (quedaAlgo) Result.retry() else Result.success()
        } catch (e: Exception) {
            return Result.retry()
        } finally {
            db.close()
        }
    }

    // ── Una solicitud ───────────────────────────────────────────────────────

    /** @return true si quedó resuelta (enviada o rendida); false si hay que volver. */
    private fun sincronizar(
        db: android.database.sqlite.SQLiteDatabase,
        api: ApiCliente,
        id: String,
        dispositivo: String
    ): Boolean {
        val intentos = intentosDe(db, id)

        marcar(db, id, "SINCRONIZANDO", null)
        anotar(db, id, "INTENTO", null)

        // La carga se reutiliza entre intentos. Si la señal se cortó tras subir
        // tres fotos, el siguiente intento aprovecha esas tres en vez de volver
        // a subirlas: en una vereda esa diferencia es real.
        var carga = cargaDe(db, id)

        if (carga == null) {
            val r = api.abrirCarga(dispositivo)
            carga = r.cuerpo?.optJSONObject("data")?.optString("carga")?.takeIf { it.isNotEmpty() }

            if (carga == null) {
                return aplicar(db, id, Reintentos.decidir(r.estado, r.cuerpo, intentos, r.retryAfter))
            }

            guardarCarga(db, id, carga)
        }

        // ── Fotos ───────────────────────────────────────────────────────────

        for (foto in adjuntosPendientes(db, id, soloVideos = false)) {
            val archivo = File(applicationContext.filesDir, foto.ruta)

            // Un archivo que ya no está no puede bloquear la solicitud para
            // siempre: se marca subido y se sigue. La solicitud vale más que la
            // foto que el sistema ya borró.
            if (!archivo.exists()) {
                marcarAdjunto(db, foto.id, "SUBIDO")
                continue
            }

            val r = api.subirArchivo(carga, archivo, foto.tipo)

            if (r.estado != null && r.estado in 200..299) {
                marcarAdjunto(db, foto.id, "SUBIDO")
            } else {
                return aplicar(db, id, Reintentos.decidir(r.estado, r.cuerpo, intentos, r.retryAfter))
            }
        }

        // ── Videos ──────────────────────────────────────────────────────────

        for (video in adjuntosPendientes(db, id, soloVideos = true)) {
            val archivo = File(applicationContext.filesDir, video.ruta)

            if (!archivo.exists()) {
                marcarAdjunto(db, video.id, "SUBIDO")
                continue
            }

            var videoId = video.videoIdServidor

            if (videoId == null) {
                val r = api.reservarVideo(
                    carga, video.categoriaId, video.mime, archivo.length(), video.segundos
                )
                videoId = r.cuerpo?.optJSONObject("data")?.optInt("id", 0)?.takeIf { it > 0 }

                if (videoId == null) {
                    return aplicar(db, id, Reintentos.decidir(r.estado, r.cuerpo, intentos, r.retryAfter))
                }

                guardarVideoId(db, video.id, videoId)
            }

            val total = trozosDe(archivo.length())

            // Desde donde iba, no desde cero. Y EN ORDEN: el servidor rechaza un
            // trozo fuera de secuencia.
            for (indice in video.trozosSubidos until total) {
                val r = api.subirTrozo(carga, videoId, indice, archivo)

                if (r.estado != null && r.estado in 200..299) {
                    guardarProgreso(db, video.id, indice + 1)
                } else {
                    return aplicar(db, id, Reintentos.decidir(r.estado, r.cuerpo, intentos, r.retryAfter))
                }
            }

            marcarAdjunto(db, video.id, "SUBIDO")
        }

        // ── El formulario, al final ─────────────────────────────────────────

        val r = api.enviarFormulario(datosDe(db, id, dispositivo), senalesDe(db, id), carga)
        val decision = Reintentos.decidir(r.estado, r.cuerpo, intentos, r.retryAfter)

        if (decision is Decision.Listo) {
            terminar(db, id, decision.radicado)

            return true
        }

        return aplicar(db, id, decision)
    }

    /**
     * Cierra una solicitud que ya llegó.
     *
     * Los archivos se borran del teléfono. No es limpieza de espacio: son fotos
     * de la cédula y de la casa de alguien, y una vez a salvo en el servidor no
     * tienen por qué seguir en un aparato que se presta, se pierde y se vende.
     */
    private fun terminar(db: android.database.sqlite.SQLiteDatabase, id: String, radicado: String) {
        for (adjunto in todosLosAdjuntos(db, id)) {
            File(applicationContext.filesDir, adjunto.ruta).delete()
        }

        db.execSQL(
            """UPDATE registros
                  SET estado = 'SINCRONIZADO', radicado = ?, error_ultimo = NULL,
                      sincronizado_en = datetime('now'), actualizado_en = datetime('now')
                WHERE id = ?""",
            arrayOf(radicado, id)
        )

        anotar(db, id, "ENVIADO", radicado)
    }

    private fun aplicar(
        db: android.database.sqlite.SQLiteDatabase,
        id: String,
        decision: Decision
    ): Boolean = when (decision) {
        is Decision.Listo -> {
            terminar(db, id, decision.radicado)
            true
        }

        is Decision.Rendirse -> {
            // `ERROR_VALIDACION` cuando hay que corregir datos, `ERROR` cuando
            // fue del camino: la pantalla dice cosas distintas y solo la primera
            // le pide algo a la persona.
            val estado = if (decision.motivo.contains("corregir")) "ERROR_VALIDACION" else "ERROR"

            db.execSQL(
                """UPDATE registros
                      SET estado = ?, error_ultimo = ?, intentos = intentos + 1,
                          ultimo_intento_en = datetime('now'), actualizado_en = datetime('now')
                    WHERE id = ?""",
                arrayOf(estado, decision.motivo, id)
            )

            anotar(db, id, "ERROR", decision.motivo)
            true
        }

        is Decision.Reintentar -> {
            db.execSQL(
                """UPDATE registros
                      SET estado = 'PENDIENTE', error_ultimo = ?, intentos = intentos + 1,
                          ultimo_intento_en = datetime('now'),
                          proximo_intento_en = datetime('now', ?),
                          actualizado_en = datetime('now')
                    WHERE id = ?""",
                arrayOf(decision.motivo, "+${decision.esperaSegundos} seconds", id)
            )

            // «Sin conexión» se distingue del resto: es lo más común y no es un
            // error que nadie tenga que resolver. En pantalla se dice distinto.
            val clase = if (decision.motivo.contains("conexión")) "SIN_CONEXION" else "ERROR"
            anotar(db, id, clase, decision.motivo)
            false
        }
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    private data class AdjuntoFila(
        val id: String,
        val tipo: String,
        val ruta: String,
        val mime: String,
        val segundos: Int,
        val categoriaId: Int?,
        val videoIdServidor: Int?,
        val trozosSubidos: Int
    )

    private fun trozosDe(bytes: Long): Int =
        if (bytes <= 0) 0 else ((bytes + ApiCliente.BYTES_TROZO - 1) / ApiCliente.BYTES_TROZO).toInt()

    private fun ajuste(db: android.database.sqlite.SQLiteDatabase, clave: String): String? =
        db.rawQuery("SELECT valor FROM ajustes WHERE clave = ?", arrayOf(clave)).use {
            if (it.moveToFirst() && !it.isNull(0)) it.getString(0) else null
        }

    /**
     * La cola: lo pendiente cuya espera ya venció.
     *
     * `ERROR` no entra — ahí la persona tiene que tocar «Reintentar». Insistir
     * sobre algo que ya se rindió cinco veces solo gasta batería.
     */
    private fun pendientes(db: android.database.sqlite.SQLiteDatabase): List<String> =
        db.rawQuery(
            """SELECT id FROM registros
                WHERE estado IN ('PENDIENTE','SINCRONIZANDO')
                  AND (proximo_intento_en IS NULL OR proximo_intento_en <= datetime('now'))
                ORDER BY creado_en""",
            null
        ).use {
            val ids = mutableListOf<String>()
            while (it.moveToNext()) ids.add(it.getString(0))
            ids
        }

    private fun intentosDe(db: android.database.sqlite.SQLiteDatabase, id: String): Int =
        db.rawQuery("SELECT intentos FROM registros WHERE id = ?", arrayOf(id)).use {
            if (it.moveToFirst()) it.getInt(0) else 0
        }

    private fun cargaDe(db: android.database.sqlite.SQLiteDatabase, id: String): String? =
        db.rawQuery("SELECT carga FROM registros WHERE id = ?", arrayOf(id)).use {
            if (it.moveToFirst() && !it.isNull(0)) it.getString(0) else null
        }

    private fun adjuntosPendientes(
        db: android.database.sqlite.SQLiteDatabase,
        id: String,
        soloVideos: Boolean
    ): List<AdjuntoFila> {
        val filtro = if (soloVideos) "= 'VIDEO'" else "<> 'VIDEO'"

        return db.rawQuery(
            """SELECT id, tipo, ruta, mime, COALESCE(segundos,0), categoria_id,
                      video_id_servidor, trozos_subidos
                 FROM adjuntos
                WHERE registro_id = ? AND tipo $filtro AND estado <> 'SUBIDO'
                ORDER BY creado_en""",
            arrayOf(id)
        ).use { leerAdjuntos(it) }
    }

    private fun todosLosAdjuntos(
        db: android.database.sqlite.SQLiteDatabase,
        id: String
    ): List<AdjuntoFila> = db.rawQuery(
        """SELECT id, tipo, ruta, mime, COALESCE(segundos,0), categoria_id,
                  video_id_servidor, trozos_subidos
             FROM adjuntos WHERE registro_id = ?""",
        arrayOf(id)
    ).use { leerAdjuntos(it) }

    private fun leerAdjuntos(c: android.database.Cursor): List<AdjuntoFila> {
        val filas = mutableListOf<AdjuntoFila>()

        while (c.moveToNext()) {
            filas.add(
                AdjuntoFila(
                    id = c.getString(0),
                    tipo = c.getString(1),
                    ruta = c.getString(2),
                    mime = c.getString(3),
                    segundos = c.getInt(4),
                    categoriaId = if (c.isNull(5)) null else c.getInt(5),
                    videoIdServidor = if (c.isNull(6)) null else c.getInt(6),
                    trozosSubidos = c.getInt(7)
                )
            )
        }

        return filas
    }

    private fun senalesDe(db: android.database.sqlite.SQLiteDatabase, id: String): List<String> =
        db.rawQuery(
            "SELECT codigo FROM registro_senales WHERE registro_id = ?",
            arrayOf(id)
        ).use {
            val codigos = mutableListOf<String>()
            while (it.moveToNext()) codigos.add(it.getString(0))
            codigos
        }

    private fun datosDe(
        db: android.database.sqlite.SQLiteDatabase,
        id: String,
        dispositivo: String
    ): JSONObject = db.rawQuery(
        """SELECT envio_id, nombre_completo, documento, telefono, correo, zona,
                  direccion, vereda, corregimiento, latitud, longitud, precision_m,
                  descripcion_dano, aviso_version
             FROM registros WHERE id = ?""",
        arrayOf(id)
    ).use { c ->
        val j = JSONObject()

        if (c.moveToFirst()) {
            j.put("envio_id", c.getString(0))
            j.put("nombre_completo", c.getString(1))
            j.put("documento", c.getString(2))
            j.put("telefono", c.getString(3))
            j.put("correo", if (c.isNull(4)) "" else c.getString(4))
            j.put("zona", c.getString(5))
            j.put("direccion", c.getString(6))
            j.put("vereda", if (c.isNull(7)) "" else c.getString(7))
            j.put("corregimiento", if (c.isNull(8)) "" else c.getString(8))
            if (!c.isNull(9)) j.put("latitud", c.getDouble(9))
            if (!c.isNull(10)) j.put("longitud", c.getDouble(10))
            if (!c.isNull(11)) j.put("precision_m", c.getInt(11))
            j.put("descripcion_dano", if (c.isNull(12)) "" else c.getString(12))
            j.put("aviso_version", c.getString(13))
        }

        j.put("autoriza_datos", true)
        j.put("dispositivo_id", dispositivo)
        // La trampa antirrobot va SIEMPRE, aunque vacía. Si dejara de mandarse,
        // el servidor nunca vería el campo lleno y la trampa quedaría desarmada
        // sin que nada fallara.
        j.put("sitio_web", "")

        j
    }

    /**
     * Deja constancia de un intento.
     *
     * Una fila por intento, no por registro. `registros` solo guarda el último,
     * y eso basta para decidir cuándo reintentar pero no para responder la
     * pregunta que de verdad hace la gente —«¿cuándo se mandó lo mío?»— ni la
     * que hace quien atiende el teléfono: «¿se ha intentado siquiera?».
     *
     * `@Suppress` no hace falta: si esto fallara, no puede tumbar la
     * sincronización. Una bitácora que no se escribe es un inconveniente; una
     * solicitud que no sale es el problema que esta aplicación existe para
     * evitar.
     */
    private fun anotar(
        db: android.database.sqlite.SQLiteDatabase,
        id: String,
        resultado: String,
        detalle: String?
    ) {
        try {
            db.execSQL(
                """INSERT INTO bitacora (id, registro_id, cuando, resultado, detalle)
                   VALUES (?, ?, datetime('now'), ?, ?)""",
                arrayOf(java.util.UUID.randomUUID().toString(), id, resultado, detalle)
            )
        } catch (e: Exception) {
            // Ver arriba: nunca hacia arriba.
        }
    }

    // ── Escrituras cortas ───────────────────────────────────────────────────

    private fun marcar(
        db: android.database.sqlite.SQLiteDatabase,
        id: String,
        estado: String,
        error: String?
    ) = db.execSQL(
        "UPDATE registros SET estado = ?, error_ultimo = ?, actualizado_en = datetime('now') WHERE id = ?",
        arrayOf(estado, error, id)
    )

    private fun guardarCarga(db: android.database.sqlite.SQLiteDatabase, id: String, carga: String) =
        db.execSQL("UPDATE registros SET carga = ? WHERE id = ?", arrayOf(carga, id))

    private fun marcarAdjunto(
        db: android.database.sqlite.SQLiteDatabase,
        id: String,
        estado: String
    ) = db.execSQL(
        "UPDATE adjuntos SET estado = ?, actualizado_en = datetime('now') WHERE id = ?",
        arrayOf(estado, id)
    )

    private fun guardarVideoId(
        db: android.database.sqlite.SQLiteDatabase,
        id: String,
        videoId: Int
    ) = db.execSQL(
        "UPDATE adjuntos SET video_id_servidor = ? WHERE id = ?",
        arrayOf(videoId.toString(), id)
    )

    private fun guardarProgreso(
        db: android.database.sqlite.SQLiteDatabase,
        id: String,
        trozos: Int
    ) = db.execSQL(
        "UPDATE adjuntos SET trozos_subidos = ?, actualizado_en = datetime('now') WHERE id = ?",
        arrayOf(trozos.toString(), id)
    )
}
