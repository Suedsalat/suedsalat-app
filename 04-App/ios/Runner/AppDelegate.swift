import AVFoundation
import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  private let carContextChannel = "eu.suedsalat.suedsalat_app/car_context"

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    // Erkennt CarPlay anhand der aktuellen Audio-Ausgabe-Route - sobald ueber
    // CarPlay wiedergegeben wird, meldet AVAudioSession "carAudio" als
    // Ausgabe-Port. Rein informativ fuer die anonyme Statistik in
    // CarContextService, kein Geraete-/Fahrzeug-Identifier.
    // registrar(forPlugin:) ist die stabile Standard-API fuer einen eigenen
    // Method-Channel, unabhaengig von Details der Implicit-Engine-API.
    if let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "CarContextChannel") {
      let channel = FlutterMethodChannel(
        name: carContextChannel,
        binaryMessenger: registrar.messenger()
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
}
