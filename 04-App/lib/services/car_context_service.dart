import 'dart:io';

import 'package:flutter/services.dart';

/// Erkennt anonym, ob eine Wiedergabe gerade ueber Android Auto oder CarPlay
/// laeuft - fuer die Auto-Nutzungs-Auswertung im Admin-Bereich. Rein
/// informativ, kein Personenbezug: es wird nur "android_auto"/"carplay"/null
/// ermittelt, kein Geraete- oder Fahrzeug-Identifier.
///
/// Android: Configuration.uiMode wechselt auf UI_MODE_TYPE_CAR, sobald
/// Android Auto aktiv das Display projiziert - das ist der uebliche,
/// zuverlaessige Weg, das zu erkennen (siehe MainActivity.kt).
/// iOS: die aktuelle Audio-Route zeigt "carAudio" als Ausgabe-Port, sobald
/// ueber CarPlay wiedergegeben wird (siehe AppDelegate.swift).
class CarContextService {
  static const _channel = MethodChannel('eu.suedsalat.suedsalat_app/car_context');

  /// Liefert 'android_auto', 'carplay' oder null (normale Wiedergabe am
  /// Geraet selbst). Fehler werden verschluckt - die Zuordnung ist rein
  /// informativ und darf die eigentliche Wiedergabe nie blockieren.
  static Future<String?> detect() async {
    try {
      if (Platform.isAndroid) {
        final isCar = await _channel.invokeMethod<bool>('isAndroidAuto') ?? false;
        return isCar ? 'android_auto' : null;
      }
      if (Platform.isIOS) {
        final isCarPlay = await _channel.invokeMethod<bool>('isCarPlay') ?? false;
        return isCarPlay ? 'carplay' : null;
      }
    } catch (_) {
      // Ignorieren - Plattform-Kanal evtl. noch nicht bereit, kein kritischer Pfad.
    }
    return null;
  }
}
