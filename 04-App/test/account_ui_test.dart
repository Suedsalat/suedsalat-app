import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:suedsalat_app/screens/account/stats_consent_dialog.dart';
import 'package:suedsalat_app/services/stats_consent_service.dart';
import 'package:suedsalat_app/widgets/content_menu_button.dart';

/// App 2.0: Statistik-Einwilligung und Meldemenue.
void main() {
  Future<void> dialogOeffnen(WidgetTester tester) async {
    SharedPreferences.setMockInitialValues({});
    await StatsConsentService.instance.load();
    await tester.pumpWidget(MaterialApp(
      home: Builder(
        builder: (context) => Scaffold(
          body: ElevatedButton(onPressed: () => showStatsConsentDialog(context), child: const Text('los')),
        ),
      ),
    ));
    await tester.tap(find.text('los'));
    await tester.pumpAndSettle();
  }

  testWidgets('Einwilligung: ohne Entscheidung fragt die App, nichts wird gezaehlt', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await StatsConsentService.instance.load();
    expect(StatsConsentService.instance.needsDecision, isTrue);
    expect(StatsConsentService.instance.granted, isFalse);
  });

  testWidgets('Einwilligung: Ablehnen und Zustimmen sind gleich gross und gleich gestaltet', (tester) async {
    await dialogOeffnen(tester);
    final nein = find.widgetWithText(ElevatedButton, 'Nein, danke');
    final ja = find.widgetWithText(ElevatedButton, 'Ja, gern');
    expect(nein, findsOneWidget);
    expect(ja, findsOneWidget);
    expect(tester.getSize(nein), tester.getSize(ja));
  });

  testWidgets('Einwilligung: Tippen daneben schliesst den Dialog nicht', (tester) async {
    await dialogOeffnen(tester);
    await tester.tapAt(const Offset(5, 5));
    await tester.pumpAndSettle();
    expect(find.text('Ja, gern'), findsOneWidget);
  });

  testWidgets('Einwilligung: Ablehnen wird gespeichert, danach keine erneute Frage', (tester) async {
    await dialogOeffnen(tester);
    await tester.tap(find.text('Nein, danke'));
    await tester.pumpAndSettle();
    expect(StatsConsentService.instance.granted, isFalse);
    expect(StatsConsentService.instance.needsDecision, isFalse);
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('stats_consent'), 'denied');
    expect(prefs.getString('stats_consent_version'), StatsConsentService.textVersion);
  });

  testWidgets('Einwilligung: alte Textfassung zaehlt nicht, App fragt erneut', (tester) async {
    SharedPreferences.setMockInitialValues({'stats_consent': 'granted', 'stats_consent_version': '2025-01'});
    await StatsConsentService.instance.load();
    expect(StatsConsentService.instance.granted, isFalse);
    expect(StatsConsentService.instance.needsDecision, isTrue);
  });

  testWidgets('Meldemenue: bei eigenen Beitraegen gibt es keins', (tester) async {
    await tester.pumpWidget(const MaterialApp(
      home: Scaffold(body: ContentMenuButton(contentType: 'review', contentId: 1, isOwn: true)),
    ));
    expect(find.byIcon(Icons.more_vert), findsNothing);
  });

  testWidgets('Meldemenue: Gast sieht Melden, aber nicht Nutzer ausblenden', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(const MaterialApp(
      home: Scaffold(body: ContentMenuButton(contentType: 'review', contentId: 1, allowHideAuthor: true)),
    ));
    await tester.tap(find.byIcon(Icons.more_vert));
    await tester.pumpAndSettle();
    expect(find.text('Melden'), findsOneWidget);
    expect(find.text('Nutzer ausblenden'), findsNothing);
  });

  testWidgets('Meldeformular: ohne Grund wird nicht gesendet', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: Builder(
        builder: (context) => Scaffold(
          body: ElevatedButton(onPressed: () => showReportSheet(context, 'review', 1), child: const Text('melden')),
        ),
      ),
    ));
    await tester.tap(find.text('melden'));
    await tester.pumpAndSettle();
    for (final grund in kReportCategories.values) {
      expect(find.text(grund), findsOneWidget);
    }
    await tester.tap(find.text('Meldung senden'));
    await tester.pumpAndSettle();
    expect(find.text('Bitte wähle einen Grund.'), findsOneWidget);
  });
}
