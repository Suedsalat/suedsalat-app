import 'dart:io';

import 'package:firebase_messaging/firebase_messaging.dart';

import 'api_service.dart';

/// Kuemmert sich um Berechtigung, FCM-Token und die An-/Abmeldung dieses
/// Geraets beim Backend (siehe api/register-push-token.php).
class PushNotificationService {
  PushNotificationService._();

  static final PushNotificationService instance = PushNotificationService._();

  final _messaging = FirebaseMessaging.instance;
  final _api = ApiService();

  String get _platform => Platform.isIOS ? 'ios' : 'android';

  /// Fordert Berechtigung an und registriert das Geraet beim Backend.
  /// Wird beim App-Start aufgerufen, wenn Push aktiviert ist, und wenn der
  /// Nutzer den Schalter in den Einstellungen einschaltet.
  Future<void> enable() async {
    final settings = await _messaging.requestPermission();
    if (settings.authorizationStatus == AuthorizationStatus.denied) {
      return;
    }

    if (Platform.isIOS) {
      // Auf iOS liefert getToken() sofort nach App-Start einen Fehler
      // (apns-token-not-set), weil der native APNs-Token erst kurz nach dem
      // Start eintrifft. Darauf warten, bevor der FCM-Token angefragt wird.
      var apnsToken = await _messaging.getAPNSToken();
      var attempts = 0;
      while (apnsToken == null && attempts < 10) {
        await Future.delayed(const Duration(seconds: 1));
        apnsToken = await _messaging.getAPNSToken();
        attempts++;
      }
    }

    String? token;
    try {
      token = await _messaging.getToken();
    } catch (_) {
      token = null;
    }
    if (token != null) {
      await _api.registerPushToken(token, _platform);
    }

    _messaging.onTokenRefresh.listen((newToken) {
      _api.registerPushToken(newToken, _platform);
    });
  }

  /// Meldet das Geraet beim Backend ab, wenn der Nutzer Push ausschaltet.
  Future<void> disable() async {
    final token = await _messaging.getToken();
    if (token != null) {
      await _api.unregisterPushToken(token);
    }
  }
}
