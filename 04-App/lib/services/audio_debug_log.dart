import 'package:shared_preferences/shared_preferences.dart';

/// Temporaeres Diagnose-Log fuer die Android-Auto-/CarPlay-Anbindung, um ohne
/// PC/USB-Debugging nachvollziehen zu koennen, welche Aufrufe vom Betriebssystem
/// tatsaechlich ankommen und woran sie ggf. scheitern. Wird auf dem Geraet in
/// SharedPreferences gehalten, damit es auch nach dem Verlassen der App noch
/// abrufbar ist (siehe AudioDebugLogScreen).
///
/// Bewusst nur eine Uebergangsloesung fuer die Fehlersuche - nach Abschluss
/// wieder entfernen (Log-Aufrufe in audio_handler.dart + diesen Screen).
class AudioDebugLog {
  static const _prefsKey = 'audio_debug_log_v1';
  static const _maxEntries = 200;

  static final List<String> _entries = [];
  static bool _loaded = false;

  static Future<void> _ensureLoaded() async {
    if (_loaded) return;
    _loaded = true;
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getStringList(_prefsKey);
    if (saved != null) {
      _entries.addAll(saved);
    }
  }

  static Future<void> add(String message) async {
    await _ensureLoaded();
    final timestamp = DateTime.now().toIso8601String().substring(11, 19);
    _entries.add('[$timestamp] $message');
    if (_entries.length > _maxEntries) {
      _entries.removeRange(0, _entries.length - _maxEntries);
    }
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(_prefsKey, _entries);
  }

  static Future<List<String>> read() async {
    await _ensureLoaded();
    return List.unmodifiable(_entries);
  }

  static Future<void> clear() async {
    _entries.clear();
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_prefsKey);
  }
}
