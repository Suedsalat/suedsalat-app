import 'package:flutter_test/flutter_test.dart';
import 'package:suedsalat_app/models/episode.dart';
import 'package:suedsalat_app/services/audio_handler.dart';
import 'package:suedsalat_app/services/carplay_service.dart';

/// CarPlay-Liste: was iOS pro Folge bekommt (ios/Runner/CarPlaySceneDelegate.swift liest genau
/// diese Schluessel: guid, title, detail, imageUrl, playing, progress).
void main() {
  Episode folge(String guid, {String? bild, String? dauer}) => Episode(
        guid: guid,
        title: 'Folge $guid',
        audioUrl: 'https://x.invalid/$guid.mp3',
        imageUrl: bild,
        duration: dauer,
        pubDate: DateTime(2026, 9, 18),
      );

  test('neue Folge: Datum, Ersatzcover, kein Fortschritt', () {
    final items = CarPlayService.buildItems([folge('a')], listened: {}, positions: {});
    expect(items.single, {
      'guid': 'a',
      'title': 'Folge a',
      'detail': '18.09.2026',
      'imageUrl': SuedsalatAudioHandler.fallbackArtUrl,
      'playing': false,
    });
  });

  test('angefangen: "Weiter bei" und Fortschritt aus der Laenge', () {
    final items = CarPlayService.buildItems(
      [folge('a', bild: 'https://x.invalid/a.jpg', dauer: '1:00:00')],
      listened: {},
      positions: {'a': const Duration(minutes: 15)},
    );
    expect(items.single['detail'], '18.09.2026 · Weiter bei 15:00');
    expect(items.single['progress'], 0.25);
    expect(items.single['imageUrl'], 'https://x.invalid/a.jpg');
  });

  test('gehoert: voll, laufende Folge markiert', () {
    final items = CarPlayService.buildItems(
      [folge('a'), folge('b')],
      listened: {'a'},
      positions: {},
      currentGuid: 'b',
    );
    expect(items[0]['detail'], '18.09.2026 · Gehört');
    expect(items[0]['progress'], 1.0);
    expect(items[1]['playing'], isTrue);
    expect(items[0]['playing'], isFalse);
  });

  test('Laenge aus dem Feed in allen ueblichen Schreibweisen', () {
    expect(CarPlayService.parseDuration('1:02:03'), const Duration(hours: 1, minutes: 2, seconds: 3));
    expect(CarPlayService.parseDuration('45:10'), const Duration(minutes: 45, seconds: 10));
    expect(CarPlayService.parseDuration('3600'), const Duration(hours: 1));
    expect(CarPlayService.parseDuration(null), isNull);
    expect(CarPlayService.parseDuration(''), isNull);
    expect(CarPlayService.parseDuration('ca. 1 Stunde'), isNull);
  });
}
