package co.gov.jamundi.sgr

import android.content.Context
import android.database.sqlite.SQLiteDatabase
import java.io.File

/**
 * La misma base que abre el WebView, vista desde Kotlin.
 *
 * `SyncWorker` corre con la aplicación CERRADA: no hay WebView, no hay
 * JavaScript y no hay plugin de Capacitor vivo. Esta clase abre el archivo de
 * SQLite directamente.
 *
 * ⚠ EL PRAGMA NO ES OPCIONAL.
 *
 * En SQLite `foreign_keys` es POR CONEXIÓN, no del archivo. El lado TypeScript
 * lo emite en `src/local/base.ts`; esta conexión es OTRA y tiene que emitirlo
 * por su cuenta. Sin él, borrar un registro ya sincronizado deja sus señales y
 * sus adjuntos como filas huérfanas, apuntando a archivos que nadie va a borrar.
 *
 * Está comprobado con SQLite de verdad en `scripts/comprobar-esquema.mjs`: sin
 * el pragma quedan huérfanos; con él, la cascada limpia.
 */
object BaseDatos {

    /**
     * El nombre del archivo, tal como lo compone el plugin de Capacitor.
     *
     * `@capacitor-community/sqlite` toma el nombre que se le pasó a
     * `createConnection` —`sgr_ciudadano`, en `src/local/base.ts`— y le pega el
     * sufijo `SQLite.db`. Está leído de su código, no supuesto:
     * `CapacitorSQLite.java` hace `dbName + "SQLite.db"`.
     *
     * Son dos sitios y el compilador no puede emparejarlos, así que lo empareja
     * `scripts/comparar-kotlin.mjs`: si alguien cambia el nombre en TypeScript y
     * se olvida de aquí, el sincronizador no encontraría la base y fallaría en
     * SILENCIO — nadie sabría que las solicitudes dejaron de salir.
     */
    private const val NOMBRE_EN_TYPESCRIPT = "sgr_ciudadano"
    private const val ARCHIVO = NOMBRE_EN_TYPESCRIPT + "SQLite.db"

    /**
     * La ruta la da Android, no se construye a mano.
     *
     * `getDatabasePath()` devuelve exactamente lo que usa el plugin —él llama a
     * lo mismo—, así que no hay dos formas de calcular la carpeta que puedan
     * separarse.
     */
    private fun archivo(contexto: Context): File = contexto.getDatabasePath(ARCHIVO)

    fun abrir(contexto: Context): SQLiteDatabase {
        val db = SQLiteDatabase.openDatabase(
            archivo(contexto).absolutePath,
            null,
            SQLiteDatabase.OPEN_READWRITE
        )

        // ⚠ Ver la cabecera. Esto va SIEMPRE, en cada apertura.
        db.execSQL("PRAGMA foreign_keys = ON;")

        return db
    }

    /** ¿Existe ya? Antes del primer uso del formulario, no. */
    fun existe(contexto: Context): Boolean = archivo(contexto).exists()
}
