import 'package:shared_preferences/shared_preferences.dart';

/// Merkt sich lokal auf dem Geraet, welche Folgen (per GUID) bereits
/// zu Ende gehoert wurden.
class ListenedEpisodesService {
  static const _key = 'listened_episode_guids';

  static Future<Set<String>> getListened() async {
    final prefs = await SharedPreferences.getInstance();
    return (prefs.getStringList(_key) ?? []).toSet();
  }

  static Future<void> markListened(String guid) async {
    final prefs = await SharedPreferences.getInstance();
    final current = (prefs.getStringList(_key) ?? []).toSet();
    if (current.add(guid)) {
      await prefs.setStringList(_key, current.toList());
    }
  }
}
