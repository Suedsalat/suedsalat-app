import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:suedsalat_app/screens/account/register_screen.dart';

/// Registrieren: Wer schon ein Konto hat, findet "anmelden" gleich oben; das Formularende
/// liegt ueber der Navigationsleiste des Handys (Fund Thorsten, 26.09.2026).
void main() {
  testWidgets('Anmelden-Link steht ueber dem Formular', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: RegisterScreen()));
    await tester.pumpAndSettle();
    final link = tester.getTopLeft(find.text('Ich habe schon ein Konto – anmelden')).dy;
    expect(link, lessThan(tester.getTopLeft(find.widgetWithText(TextFormField, 'Vorname')).dy));
  });

  testWidgets('Code anfordern laesst sich ueber die Handy-Leiste scrollen', (tester) async {
    tester.view.physicalSize = const Size(800, 900);
    tester.view.devicePixelRatio = 1.0;
    tester.view.padding = const FakeViewPadding(bottom: 60);
    tester.view.viewPadding = const FakeViewPadding(bottom: 60);
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MaterialApp(home: RegisterScreen()));
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -3000));
    await tester.pumpAndSettle();

    final knopf = tester.getBottomLeft(find.widgetWithText(ElevatedButton, 'Code anfordern')).dy;
    expect(knopf, lessThanOrEqualTo(900 - 60));
  });
}
