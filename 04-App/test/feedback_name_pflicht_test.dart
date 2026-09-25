import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:suedsalat_app/screens/feedback/feedback_screen.dart';

/// Tipps erscheinen nach der Uebernahme mit "Tipp von ..." in der App, deshalb ist
/// der Name bei allen vier Tipp-Arten Pflicht. Bei allgemeinem Feedback nicht.
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

  for (final typ in ['termin_tipp', 'kino_tipp', 'location_tipp', 'foto_vorschlag']) {
    testWidgets('$typ: ohne Namen kommt die Pflichtfeld-Meldung', (tester) async {
      await absendenOhneName(tester, typ);
      expect(find.text('Bitte gib deinen Namen ein.'), findsOneWidget);
    });
  }

  testWidgets('Allgemeines Feedback: Name bleibt freiwillig', (tester) async {
    await absendenOhneName(tester, 'allgemein');
    expect(find.text('Bitte gib deinen Namen ein.'), findsNothing);
  });
}
