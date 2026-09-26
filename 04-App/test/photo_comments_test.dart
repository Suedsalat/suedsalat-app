import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:suedsalat_app/models/photo_comment.dart';
import 'package:suedsalat_app/screens/gallery/photo_comments_sheet.dart';
import 'package:suedsalat_app/services/account_service.dart';
import 'package:suedsalat_app/services/api_service.dart';

/// Schnittstelle ohne Server: merkt sich Kommentare im Speicher.
class FakeApi extends ApiService {
  FakeApi(this.kommentare);

  List<PhotoComment> kommentare;
  String? zuletztGesendet;
  int? zuletztGeloescht;

  @override
  Future<List<PhotoComment>> fetchPhotoComments(int photoId) async => kommentare;

  @override
  Future<List<PhotoComment>> postPhotoComment(int photoId, String text) async {
    zuletztGesendet = text;
    kommentare = [
      ...kommentare,
      PhotoComment(id: 99, authorName: 'Angela', text: text, createdAt: DateTime(2026, 9, 25, 20), isOwn: true),
    ];
    return kommentare;
  }

  @override
  Future<void> deletePhotoComment(int commentId) async {
    zuletztGeloescht = commentId;
    kommentare = kommentare.where((k) => k.id != commentId).toList();
  }
}

PhotoComment kommentar(int id, String name, String text, {bool eigen = false}) =>
    PhotoComment(id: id, authorName: name, text: text, createdAt: DateTime(2026, 9, 24, 18, 30), isOwn: eigen);

void main() {
  Future<void> alsGast() async {
    SharedPreferences.setMockInitialValues({});
    await AccountService.instance.load();
  }

  Future<void> alsHoerer({bool gesperrt = false}) async {
    SharedPreferences.setMockInitialValues({
      'listener_profile': jsonEncode({
        'id': 7, 'first_name': 'Angela', 'last_name': 'Test', 'email': 'a@example.org', 'nickname': 'Angela', 'blocked': gesperrt,
      }),
    });
    await AccountService.instance.load();
  }

  Future<void> oeffnen(WidgetTester tester, FakeApi api) async {
    tester.view.physicalSize = const Size(800, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: PhotoCommentsSheet(photoId: 1, api: api))));
    await tester.pumpAndSettle();
  }

  testWidgets('Gast sieht Kommentare mit Namen, aber kein Eingabefeld', (tester) async {
    await alsGast();
    await oeffnen(tester, FakeApi([kommentar(1, 'Bea', 'Schöner Abend!')]));
    expect(find.text('Bea'), findsOneWidget);
    expect(find.text('Schöner Abend!'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
    expect(find.text('Kommentieren geht mit einem kostenlosen Hörerkonto.'), findsOneWidget);
    expect(find.byIcon(Icons.more_vert), findsOneWidget, reason: 'Melden geht auch fuer Gaeste');
  });

  testWidgets('Gast ohne Kommentare: keine Aufforderung zum Schreiben', (tester) async {
    await alsGast();
    await oeffnen(tester, FakeApi([]));
    expect(find.text('Noch keine Kommentare.'), findsOneWidget);
    expect(find.text('Noch keine Kommentare – schreib den ersten!'), findsNothing);
  });

  testWidgets('Angemeldet: kommentieren unter dem eigenen Spitznamen', (tester) async {
    await alsHoerer();
    final api = FakeApi([]);
    await oeffnen(tester, api);
    expect(find.text('Noch keine Kommentare – schreib den ersten!'), findsOneWidget);
    expect(find.text('Kommentar als Angela …'), findsOneWidget);
    await tester.enterText(find.byType(TextField), '  Was für ein Fest!  ');
    await tester.tap(find.byIcon(Icons.send));
    await tester.pumpAndSettle();
    expect(api.zuletztGesendet, 'Was für ein Fest!');
    expect(find.text('Was für ein Fest!'), findsOneWidget);
    expect(find.byIcon(Icons.delete_outline), findsOneWidget, reason: 'eigener Kommentar: Loeschen statt Melden');
  });

  testWidgets('Eigenen Kommentar loeschen', (tester) async {
    await alsHoerer();
    final api = FakeApi([kommentar(5, 'Angela', 'Mein Kommentar', eigen: true), kommentar(6, 'Bea', 'Fremder')]);
    await oeffnen(tester, api);
    expect(find.byIcon(Icons.more_vert), findsOneWidget, reason: 'nur beim fremden Kommentar');
    await tester.tap(find.byIcon(Icons.delete_outline));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Löschen'));
    await tester.pumpAndSettle();
    expect(api.zuletztGeloescht, 5);
    expect(find.text('Mein Kommentar'), findsNothing);
    expect(find.text('Fremder'), findsOneWidget);
  });

  testWidgets('Gesperrt: Hinweis statt Eingabefeld', (tester) async {
    await alsHoerer(gesperrt: true);
    await oeffnen(tester, FakeApi([]));
    expect(find.byType(TextField), findsNothing);
    expect(find.textContaining('für Beiträge gesperrt'), findsOneWidget);
  });
}
