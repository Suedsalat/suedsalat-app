import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:suedsalat_app/screens/gallery/gallery_video_page.dart';
import 'package:suedsalat_app/screens/gallery/photo_viewer_screen.dart';
import 'package:suedsalat_app/services/account_service.dart';

/// Videos im Galerie-Viewer enden oberhalb der Beschriftung (Text, „Foto von“, Datum,
/// Kommentare) - sonst waere die Video-Steuerung unten bei Hochkant-Videos verdeckt und nicht
/// antippbar. Fotos laufen dagegen weiter bis zum unteren Rand.
void main() {
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await AccountService.instance.load();
  });

  Future<void> oeffnen(WidgetTester tester, List<PhotoViewerItem> items) async {
    tester.view.physicalSize = const Size(800, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(home: PhotoViewerScreen.gallery(items: items, initialIndex: 0)));
    await tester.pump();
    await tester.pump();
  }

  testWidgets('Video endet oberhalb der Beschriftung mit Kommentarzeile', (tester) async {
    await oeffnen(tester, const [
      PhotoViewerItem(
        imageUrl: 'https://x.invalid/video.mp4',
        description: 'Hinter den Kulissen\nzweite Zeile',
        submittedByName: 'Inga',
        isVideo: true,
        contentId: 5,
        commentCount: 2,
      ),
    ]);
    final video = tester.getRect(find.byType(GalleryVideoPage));
    final beschriftung = tester.getRect(find.textContaining('Hinter den Kulissen'));
    final kommentare = tester.getRect(find.text('2 Kommentare'));
    expect(video.bottom, lessThanOrEqualTo(beschriftung.top), reason: 'Beschriftung liegt nicht ueber dem Video');
    expect(video.bottom, lessThanOrEqualTo(kommentare.top));
    expect(video.bottom, greaterThan(1000), reason: 'Video behaelt den restlichen Platz');
  });

  testWidgets('Video ohne Beschriftung nutzt die volle Hoehe', (tester) async {
    await oeffnen(tester, const [PhotoViewerItem(imageUrl: 'https://x.invalid/video.mp4', isVideo: true)]);
    expect(tester.getRect(find.byType(GalleryVideoPage)).bottom, 1600);
  });
}
