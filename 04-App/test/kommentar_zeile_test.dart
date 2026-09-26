import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:suedsalat_app/models/photo_comment.dart';
import 'package:suedsalat_app/screens/gallery/photo_comments_sheet.dart';
import 'package:suedsalat_app/screens/gallery/photo_viewer_screen.dart';
import 'package:suedsalat_app/services/account_service.dart';
import 'package:suedsalat_app/services/api_service.dart';

/// Funde aus Thorstens Test mit "Südsalat TEST" (26.09.2026):
/// - Gaeste sahen unter dem Foto "Kommentieren", obwohl sie nur lesen koennen.
/// - Die Navigationsleiste des Handys lag ueber dem Hinweis unten im Kommentarfenster.
class FakeApi extends ApiService {
  @override
  Future<List<PhotoComment>> fetchPhotoComments(int photoId) async => const [];
}

void main() {
  Future<void> gast() async {
    SharedPreferences.setMockInitialValues({});
    await AccountService.instance.load();
  }

  Future<void> hoerer() async {
    SharedPreferences.setMockInitialValues({
      'listener_profile': jsonEncode({
        'id': 7, 'first_name': 'A', 'last_name': 'B', 'email': 'a@example.org', 'nickname': 'Angela', 'blocked': false,
      }),
    });
    await AccountService.instance.load();
  }

  Future<void> foto(WidgetTester tester) async {
    tester.view.physicalSize = const Size(800, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(
      home: PhotoViewerScreen.gallery(
        items: const [PhotoViewerItem(imageUrl: 'https://x.invalid/1.mp4', description: 'Ein Video', contentId: 1, isVideo: true)],
        initialIndex: 0,
      ),
    ));
    await tester.pump();
  }

  testWidgets('Gast: unter dem Foto steht "Kommentare", nicht "Kommentieren"', (tester) async {
    await gast();
    await foto(tester);
    expect(find.text('Kommentare'), findsOneWidget);
    expect(find.text('Kommentieren'), findsNothing);
  });

  testWidgets('Angemeldet: "Kommentieren"', (tester) async {
    await hoerer();
    await foto(tester);
    expect(find.text('Kommentieren'), findsOneWidget);
  });

  testWidgets('Navigationsleiste verdeckt den Hinweis im Kommentarfenster nicht', (tester) async {
    await gast();
    tester.view.physicalSize = const Size(800, 1600);
    tester.view.devicePixelRatio = 1.0;
    tester.view.padding = const FakeViewPadding(bottom: 60);
    tester.view.viewPadding = const FakeViewPadding(bottom: 60);
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: PhotoCommentsSheet(photoId: 1, api: FakeApi()))));
    await tester.pumpAndSettle();
    final hinweis = tester.getRect(find.text('Kommentieren geht mit einem kostenlosen Hörerkonto.'));
    expect(hinweis.bottom, lessThanOrEqualTo(1600 - 60), reason: 'Hinweis liegt oberhalb der Navigationsleiste');
    expect(tester.getRect(find.text('Mitmachen')).bottom, lessThanOrEqualTo(1600 - 60));
  });
}
