import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:suedsalat_app/models/bonus_item.dart';
import 'package:suedsalat_app/screens/bonus/bonus_screen.dart';
import 'package:suedsalat_app/screens/start/start_screen.dart';
import 'package:suedsalat_app/services/account_service.dart';
import 'package:suedsalat_app/services/api_service.dart';

class FakeApi extends ApiService {
  FakeApi(this.items);
  final List<BonusItem> items;

  @override
  Future<List<BonusItem>> fetchBonus() async => items;
}

/// Bonus und Outtakes: nur mit Hoererkonto sichtbar, Liste mit Titel, Datum und Text.
void main() {
  Future<void> startseite(WidgetTester tester) async {
    tester.view.physicalSize = const Size(800, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: StartScreen(onNavigateToTab: (_) {}, onRefresh: () async {})),
    ));
    await tester.pump();
  }

  testWidgets('Gast sieht keine Bonus-Kachel', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await AccountService.instance.load();
    await startseite(tester);
    expect(find.text('Galerie'), findsOneWidget);
    expect(find.text('Bonus und Outtakes'), findsNothing);
  });

  testWidgets('Angemeldet: Bonus-Kachel direkt nach der Galerie', (tester) async {
    SharedPreferences.setMockInitialValues({
      'listener_profile': jsonEncode({
        'id': 7, 'first_name': 'Angela', 'last_name': 'Test', 'email': 'a@example.org', 'nickname': 'Angela', 'blocked': false,
      }),
    });
    await AccountService.instance.load();
    await startseite(tester);
    expect(find.text('Bonus und Outtakes'), findsOneWidget);
    expect(
      find.byWidgetPredicate((w) => w is Image && w.image is AssetImage && (w.image as AssetImage).assetName == 'assets/images/outtakes.png'),
      findsOneWidget,
      reason: 'Thorstens Outtakes-Symbol auf der Kachel',
    );
    expect(tester.getTopLeft(find.text('Bonus und Outtakes')).dy, greaterThan(tester.getTopLeft(find.text('Galerie')).dy));
    expect(tester.getTopLeft(find.text('Bonus und Outtakes')).dy, lessThan(tester.getTopLeft(find.text('Newsletter')).dy));
  });

  testWidgets('Liste zeigt Titel, Datum und Text', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: BonusScreen(
        api: FakeApi([
          BonusItem(id: 3, title: 'Outtakes Folge 36', description: 'Was nicht in die Folge kam.', audioUrl: 'https://x.invalid/a.mp3', publishedAt: DateTime(2026, 9, 20)),
          BonusItem(id: 2, title: 'Versprecher-Special', audioUrl: 'https://x.invalid/b.mp3', publishedAt: DateTime(2026, 8, 1)),
        ]),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Outtakes Folge 36'), findsOneWidget);
    expect(find.text('20.09.2026'), findsOneWidget);
    expect(find.text('Was nicht in die Folge kam.'), findsOneWidget);
    expect(find.text('Versprecher-Special'), findsOneWidget);
  });

  testWidgets('leere Liste: freundlicher Hinweis', (tester) async {
    await tester.pumpWidget(MaterialApp(home: BonusScreen(api: FakeApi(const []))));
    await tester.pumpAndSettle();
    expect(find.textContaining('bald Extras'), findsOneWidget);
  });

  test('Bonus laeuft im Player unter eigener Kennung', () {
    final e = BonusItem(id: 5, title: 'x', audioUrl: 'https://x.invalid/c.mp3', publishedAt: DateTime(2026)).toEpisode();
    expect(e.guid, 'bonus-5');
    expect(e.audioUrl, 'https://x.invalid/c.mp3');
  });
}
