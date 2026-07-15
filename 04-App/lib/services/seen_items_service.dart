import 'package:shared_preferences/shared_preferences.dart';

/// Merkt sich lokal auf dem Geraet, welche Inhalte (Folgen, Termine, Fotos)
/// der Nutzer bereits geoeffnet/gesehen hat, um neue Inhalte mit einem
/// gruenen Punkt zu markieren. Unabhaengig vom "gehoert"-Status in
/// [ListenedEpisodesService], der erst nach vollstaendigem Abspielen gesetzt wird.
class SeenItemsService {
  static Future<Set<String>> getSeen(String category) async {
    final prefs = await SharedPreferences.getInstance();
    return (prefs.getStringList('seen_${category}_ids') ?? []).toSet();
  }

  static Future<void> markSeen(String category, String id) async {
    final prefs = await SharedPreferences.getInstance();
    final key = 'seen_${category}_ids';
    final current = (prefs.getStringList(key) ?? []).toSet();
    if (current.add(id)) {
      await prefs.setStringList(key, current.toList());
    }
  }

  /// Markiert beim allerersten Aufruf fuer eine Kategorie alle aktuell
  /// vorhandenen IDs als gesehen, damit nach dem Feature-Rollout nicht der
  /// komplette Altbestand ploetzlich als "neu" erscheint.
  static Future<void> ensureBaseline(String category, Iterable<String> currentIds) async {
    final prefs = await SharedPreferences.getInstance();
    final baselineKey = 'seen_${category}_baseline_done';
    if (prefs.getBool(baselineKey) == true) return;

    final key = 'seen_${category}_ids';
    final current = (prefs.getStringList(key) ?? []).toSet();
    current.addAll(currentIds);
    await prefs.setStringList(key, current.toList());
    await prefs.setBool(baselineKey, true);
  }
}
