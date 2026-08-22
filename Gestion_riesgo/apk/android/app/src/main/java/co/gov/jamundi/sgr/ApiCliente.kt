package co.gov.jamundi.sgr

import org.json.JSONArray
import org.json.JSONObject
import java.io.DataOutputStream
import java.io.File
import java.io.RandomAccessFile
import java.net.HttpURLConnection
import java.net.URL

/**
 * El protocolo de subida, en Kotlin.
 *
 * Es la segunda implementación de algo que ya existe en TypeScript, y eso es una
 * decisión con precio: WorkManager corre sin WebView, así que la subida en
 * segundo plano no puede reutilizar el código de la web. Lo que se hace para que
 * las dos no se separen en silencio está en `scripts/comparar-kotlin.mjs`.
 *
 * Sin dependencias nuevas: `HttpURLConnection` y `org.json` vienen con Android.
 * Es el mismo criterio con el que el backend de este proyecto no usa Composer —
 * una dependencia que hay que mantener durante años para ahorrar treinta líneas
 * de multipart no sale a cuenta.
 *
 * ── Los cinco pasos ──────────────────────────────────────────────────────────
 *
 *   1. abrirCarga()          → token de carga
 *   2. subirArchivo()        → una foto, multipart
 *   3. reservarVideo()       → JSON, devuelve id y cuántos trozos
 *   4. subirTrozo()          → EN ORDEN, 1 MiB cada uno
 *   5. enviarFormulario()    → JSON con todo y el token de carga
 *
 * Cada uno de esos puntos costó un fallo en la implementación de TypeScript. Van
 * anotados donde corresponde.
 */
class ApiCliente(private val base: String) {

    companion object {
        /** `Videos::BYTES_TROZO`. El servidor rechaza un trozo fuera de orden. */
        const val BYTES_TROZO = 1024 * 1024

        private const val TIEMPO_CONEXION = 30_000
        private const val TIEMPO_LECTURA = 120_000
    }

    /**
     * Lo que devolvió el servidor, sin interpretar todavía.
     *
     * `retryAfter` se lee de la cabecera del mismo nombre: `Limite.php` la manda
     * con los segundos que quedan de su ventana de control de tasa. Honrarla en
     * vez de usar la escalera genérica es lo que hace que una brigada entera
     * tras una misma IP termine de sincronizar —medido: 20 de 20 en vez de 15—.
     */
    data class Respuesta(
        val estado: Int?,
        val cuerpo: JSONObject?,
        val retryAfter: Int?
    )

    // ── 1. Abrir la carga ───────────────────────────────────────────────────

    fun abrirCarga(dispositivoId: String): Respuesta =
        conJson("POST", "$base/preinscripcion/cargas", JSONObject().apply {
            put("dispositivo_id", dispositivoId)
        })

    // ── 2. Las fotos ────────────────────────────────────────────────────────

    /**
     * @param tipo `PRE_CEDULA` o `PRE_DANO`. El servidor los filtra contra una
     *   lista blanca: cualquier otro valor hace que la foto ocupe un cupo que no
     *   le corresponde, y por eso lo rechaza.
     */
    fun subirArchivo(carga: String, archivo: File, tipo: String): Respuesta {
        val conexion = abrirConexion("POST", "$base/preinscripcion/cargas/$carga/archivos")
        val frontera = "----sgr${System.nanoTime()}"

        conexion.setRequestProperty("Content-Type", "multipart/form-data; boundary=$frontera")
        conexion.doOutput = true

        DataOutputStream(conexion.outputStream.buffered()).use { salida ->
            campoDeTexto(salida, frontera, "tipo", tipo)

            salida.writeBytes("--$frontera\r\n")
            salida.writeBytes(
                "Content-Disposition: form-data; name=\"archivo\"; filename=\"${archivo.name}\"\r\n"
            )
            salida.writeBytes("Content-Type: application/octet-stream\r\n\r\n")
            archivo.inputStream().use { it.copyTo(salida) }
            salida.writeBytes("\r\n--$frontera--\r\n")
        }

        return leer(conexion)
    }

    // ── 3. Reservar el video ────────────────────────────────────────────────

    /**
     * ⚠ VA EN JSON, NO EN FORMULARIO.
     *
     * El servidor lee este cuerpo con `json_decode`. Mandarlo como
     * `application/x-www-form-urlencoded` hace que llegue VACÍO y el video no se
     * reserve nunca. Fue un fallo real de la versión de TypeScript, y no daba
     * error visible: simplemente ningún video llegaba.
     *
     * `mime` tiene que ser uno de `Videos::FORMATOS` —`video/webm`, `video/mp4`
     * o `video/quicktime`—; cualquier otro se rechaza con el archivo ya grabado.
     */
    fun reservarVideo(
        carga: String,
        categoriaId: Int?,
        mime: String,
        bytes: Long,
        segundos: Int
    ): Respuesta = conJson("POST", "$base/preinscripcion/cargas/$carga/videos", JSONObject().apply {
        if (categoriaId != null) put("categoria_id", categoriaId)
        put("mime", mime)
        put("bytes", bytes)
        put("segundos", segundos)
    })

    // ── 4. Los trozos ───────────────────────────────────────────────────────

    /**
     * Un trozo de 1 MiB, leído del archivo sin cargarlo entero en memoria.
     *
     * ⚠ EN ORDEN, empezando en cero. El servidor rechaza un trozo que llegue
     * fuera de secuencia, y un video al que le falte alguno lo BORRA al recibir
     * el formulario, dejando una nota en el historial de la ficha. De ahí que
     * `SyncWorker` termine todos los trozos antes de mandar el paso 5.
     *
     * `RandomAccessFile` y no `readBytes()`: un video de 8 MiB cargado entero en
     * memoria, en un teléfono de gama baja y con la aplicación en segundo plano,
     * es justo la clase de cosa que Android mata sin avisar.
     */
    fun subirTrozo(carga: String, videoId: Int, indice: Int, archivo: File): Respuesta {
        val desde = indice.toLong() * BYTES_TROZO
        val cuantos = minOf(BYTES_TROZO.toLong(), archivo.length() - desde).toInt()

        if (cuantos <= 0) {
            return Respuesta(null, null, null)
        }

        val datos = ByteArray(cuantos)
        RandomAccessFile(archivo, "r").use { lector ->
            lector.seek(desde)
            lector.readFully(datos)
        }

        val conexion = abrirConexion(
            "POST",
            "$base/preinscripcion/cargas/$carga/videos/$videoId/trozos"
        )
        val frontera = "----sgr${System.nanoTime()}"

        conexion.setRequestProperty("Content-Type", "multipart/form-data; boundary=$frontera")
        conexion.doOutput = true

        DataOutputStream(conexion.outputStream.buffered()).use { salida ->
            campoDeTexto(salida, frontera, "indice", indice.toString())

            salida.writeBytes("--$frontera\r\n")
            salida.writeBytes(
                "Content-Disposition: form-data; name=\"trozo\"; filename=\"t$indice\"\r\n"
            )
            salida.writeBytes("Content-Type: application/octet-stream\r\n\r\n")
            salida.write(datos)
            salida.writeBytes("\r\n--$frontera--\r\n")
        }

        return leer(conexion)
    }

    // ── 5. El formulario ────────────────────────────────────────────────────

    /**
     * ⚠ SIN `carga`, LAS FOTOS Y LOS VIDEOS SE PIERDEN.
     *
     * El servidor adopta los archivos de esa carga al recibir la solicitud. Si
     * no llega el token, quedan huérfanos en `temporal/` y la purga se los lleva
     * dos horas después: la solicitud entra sin una sola evidencia y nadie se
     * entera. Pasó exactamente eso en la web, con las fotos de la inspección.
     *
     * `envio_id` es lo que hace seguro reintentar: si la solicitud entró pero la
     * respuesta se perdió —lo normal con mala señal—, el servidor devuelve el
     * radicado original con `reintento: true` en vez de inscribir dos veces al
     * mismo hogar.
     */
    fun enviarFormulario(datos: JSONObject, senales: List<String>, carga: String?): Respuesta {
        val cuerpo = JSONObject(datos.toString())

        cuerpo.put("senales", JSONArray(senales))
        if (carga != null) cuerpo.put("carga", carga)

        return conJson("POST", "$base/preinscripcion", cuerpo)
    }

    // ── Fontanería ──────────────────────────────────────────────────────────

    private fun abrirConexion(metodo: String, url: String): HttpURLConnection {
        val conexion = URL(url).openConnection() as HttpURLConnection

        conexion.requestMethod = metodo
        conexion.connectTimeout = TIEMPO_CONEXION
        // Generoso a propósito: una subida por red móvil de vereda tarda, y
        // cortarla a los treinta segundos convierte un trozo lento en un fallo.
        conexion.readTimeout = TIEMPO_LECTURA
        conexion.setRequestProperty("Accept", "application/json")
        conexion.setRequestProperty("User-Agent", "SGR-Jamundi-APK")

        return conexion
    }

    private fun conJson(metodo: String, url: String, cuerpo: JSONObject): Respuesta {
        val conexion = abrirConexion(metodo, url)

        conexion.setRequestProperty("Content-Type", "application/json; charset=utf-8")
        conexion.doOutput = true
        conexion.outputStream.use { it.write(cuerpo.toString().toByteArray(Charsets.UTF_8)) }

        return leer(conexion)
    }

    private fun campoDeTexto(
        salida: DataOutputStream,
        frontera: String,
        nombre: String,
        valor: String
    ) {
        salida.writeBytes("--$frontera\r\n")
        salida.writeBytes("Content-Disposition: form-data; name=\"$nombre\"\r\n\r\n")
        salida.write(valor.toByteArray(Charsets.UTF_8))
        salida.writeBytes("\r\n")
    }

    /**
     * Lee la respuesta, venga como venga.
     *
     * Un 4xx o 5xx llega por `errorStream`, no por `inputStream`, y leer del
     * segundo lanza excepción. Sin esto, todo error del servidor se vería como
     * «no hubo red», que es justo lo contrario: un 422 con los campos mal no se
     * debe reintentar y un fallo de red sí.
     */
    private fun leer(conexion: HttpURLConnection): Respuesta {
        return try {
            val estado = conexion.responseCode
            val flujo = if (estado in 200..299) conexion.inputStream else conexion.errorStream
            val texto = flujo?.bufferedReader()?.use { it.readText() } ?: ""

            val json = try {
                if (texto.isBlank()) null else JSONObject(texto)
            } catch (e: Exception) {
                null
            }

            Respuesta(estado, json, conexion.getHeaderField("Retry-After")?.toIntOrNull())
        } catch (e: Exception) {
            // Sin red. `estado = null` es lo que `Decision` traduce a «reintentar
            // sin contarlo como culpa del contenido».
            Respuesta(null, null, null)
        } finally {
            conexion.disconnect()
        }
    }
}
