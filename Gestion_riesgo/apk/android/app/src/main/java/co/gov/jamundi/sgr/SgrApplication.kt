package co.gov.jamundi.sgr

import android.app.Application

/**
 * Programa la sincronización en cuanto la aplicación existe.
 *
 * Va aquí y no en la pantalla principal a propósito: `Application.onCreate` corre
 * también cuando Android levanta el proceso por su cuenta —para entregar una
 * tarea, por ejemplo— sin que nadie haya abierto nada. Si el trabajo periódico
 * se programara desde una Activity, un teléfono al que se le instaló la
 * aplicación y no se volvió a tocar nunca sincronizaría.
 *
 * Hay que declararla en `AndroidManifest.xml`:
 *
 *     <application android:name=".SgrApplication" ...>
 */
class SgrApplication : Application() {

    override fun onCreate() {
        super.onCreate()

        // Idempotente: `enqueueUniquePeriodicWork` con KEEP no reinicia el reloj
        // si ya estaba programado.
        SyncWorker.programar(this)
    }
}
