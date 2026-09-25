import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:suedsalat_app/services/playback_position_service.dart';

/// Wiedergabeposition merken: Folge geht nach Unterbrechung an der letzten Stelle weiter.
void main() {
  const folge = Duration(minutes: 50);

  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('weiter ein paar Sekunden vor der gemerkten Stelle', () async {
    await PlaybackPositionService.save('ep1', const Duration(minutes: 12, seconds: 30), folge);
    expect(await PlaybackPositionService.resumePosition('ep1'), const Duration(minutes: 12, seconds: 25));
    expect((await PlaybackPositionService.all())['ep1'], const Duration(minutes: 12, seconds: 30));
  });

  test('unbekannte Folge beginnt vorn', () async {
    expect(await PlaybackPositionService.resumePosition('neu'), isNull);
  });

  test('nach wenigen Sekunden abgebrochen: beginnt wieder vorn', () async {
    await PlaybackPositionService.save('ep1', const Duration(seconds: 20), folge);
    expect(await PlaybackPositionService.resumePosition('ep1'), isNull);
  });

  test('kurz vor dem Ende: gilt als durchgehoert, alte Stelle wird vergessen', () async {
    await PlaybackPositionService.save('ep1', const Duration(minutes: 10), folge);
    await PlaybackPositionService.save('ep1', folge - const Duration(seconds: 30), folge);
    expect(await PlaybackPositionService.resumePosition('ep1'), isNull);
  });

  test('Laenge noch unbekannt: Stelle wird trotzdem gemerkt', () async {
    await PlaybackPositionService.save('ep1', const Duration(minutes: 3), Duration.zero);
    expect(await PlaybackPositionService.resumePosition('ep1'), const Duration(minutes: 2, seconds: 55));
  });

  test('clear vergisst die Stelle', () async {
    await PlaybackPositionService.save('ep1', const Duration(minutes: 5), folge);
    await PlaybackPositionService.clear('ep1');
    expect(await PlaybackPositionService.resumePosition('ep1'), isNull);
  });

  test('hoechstens 50 Folgen, die aeltesten fallen raus', () async {
    for (var i = 0; i < 55; i++) {
      await PlaybackPositionService.save('ep$i', const Duration(minutes: 5), folge);
      await Future<void>.delayed(const Duration(milliseconds: 2));
    }
    final alle = await PlaybackPositionService.all();
    expect(alle.length, 50);
    expect(alle.containsKey('ep0'), isFalse);
    expect(alle.containsKey('ep54'), isTrue);
  });

  test('kaputter Speicher fuehrt nicht zum Absturz', () async {
    SharedPreferences.setMockInitialValues({'playback_positions': 'kein json'});
    expect(await PlaybackPositionService.resumePosition('ep1'), isNull);
    await PlaybackPositionService.save('ep1', const Duration(minutes: 5), folge);
    expect(await PlaybackPositionService.resumePosition('ep1'), isNotNull);
  });
}
