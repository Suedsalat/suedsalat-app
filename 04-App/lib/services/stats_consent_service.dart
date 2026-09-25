import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_service.dart';

/// Einwilligung in die anonyme Statistik (App 2.0, § 25 TDDDG), pro Installation.
///
/// Ohne Zustimmung schickt die App gar keine Zaehl-Anfragen (trackView, trackEpisodePlay,
/// trackEpisodeMilestone) - nicht erst der Server verwirft sie. Die Entscheidung gilt sofort
/// lokal und wird an den Server gemeldet, der sie mit Textfassung als Nachweis speichert.
class StatsConsentService extends ChangeNotifier {
  StatsConsentService._();

  static final StatsConsentService instance = StatsConsentService._();

  /// Fassung des Dialogtexts (StatsConsentDialog). Muss zu StatsConsent::TEXT_VERSION im
  /// Backend passen. Bei inhaltlicher Aenderung des Texts beide erhoehen - dann fragt die App
  /// alle erneut.
  static const textVersion = '2026-10';

  static const _decisionKey = 'stats_consent';
  static const _versionKey = 'stats_consent_version';

  String? _decision;
  String? _version;
  bool _loaded = false;

  bool get granted => _decision == 'granted' && _version == textVersion;
  bool get decided => _decision != null;
  bool get needsDecision =>
      _loaded && (_decision == null || _version != textVersion);

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    _decision = prefs.getString(_decisionKey);
    _version = prefs.getString(_versionKey);
    _loaded = true;
    notifyListeners();
  }

  /// Abgleich mit dem Server beim Start: Konnte eine Entscheidung (z. B. offline) nicht
  /// gemeldet werden, wird sie nachgereicht. Fehler bleiben still - der Abgleich wird beim
  /// naechsten Start wiederholt.
  Future<void> sync() async {
    if (!_loaded) await load();
    try {
      final server = await ApiService().sendJson(
        'GET',
        'statistics-consent.php',
      );
      final local = _decision;
      if (local != null &&
          (server['consent'] != local || server['text_version'] != _version)) {
        await _send(local, _version ?? textVersion);
      }
    } catch (_) {
      // Kein Netz oder alter Server - nicht schlimm.
    }
  }

  /// Zustimmen oder ablehnen/widerrufen. Wirkt lokal sofort.
  Future<void> decide({required bool granted}) async {
    final decision = granted ? 'granted' : 'denied';
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_decisionKey, decision);
    await prefs.setString(_versionKey, textVersion);
    _decision = decision;
    _version = textVersion;
    _loaded = true;
    notifyListeners();
    try {
      await _send(decision, textVersion);
    } catch (_) {
      // Wird bei sync() nachgereicht.
    }
  }

  Future<void> _send(String decision, String version) => ApiService().sendJson(
    'POST',
    'statistics-consent.php',
    {'decision': decision, 'text_version': version},
  );
}
