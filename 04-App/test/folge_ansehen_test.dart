import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:suedsalat_app/models/episode.dart';
import 'package:suedsalat_app/screens/episodes/episode_player_screen.dart';
import 'package:suedsalat_app/services/audio_player_service.dart';

/// Folge aus der Liste antippen = nur ansehen; die laufende Folge spielt weiter, bis man
/// "Abspielen" drueckt. Bei der laufenden Folge gibt es Kapitel- und Sprungknoepfe
/// (Wunsch Thorsten, 26.09.2026).
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  // Der echte Player spricht mit der Plattform - im Test gibt es nur eine stumme Attrappe.
  setUpAll(() {
    final messenger = TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
    for (final name in ['xyz.luan/audioplayers', 'xyz.luan/audioplayers.global']) {
      messenger.setMockMethodCallHandler(MethodChannel(name), (_) async => null);
    }
    messenger.setMockStreamHandler(
      const EventChannel('xyz.luan/audioplayers.global/events'),
      MockStreamHandler.inline(onListen: (_, _) {}),
    );
  });

  Episode folge(String guid, String titel, {String? beschreibung}) => Episode(
        guid: guid,
        title: titel,
        description: beschreibung,
        audioUrl: 'https://example.org/$guid.mp3',
        pubDate: DateTime(2026, 9, 1),
      );

  const mitKapiteln = 'Worum es geht.\n\nKapitel:\n00:00 Begrüßung\n05:00 Thema\n20:00 Verabschiedung';

  Future<void> zeigen(WidgetTester tester, Episode e) async {
    SharedPreferences.setMockInitialValues({});
    tester.view.physicalSize = const Size(800, 1800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(home: EpisodePlayerScreen(episode: e, queue: [e], index: 0)));
    await tester.pumpAndSettle();
  }

  testWidgets('Andere Folge als die laufende: nur Infoseite mit Abspielen-Knopf', (tester) async {
    final service = AudioPlayerService.instance;
    service.currentEpisode = folge('laeuft', 'Folge 12');
    addTearDown(() => service.currentEpisode = null);

    await zeigen(tester, folge('neu', 'Folge 5', beschreibung: mitKapiteln));

    expect(find.text('Folge'), findsOneWidget); // Titelleiste
    expect(find.byKey(const ValueKey('folge-abspielen')), findsOneWidget);
    expect(find.text('Abspielen'), findsOneWidget);
    expect(find.byType(Slider), findsNothing);
    expect(find.text('Thema'), findsOneWidget); // Kapitel sind schon zu sehen
    // Die laufende Folge bleibt die laufende.
    expect(service.currentEpisode!.guid, 'laeuft');
  });

  testWidgets('Laufende Folge mit Kapiteln: Zeitleiste, Spruenge und Kapitelknoepfe', (tester) async {
    final service = AudioPlayerService.instance;
    final e = folge('laeuft', 'Folge 5', beschreibung: mitKapiteln);
    service.currentEpisode = e;
    addTearDown(() => service.currentEpisode = null);

    await zeigen(tester, e);

    expect(find.text('Wird abgespielt'), findsOneWidget);
    expect(find.byType(Slider), findsOneWidget);
    expect(find.byKey(const ValueKey('folge-abspielen')), findsNothing);
    expect(find.byTooltip('Kapitel zurück'), findsOneWidget);
    expect(find.byTooltip('Nächstes Kapitel'), findsOneWidget);
    expect(find.byTooltip('10 Sekunden zurück'), findsOneWidget);
    expect(find.byTooltip('30 Sekunden vor'), findsOneWidget);
  });

  testWidgets('Laufende Folge ohne Kapitel: nur die Sprungknoepfe', (tester) async {
    final service = AudioPlayerService.instance;
    final e = folge('laeuft', 'Folge 1', beschreibung: 'Ganz ohne Kapitel.');
    service.currentEpisode = e;
    addTearDown(() => service.currentEpisode = null);

    await zeigen(tester, e);

    expect(find.byTooltip('Kapitel zurück'), findsNothing);
    expect(find.byTooltip('Nächstes Kapitel'), findsNothing);
    expect(find.byTooltip('10 Sekunden zurück'), findsOneWidget);
    expect(find.byTooltip('30 Sekunden vor'), findsOneWidget);
  });
}
