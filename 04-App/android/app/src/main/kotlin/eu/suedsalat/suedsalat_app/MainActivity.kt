package eu.suedsalat.suedsalat_app

import android.content.res.Configuration
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private val carContextChannel = "eu.suedsalat.suedsalat_app/car_context"

    // Erkennt Android Auto anhand des aktiven UI-Modus - Configuration.uiMode
    // wechselt auf UI_MODE_TYPE_CAR, sobald Android Auto das Display
    // projiziert, unabhaengig davon ueber welchen Weg (Bluetooth/USB/WLAN)
    // die Verbindung laeuft. Rein informativ fuer die anonyme Statistik in
    // CarContextService, kein Geraete-/Fahrzeug-Identifier.
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, carContextChannel)
            .setMethodCallHandler { call, result ->
                if (call.method == "isAndroidAuto") {
                    val uiMode = resources.configuration.uiMode and Configuration.UI_MODE_TYPE_MASK
                    result.success(uiMode == Configuration.UI_MODE_TYPE_CAR)
                } else {
                    result.notImplemented()
                }
            }
    }
}
