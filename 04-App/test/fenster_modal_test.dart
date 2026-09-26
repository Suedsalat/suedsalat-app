import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:suedsalat_app/widgets/content_menu_button.dart';

/// Fenster schliessen sich nur ueber ihre Knoepfe, nicht per Tipp daneben
/// (Wunsch Thorsten, 26.09.2026).
void main() {
  Future<void> oeffnen(WidgetTester tester) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => Center(
            child: TextButton(
              onPressed: () => showReportSheet(context, 'event', 1),
              child: const Text('Melden'),
            ),
          ),
        ),
      ),
    ));
    await tester.tap(find.text('Melden'));
    await tester.pumpAndSettle();
    expect(find.text('Meldung senden'), findsOneWidget);
  }

  testWidgets('Melde-Fenster bleibt beim Tipp daneben offen', (tester) async {
    await oeffnen(tester);
    await tester.tapAt(const Offset(10, 10));
    await tester.pumpAndSettle();
    expect(find.text('Meldung senden'), findsOneWidget);
  });

  testWidgets('Melde-Fenster schliesst mit Abbrechen', (tester) async {
    await oeffnen(tester);
    await tester.tap(find.text('Abbrechen'));
    await tester.pumpAndSettle();
    expect(find.text('Meldung senden'), findsNothing);
  });
}
