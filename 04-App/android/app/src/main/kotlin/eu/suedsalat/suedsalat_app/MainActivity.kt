package eu.suedsalat.suedsalat_app

import android.content.res.Configuration
import com.ryanheise.audioservice.AudioServiceActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

// Erbt von AudioServiceActivity (audio_service-Paket) statt direkt von
// FlutterActivity - das teilt die Flutter-Engine korrekt mit dem
// Hintergrund-Wiedergabe-Service (siehe audio_service-Doku "Custom Android
// activity"). Ohne diese Umstellung startet zwar eine zweite Engine-Instanz,
// aber die Medien-Session/Android-Auto-Anbindung funktioniert nicht richtig.
class MainActivity : AudioServiceActivity() {
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
