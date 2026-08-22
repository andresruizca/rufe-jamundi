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
     * El nombre que le pone el plugin de Capacitor.
     *
     * `@capacitor-community/sqlite` guarda las bases en `databases/` dentro de
     * los datos de la aplicación y añade el sufijo `SQLite.db` al nombre que se
     * le pidió en `createConnection`. Si algún día cambia el nombre en
     * `src/local/base.ts`, hay que cambiarlo aquí: son dos sitios y no hay forma
     * de que el compilador lo note.
     */
    private const val ARCHIVO = "sgr_ciudadanoSQLite.db"

    fun abrir(contexto: Context): SQLiteDatabase {
        val ruta = File(File(contexto.filesDir.parentFile, "databases"), ARCHIVO)

        val db = SQLiteDatabase.openDatabase(
            ruta.absolutePath,
            null,
            SQLiteDatabase.OPEN_READWRITE
        )

        // ⚠ Ver la cabecera. Esto va SIEMPRE, en cada apertura.
        db.execSQL("PRAGMA foreign_keys = ON;")

        return db
    }

    /** ¿Existe ya? Antes del primer uso del formulario, no. */
    fun existe(contexto: Context): Boolean =
        File(File(contexto.filesDir.parentFile, "databases"), ARCHIVO).exists()
}
