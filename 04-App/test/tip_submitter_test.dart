import 'package:flutter_test/flutter_test.dart';
import 'package:suedsalat_app/models/location_tip.dart';
import 'package:suedsalat_app/models/movie_tip.dart';

void main() {
  Map<String, dynamic> filmtipp(Map<String, dynamic> extra) =>
      {'id': 1, 'title': 'Top Gun', 'created_at': '2026-09-18 07:29:00', ...extra};
  Map<String, dynamic> locationtipp(Map<String, dynamic> extra) =>
      {'id': 1, 'name': 'Rossini', 'location': 'Merten', 'created_at': '2026-09-24 06:38:00', ...extra};

  test('Name des Tippgebers wird uebernommen', () {
    expect(MovieTip.fromJson(filmtipp({'submitted_by_name': 'Angela'})).submittedByName, 'Angela');
    expect(LocationTip.fromJson(locationtipp({'submitted_by_name': ' Inga '})).submittedByName, 'Inga');
  });

  test('Leerer Name gilt als "kein Name"', () {
    expect(MovieTip.fromJson(filmtipp({'submitted_by_name': ''})).submittedByName, isNull);
    expect(LocationTip.fromJson(locationtipp({'submitted_by_name': '   '})).submittedByName, isNull);
  });

  test('Server ohne das Feld (vor der Migration) bricht nichts', () {
    expect(MovieTip.fromJson(filmtipp({})).submittedByName, isNull);
    expect(LocationTip.fromJson(locationtipp({'submitted_by_name': null})).submittedByName, isNull);
  });
}
