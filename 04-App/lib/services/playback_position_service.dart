import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Merkt sich lokal auf dem Geraet, bis wohin eine Folge gehoert wurde (pro GUID), damit sie
/// nach einer Unterbrechung oder einem Neustart der App dort weitergeht. Nichts davon geht an
/// den Server.
class PlaybackPositionService {
  static const _key = 'playback_positions';

  /// Erst ab hier lohnt sich das Merken - wer nach wenigen Sekunden abbricht, faengt neu an.
  static const minPosition = Duration(seconds: 30);

  /// So nah am Ende gilt die Folge als durchgehoert - dann beginnt sie beim naechsten Mal vorn.
  static const endMargin = Duration(seconds: 60);

  /// Beim Weiterhoeren ein paar Sekunden zurueck, damit man wieder in den Satz findet.
  static const rewind = Duration(seconds: 5);

  /// Nur die zuletzt gehoerten Folgen behalten.
  static const maxEntries = 50;

  static Future<Map<String, dynamic>> _all(SharedPreferences prefs) async {
    final raw = prefs.getString(_key);
    if (raw == null) return {};
    try {
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : {};
    } catch (_) {
      return {};
    }
  }

  /// Alle gemerkten Stellen (fuer den Hinweis „Weiter bei …“ in der Folgenliste).
  static Future<Map<String, Duration>> all() async {
    final prefs = await SharedPreferences.getInstance();
    return {
      for (final e in (await _all(prefs)).entries)
        if (e.value is Map && (e.value as Map)['s'] is int) e.key: Duration(seconds: (e.value as Map)['s'] as int),
    };
  }

  /// Wo es weitergeht, oder null (dann von vorn).
  static Future<Duration?> resumePosition(String guid) async {
    final prefs = await SharedPreferences.getInstance();
    final entry = (await _all(prefs))[guid];
    final seconds = entry is Map ? entry['s'] : null;
    if (seconds is! int) return null;
    final saved = Duration(seconds: seconds);
    if (saved < minPosition) return null;
    final start = saved - rewind;
    return start.isNegative ? Duration.zero : start;
  }

  /// Aktuelle Stelle merken. Zu frueh oder zu nah am Ende: Eintrag entfernen.
  static Future<void> save(String guid, Duration position, Duration duration) async {
    final nearEnd = duration > Duration.zero && position >= duration - endMargin;
    if (position < minPosition || nearEnd) {
      await clear(guid);
      return;
    }
    final prefs = await SharedPreferences.getInstance();
    final all = await _all(prefs);
    all[guid] = {'s': position.inSeconds, 't': DateTime.now().millisecondsSinceEpoch};
    if (all.length > maxEntries) {
      final oldest = all.entries.toList()
        ..sort((a, b) => ((a.value as Map)['t'] as int? ?? 0).compareTo((b.value as Map)['t'] as int? ?? 0));
      for (final e in oldest.take(all.length - maxEntries)) {
        all.remove(e.key);
      }
    }
    await prefs.setString(_key, jsonEncode(all));
  }

  /// "12:34" bzw. "1:02:03" fuer "Weiter bei …".
  static String format(Duration d) {
    String zwei(int n) => n.toString().padLeft(2, '0');
    final h = d.inHours;
    final m = d.inMinutes.remainder(60);
    final s = d.inSeconds.remainder(60);
    return h > 0 ? '$h:${zwei(m)}:${zwei(s)}' : '$m:${zwei(s)}';
  }

  static Future<void> clear(String guid) async {
    final prefs = await SharedPreferences.getInstance();
    final all = await _all(prefs);
    if (all.remove(guid) != null) {
      await prefs.setString(_key, jsonEncode(all));
    }
  }
}
