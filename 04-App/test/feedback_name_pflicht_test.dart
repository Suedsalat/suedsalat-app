import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:suedsalat_app/screens/feedback/feedback_screen.dart';

/// Der Name ist bei allen Einsendungen Pflicht - einheitlich, weil vieles (Tipps,
/// Rezensionen, spaeter Foto-Kommentare) mit dem Namen oeffentlich erscheint.
void main() {
  Future<void> absendenOhneName(WidgetTester tester, String typ) async {
    // Das Standard-Testfenster (800x600) ist zu niedrig, der Absenden-Knopf laege ausserhalb.
    tester.view.physicalSize = const Size(800, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(home: FeedbackScreen(initialType: typ)));
    await tester.pump();
    final senden = find.widgetWithText(ElevatedButton, 'Absenden');
    await tester.ensureVisible(senden);
    await tester.tap(senden);
    await tester.pump();
  }

  const alleArten = [
    'allgemein',
    'frage',
    'sprachnachricht',
    'termin_tipp',
    'kino_tipp',
    'location_tipp',
    'foto_vorschlag',
  ];

  for (final typ in alleArten) {
    testWidgets('$typ: ohne Namen kommt die Pflichtfeld-Meldung', (tester) async {
      await absendenOhneName(tester, typ);
      expect(find.text('Bitte gib deinen Namen ein.'), findsOneWidget);
    });
  }

  testWidgets('Bei Tipps weist das Feld auf die oeffentliche Anzeige hin', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: FeedbackScreen(initialType: 'kino_tipp')));
    await tester.pump();
    expect(find.textContaining('Steht öffentlich beim Tipp'), findsOneWidget);
  });

  testWidgets('Bei allgemeinem Feedback verspricht das Feld keine Veroeffentlichung', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: FeedbackScreen(initialType: 'allgemein')));
    await tester.pump();
    expect(find.textContaining('Steht öffentlich'), findsNothing);
  });
}
