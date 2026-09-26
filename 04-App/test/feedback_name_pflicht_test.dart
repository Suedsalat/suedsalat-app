import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:suedsalat_app/screens/feedback/feedback_screen.dart';
import 'package:suedsalat_app/services/account_service.dart';

/// Feedback-Formular seit 2.0: Gaeste schicken nur schriftliches Feedback und Fragen (mit Namen,
/// Pflicht), alles andere braucht ein Konto. Angemeldete schreiben unter ihrem Spitznamen -
/// ein Namensfeld gibt es fuer sie nicht.
void main() {
  Future<void> alsGast() async {
    SharedPreferences.setMockInitialValues({});
    await AccountService.instance.load();
  }

  Future<void> alsHoerer({bool gesperrt = false}) async {
    SharedPreferences.setMockInitialValues({
      'listener_profile': jsonEncode({
        'id': 7,
        'first_name': 'Angela',
        'last_name': 'Test',
        'email': 'angela@example.org',
        'nickname': 'Angela',
        'blocked': gesperrt,
      }),
    });
    await AccountService.instance.load();
  }

  Future<void> oeffnen(WidgetTester tester, String typ) async {
    // Das Standard-Testfenster (800x600) ist zu niedrig, der Absenden-Knopf laege ausserhalb.
    tester.view.physicalSize = const Size(800, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(home: FeedbackScreen(initialType: typ)));
    await tester.pumpAndSettle();
  }

  Future<void> absenden(WidgetTester tester) async {
    final senden = find.widgetWithText(ElevatedButton, 'Absenden');
    await tester.ensureVisible(senden);
    await tester.tap(senden);
    await tester.pumpAndSettle();
  }

  const gastArten = ['allgemein', 'frage'];
  const kontoArten = ['sprachnachricht', 'termin_tipp', 'kino_tipp', 'location_tipp', 'foto_vorschlag'];

  for (final typ in gastArten) {
    testWidgets('Gast, $typ: ohne Namen kommt die Pflichtfeld-Meldung', (tester) async {
      await alsGast();
      await oeffnen(tester, typ);
      await absenden(tester);
      expect(find.text('Bitte gib deinen Namen ein.'), findsOneWidget);
    });
  }

  for (final typ in kontoArten) {
    testWidgets('Gast, $typ: Hinweis zum Registrieren, danach keine Art gewaehlt', (tester) async {
      await alsGast();
      await oeffnen(tester, typ);
      expect(find.text('Zum Mitmachen kostenlos registrieren'), findsOneWidget);
      await tester.tap(find.text('Später'));
      await tester.pumpAndSettle();
      expect(find.text('Bitte hier auswählen'), findsOneWidget);
    });

    testWidgets('Angemeldet, $typ: kein Namensfeld, Spitzname wird genannt', (tester) async {
      await alsHoerer();
      await oeffnen(tester, typ);
      expect(find.text('Zum Mitmachen kostenlos registrieren'), findsNothing);
      expect(find.widgetWithText(TextFormField, 'Dein Name'), findsNothing);
      expect(find.textContaining('Du schreibst als Angela'), findsOneWidget);
    });
  }

  testWidgets('Angemeldet: beim Tipp steht, dass der Spitzname oeffentlich erscheint', (tester) async {
    await alsHoerer();
    await oeffnen(tester, 'kino_tipp');
    expect(find.textContaining('„Tipp von Angela“'), findsOneWidget);
  });

  testWidgets('Angemeldet, allgemein: absenden ohne Namens-Pflichtmeldung', (tester) async {
    await alsHoerer();
    await oeffnen(tester, 'allgemein');
    await absenden(tester);
    expect(find.text('Bitte gib deinen Namen ein.'), findsNothing);
    expect(find.text('Bitte gib eine Nachricht ein.'), findsOneWidget);
  });

  testWidgets('Gesperrt: Tipp einreichen zeigt den Sperrhinweis', (tester) async {
    await alsHoerer(gesperrt: true);
    await oeffnen(tester, 'kino_tipp');
    expect(find.text('Dein Konto ist für Beiträge gesperrt'), findsOneWidget);
  });

  testWidgets('Gast, allgemein: das Feld verspricht keine Veroeffentlichung', (tester) async {
    await alsGast();
    await oeffnen(tester, 'allgemein');
    expect(find.textContaining('Steht öffentlich'), findsNothing);
  });

  // Gaeste sehen nur, was sie auch duerfen (Wunsch Thorsten 26.09.2026).
  testWidgets('Gast: Auswahl zeigt nur Feedback und Frage, kein Foto-Knopf', (tester) async {
    await alsGast();
    await oeffnen(tester, 'allgemein');
    await tester.tap(find.text('Allgemeines Feedback'));
    await tester.pumpAndSettle();
    expect(find.text('Frage einreichen'), findsWidgets);
    for (final art in ['Veranstaltungstipp', 'Filmtipp', 'Locationtipp', 'Fotoempfehlung', 'Sprachnachricht']) {
      expect(find.text(art), findsNothing, reason: art);
    }
    await tester.tap(find.text('Allgemeines Feedback').last);
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.add_a_photo), findsNothing);
  });

  testWidgets('Angemeldet: Auswahl zeigt alle Arten und den Foto-Knopf', (tester) async {
    await alsHoerer();
    await oeffnen(tester, 'allgemein');
    expect(find.byIcon(Icons.add_a_photo), findsOneWidget);
    await tester.tap(find.text('Allgemeines Feedback'));
    await tester.pumpAndSettle();
    for (final art in ['Veranstaltungstipp', 'Filmtipp', 'Locationtipp', 'Fotoempfehlung', 'Sprachnachricht']) {
      expect(find.text(art), findsWidgets, reason: art);
    }
  });

  testWidgets('Gast: gemerkter Name ist vorausgefuellt', (tester) async {
    SharedPreferences.setMockInitialValues({'guest_sender_name': 'Inga'});
    await AccountService.instance.load();
    await oeffnen(tester, 'frage');
    expect(find.widgetWithText(TextFormField, 'Inga'), findsOneWidget);
  });
}
