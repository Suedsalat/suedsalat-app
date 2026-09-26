import 'episode.dart';

/// Kapitel einer Folge. Sie stehen im RSS-Feed als Zeilen "00:00 Begruessung" am Ende der
/// Beschreibung (so liest sie auch Spotify; gepflegt im Admin-Bereich unter "Folgen").
class Chapter {
  final Duration start;
  final String title;

  const Chapter(this.start, this.title);
}

/// Beschreibung ohne Kapitelzeilen und die Kapitel selbst.
class EpisodeChapters {
  final String text;
  final List<Chapter> chapters;

  const EpisodeChapters(this.text, this.chapters);

  static final _line = RegExp(r'^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})\s+[-–:]?\s*(.+)$');
  static final _heading = RegExp(r'^Kapitel\s*:?$', caseSensitive: false);
  static final _cache = Expando<EpisodeChapters>();

  /// Wie Homepage::splitChapters auf dem Server: erst ab zwei Zeitzeilen ist es eine
  /// Kapitelliste, eine einzelne Uhrzeit im Text bleibt Text.
  factory EpisodeChapters.parse(String? description) {
    final lines = (description ?? '').split(RegExp(r'\r?\n')).map((l) => l.trim()).toList();
    final chapters = <Chapter>[];
    final textLines = <String>[];
    for (final line in lines) {
      final m = _line.firstMatch(line);
      if (m != null) {
        final seconds = int.parse(m.group(1) ?? '0') * 3600 + int.parse(m.group(2)!) * 60 + int.parse(m.group(3)!);
        chapters.add(Chapter(Duration(seconds: seconds), m.group(4)!.trim()));
      } else if (!_heading.hasMatch(line)) {
        textLines.add(line);
      }
    }
    String collapse(String s) => s.replaceAll(RegExp(r'\s+'), ' ').trim();
    if (chapters.length < 2) {
      return EpisodeChapters(collapse(description ?? ''), const []);
    }
    chapters.sort((a, b) => a.start.compareTo(b.start));
    return EpisodeChapters(collapse(textLines.join(' ')), List.unmodifiable(chapters));
  }

  /// Zwischengespeichert pro Folge - der Player baut sich bei jeder Positionsaenderung neu.
  static EpisodeChapters of(Episode episode) => _cache[episode] ??= EpisodeChapters.parse(episode.description);

  /// Index des Kapitels, das an [position] laeuft, oder -1.
  int indexAt(Duration position) {
    var index = -1;
    for (var i = 0; i < chapters.length; i++) {
      if (chapters[i].start <= position) index = i;
    }
    return index;
  }

  Chapter? at(Duration position) {
    final i = indexAt(position);
    return i >= 0 ? chapters[i] : null;
  }

  /// Ziel fuer "Weiter": Beginn des naechsten Kapitels, null wenn es keins mehr gibt.
  Duration? nextStart(Duration position) {
    final i = indexAt(position);
    return i + 1 < chapters.length ? chapters[i + 1].start : null;
  }

  /// Ziel fuer "Zurueck": Anfang des laufenden Kapitels - oder, wenn man gerade erst
  /// (weniger als 3 Sekunden) drin ist, der Anfang des vorigen. Wie bei Musik-Playern.
  Duration previousStart(Duration position) {
    final i = indexAt(position);
    if (i < 0) return Duration.zero;
    if (position - chapters[i].start > const Duration(seconds: 3) || i == 0) return chapters[i].start;
    return chapters[i - 1].start;
  }
}
