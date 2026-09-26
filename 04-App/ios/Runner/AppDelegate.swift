import AVFoundation
import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate {
  private let carContextChannel = "eu.suedsalat.suedsalat_app/car_context"

  /// Eigene, sofort gestartete Flutter-Engine statt der automatisch erzeugten ("implicit engine").
  /// Grund ist CarPlay: Verbindet sich das iPhone mit dem Auto, startet iOS die App oft nur im
  /// Hintergrund und verbindet allein die CarPlay-Szene - ein Handy-Fenster (und damit die
  /// automatische Engine) gibt es dann nicht. Diese Engine laeuft aber immer, sodass die Folgenliste
  /// und der Player auch im Auto funktionieren. Das Handy-Fenster (SceneDelegate) zeigt dieselbe
  /// Engine an - es gibt also nur einen Player, egal ob man im Auto oder am Handy tippt.
  let flutterEngine = FlutterEngine(name: "suedsalat")

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    flutterEngine.run()
    GeneratedPluginRegistrant.register(with: flutterEngine)
    registerCarContextChannel()
    CarPlayBridge.shared.attach(to: flutterEngine.binaryMessenger)
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  // Erkennt CarPlay anhand der aktuellen Audio-Ausgabe-Route - sobald ueber
  // CarPlay wiedergegeben wird, meldet AVAudioSession "carAudio" als
  // Ausgabe-Port. Rein informativ fuer die anonyme Statistik in
  // CarContextService, kein Geraete-/Fahrzeug-Identifier.
  private func registerCarContextChannel() {
    let channel = FlutterMethodChannel(
      name: carContextChannel,
      binaryMessenger: flutterEngine.binaryMessenger
    )
    channel.setMethodCallHandler { call, result in
      if call.method == "isCarPlay" {
        let isCarPlay = AVAudioSession.sharedInstance().currentRoute.outputs.contains {
          $0.portType == .carAudio
        }
        result(isCarPlay)
      } else {
        result(FlutterMethodNotImplemented)
      }
    }
  }
}
