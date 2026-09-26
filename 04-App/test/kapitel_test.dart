import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:suedsalat_app/models/chapter.dart';
import 'package:suedsalat_app/models/episode.dart';
import 'package:suedsalat_app/widgets/chapter_list.dart';

/// Kapitel aus der Beschreibung - dieselben Regeln wie Homepage::splitChapters auf dem Server.
void main() {
  const beschreibung = '''
        Wir reden über Urlaub und Filme.
        Und noch ein zweiter Satz.

        Kapitel:
        00:00 Begrüßung
        12:30 Urlaub
        1:02:03 Verabschiedung
      ''';
  final k = EpisodeChapters.parse(beschreibung);

  test('Text ohne Kapitelzeilen, Kapitel mit Zeit und Titel', () {
    expect(k.text, 'Wir reden über Urlaub und Filme. Und noch ein zweiter Satz.');
    expect(k.chapters.map((c) => c.start.inSeconds), [0, 750, 3723]);
    expect(k.chapters.map((c) => c.title), ['Begrüßung', 'Urlaub', 'Verabschiedung']);
  });

  test('einzelne Uhrzeit ist kein Kapitel, ohne Kapitel bleibt alles Text', () {
    final e = EpisodeChapters.parse('Treffen um\n12:30 Uhr am Rathaus.');
    expect(e.chapters, isEmpty);
    expect(e.text, 'Treffen um 12:30 Uhr am Rathaus.');
    expect(EpisodeChapters.parse(null).text, '');
  });

  test('laufendes Kapitel', () {
    expect(k.at(const Duration(minutes: 5))?.title, 'Begrüßung');
    expect(k.at(const Duration(minutes: 12, seconds: 30))?.title, 'Urlaub');
    expect(k.at(const Duration(hours: 2))?.title, 'Verabschiedung');
  });

  test('Weiter springt zum naechsten Kapitel, hinter dem letzten gibt es keins', () {
    expect(k.nextStart(const Duration(minutes: 5)), const Duration(seconds: 750));
    expect(k.nextStart(const Duration(hours: 1, minutes: 10)), isNull);
  });

  test('Zurueck: an den Kapitelanfang, direkt danach ins vorige', () {
    expect(k.previousStart(const Duration(minutes: 20)), const Duration(seconds: 750));
    expect(k.previousStart(const Duration(seconds: 752)), Duration.zero);
    expect(k.previousStart(const Duration(seconds: 1)), Duration.zero);
  });

  test('pro Folge zwischengespeichert', () {
    final folge = Episode(guid: 'g', title: 't', description: beschreibung, audioUrl: 'a', pubDate: DateTime(2026));
    expect(identical(EpisodeChapters.of(folge), EpisodeChapters.of(folge)), isTrue);
  });

  testWidgets('Kapitelliste: markiert das laufende, Antippen meldet das Kapitel', (tester) async {
    Chapter? getippt;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: ChapterList(chapters: k.chapters, currentIndex: 1, onTap: (c) => getippt = c),
      ),
    ));
    expect(find.text('00:00'), findsOneWidget);
    expect(find.text('12:30'), findsOneWidget);
    expect(find.text('1:02:03'), findsOneWidget);
    expect(tester.widget<ListTile>(find.widgetWithText(ListTile, 'Urlaub')).selected, isTrue);
    expect(tester.widget<ListTile>(find.widgetWithText(ListTile, 'Begrüßung')).selected, isFalse);
    await tester.tap(find.text('Verabschiedung'));
    expect(getippt?.start, const Duration(seconds: 3723));
  });
}
