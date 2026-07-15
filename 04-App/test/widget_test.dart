import 'package:flutter_test/flutter_test.dart';

import 'package:suedsalat_app/main.dart';

void main() {
  testWidgets('App startet und zeigt den Splash-Screen', (WidgetTester tester) async {
    await tester.pumpWidget(const SuedsalatApp());
    await tester.pump();

    expect(find.byType(SuedsalatApp), findsOneWidget);
  });
}
