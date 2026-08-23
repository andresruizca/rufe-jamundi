package co.gov.jamundi.sgr

import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.CapacitorPlugin

/**
 * El puente para que la aplicación pueda pedir una sincronización ya.
 *
 * Sin esto, lo único que sincroniza es la tarea periódica, y Android no la
 * ejecuta más seguido que cada quince minutos. Así que alguien que llena el
 * formulario, sale al patio donde hay señal y se queda mirando la pantalla,
 * puede esperar un cuarto de hora viendo «se enviará en cuanto haya internet»
 * con internet delante. Funciona, pero parece que no.
 *
 * Con esto, tres momentos disparan el envío: al recuperar la red, al volver a
 * abrir la aplicación, y la tarea periódica de siempre como red de seguridad.
 */
@CapacitorPlugin(name = "Sincronizacion")
class SyncPlugin : Plugin() {

    companion object {
        private const val TRABAJO_INMEDIATO = "sgr-sincronizar-ya"
    }

    /**
     * Encola un envío para ahora mismo.
     *
     * `KEEP` y no `REPLACE`: si ya hay uno en marcha, pedir otro no lo acelera
     * —lo reinicia—. Y esto se llama varias veces seguidas sin querer, porque
     * abrir la aplicación con la red recién recuperada dispara los dos avisos.
     */
    @PluginMethod
    fun sincronizarAhora(call: PluginCall) {
        val condiciones = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        val trabajo = OneTimeWorkRequestBuilder<SyncWorker>()
            .setConstraints(condiciones)
            .build()

        WorkManager.getInstance(context).enqueueUniqueWork(
            TRABAJO_INMEDIATO,
            ExistingWorkPolicy.KEEP,
            trabajo
        )

        call.resolve()
    }
}
