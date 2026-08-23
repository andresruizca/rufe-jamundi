package co.gov.jamundi.sgr;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    /**
     * Los complementos de terceros los descubre Capacitor solo; los propios,
     * no. Sin esta línea, `registerPlugin('Sincronizacion')` en TypeScript
     * devuelve un objeto que no hace nada y la aplicación NUNCA pide sincronizar
     * — sin ningún error a la vista.
     */
    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(SyncPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
