import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:suedsalat_app/models/event.dart';
import 'package:suedsalat_app/models/location_tip.dart';
import 'package:suedsalat_app/models/movie_tip.dart';
import 'package:suedsalat_app/models/photo.dart';
import 'package:suedsalat_app/widgets/tip_submitter_line.dart';

void main() {
  Map<String, dynamic> filmtipp(Map<String, dynamic> extra) =>
      {'id': 1, 'title': 'Top Gun', 'created_at': '2026-09-18 07:29:00', ...extra};
  Map<String, dynamic> locationtipp(Map<String, dynamic> extra) =>
      {'id': 1, 'name': 'Rossini', 'location': 'Merten', 'created_at': '2026-09-24 06:38:00', ...extra};
  Map<String, dynamic> veranstaltung(Map<String, dynamic> extra) =>
      {'id': 1, 'title': 'Stadtfest', 'event_date': '2026-10-03', ...extra};
  Map<String, dynamic> foto(Map<String, dynamic> extra) =>
      {'id': 1, 'image_path': 'https://example.org/a.jpg', 'published_at': '2026-09-20 10:00:00', ...extra};

  test('Name des Einsenders wird uebernommen', () {
    expect(MovieTip.fromJson(filmtipp({'submitted_by_name': 'Angela'})).submittedByName, 'Angela');
    expect(LocationTip.fromJson(locationtipp({'submitted_by_name': ' Inga '})).submittedByName, 'Inga');
    expect(Event.fromJson(veranstaltung({'submitted_by_name': 'Südsalat'})).submittedByName, 'Südsalat');
    expect(Photo.fromJson(foto({'submitted_by_name': 'Sarah'})).submittedByName, 'Sarah');
  });

  test('Leerer Name gilt als "kein Name"', () {
    expect(MovieTip.fromJson(filmtipp({'submitted_by_name': ''})).submittedByName, isNull);
    expect(LocationTip.fromJson(locationtipp({'submitted_by_name': '   '})).submittedByName, isNull);
    expect(Photo.fromJson(foto({'submitted_by_name': ''})).submittedByName, isNull);
  });

  test('Server ohne das Feld (vor der Migration) bricht nichts', () {
    expect(MovieTip.fromJson(filmtipp({})).submittedByName, isNull);
    expect(LocationTip.fromJson(locationtipp({'submitted_by_name': null})).submittedByName, isNull);
    expect(Event.fromJson(veranstaltung({})).submittedByName, isNull);
    expect(Photo.fromJson(foto({})).submittedByName, isNull);
  });

  Future<void> zeige(WidgetTester tester, TipSubmitterLine zeile) =>
      tester.pumpWidget(MaterialApp(home: Scaffold(body: zeile)));

  testWidgets('Geloeschtes Konto: "Tipp von einem ehemaligen Mitglied"', (tester) async {
    await zeige(tester, const TipSubmitterLine(name: kFormerMember));
    expect(find.text('Tipp von einem ehemaligen Mitglied'), findsOneWidget);
    await zeige(tester, const TipSubmitterLine(name: kFormerMember, prefix: 'Foto von'));
    expect(find.text('Foto von einem ehemaligen Mitglied'), findsOneWidget);
  });

  testWidgets('Geloeschtes Konto allein (Rezension): "Ehemaliges Mitglied"', (tester) async {
    await zeige(tester, const TipSubmitterLine(name: kFormerMember, prefix: ''));
    expect(find.text('Ehemaliges Mitglied'), findsOneWidget);
  });

  testWidgets('Normale Namen unveraendert', (tester) async {
    await zeige(tester, const TipSubmitterLine(name: 'Angela'));
    expect(find.text('Tipp von Angela'), findsOneWidget);
  });
}
