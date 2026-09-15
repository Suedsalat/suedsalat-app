import 'dart:async';

import 'package:audio_service/audio_service.dart';
import 'package:audioplayers/audioplayers.dart' show PlayerState;

import '../models/episode.dart';
import 'api_service.dart';
import 'audio_debug_log.dart';
import 'audio_player_service.dart';

/// Duenne Bruecke zwischen der bestehenden AudioPlayerService (die die
/// eigentliche Wiedergabe/den App-internen Zustand verwaltet, unveraendert
/// fuer alle bisherigen Bildschirme) und dem Betriebssystem: meldet
/// Titel/Cover/Position an Android Auto, CarPlay, die Sperrbildschirm-
/// Mediensteuerung und die Benachrichtigung, und leitet von dort kommende
/// Befehle (Play/Pause/Weiter/Suchen) an AudioPlayerService weiter.
///
/// Bewusst kein eigener Player hier - AudioPlayerService bleibt die einzige
/// Quelle der Wahrheit fuer Wiedergabezustand, damit sich an der bestehenden
/// UI (mini_player_bar, episode_player_screen) nichts aendern muss.
class SuedsalatAudioHandler extends BaseAudioHandler with SeekHandler {
  final AudioPlayerService _service = AudioPlayerService.instance;
  final ApiService _api = ApiService();

  // Ersatzbild fuer Folgen ohne eigenes Cover im RSS-Feed (z.B. aeltere
  // Folgen) - dasselbe Mikro-Symbol, das auch in der Folgenliste der App
  // selbst als Platzhalter dient, statt in Android Auto/CarPlay ganz ohne
  // Bild dazustehen. Liegt bereits oeffentlich auf dem Server (admin.css
  // laedt von dort auch andere statische Bilder).
  static final Uri _fallbackArtUri =
      Uri.parse('https://www.xn--sdsalat-n2a.eu/APP/admin/assets/img/mikro.png');

  Uri _artUriFor(Episode episode) =>
      episode.imageUrl != null ? Uri.tryParse(episode.imageUrl!) ?? _fallbackArtUri : _fallbackArtUri;

  // Cache der Folgenliste fuer die Android-Auto-/CarPlay-Browsing-Ansicht -
  // bewusst NUR Folgen (keine Termine/Filmtipps/Galerie o.ae.), das ist das
  // einzige, was im Auto sinnvoll waehlbar sein soll. Wird beim ersten
  // Aufklappen der Liste geladen und dann fuer die Sitzung wiederverwendet.
  List<Episode>? _episodesCache;

  SuedsalatAudioHandler() {
    _service.addListener(_syncState);
    _syncState();
  }

  Future<List<Episode>> _loadEpisodes() async {
    return _episodesCache ??= await _api.fetchEpisodes();
  }

  // Beschreibung aus dem RSS-Feed kann HTML enthalten (z.B. <p>/<br>-Tags) -
  // fuer die reine Textanzeige in Android Auto/CarPlay werden die Tags entfernt,
  // sonst wuerden sie dort als sichtbare spitze Klammern auftauchen.
  static final _htmlTagPattern = RegExp(r'<[^>]*>');

  String? _plainDescriptionFor(Episode episode) {
    final description = episode.description;
    if (description == null || description.isEmpty) return null;
    return description.replaceAll(_htmlTagPattern, ' ').replaceAll(RegExp(r'\s+'), ' ').trim();
  }

  MediaItem _mediaItemFor(Episode episode) => MediaItem(
        id: episode.guid,
        title: episode.title,
        artist: 'Südsalat Podcast',
        artUri: _artUriFor(episode),
        playable: true,
        displayDescription: _plainDescriptionFor(episode),
      );

  @override
  Future<List<MediaItem>> getChildren(String parentMediaId, [Map<String, dynamic>? options]) async {
    unawaited(AudioDebugLog.add('getChildren(parentMediaId="$parentMediaId")'));
    if (parentMediaId != AudioService.browsableRootId) {
      unawaited(AudioDebugLog.add('  -> nicht root, gebe [] zurueck'));
      return [];
    }
    try {
      final episodes = await _loadEpisodes();
      unawaited(AudioDebugLog.add('  -> ${episodes.length} Folgen geladen'));
      return episodes.map(_mediaItemFor).toList();
    } catch (e, st) {
      unawaited(AudioDebugLog.add('  -> FEHLER: $e\n$st'));
      rethrow;
    }
  }

  // Wird von Android Auto separat aufgerufen, um Detailinformationen zu einer
  // angetippten Folge zu laden (unabhaengig vom eigentlichen Abspiel-Befehl).
  // BaseAudioHandler liefert dafuer standardmaessig null zurueck - das fuehrte
  // dazu, dass Android Auto trotz erfolgreich laufender Wiedergabe
  // "Auswahl konnte nicht geladen werden" anzeigte.
  @override
  Future<MediaItem?> getMediaItem(String mediaId) async {
    unawaited(AudioDebugLog.add('getMediaItem(mediaId="$mediaId")'));
    try {
      final episodes = await _loadEpisodes();
      final index = episodes.indexWhere((episode) => episode.guid == mediaId);
      unawaited(AudioDebugLog.add('  -> ${index != -1 ? "gefunden: ${episodes[index].title}" : "NICHT gefunden"}'));
      return index != -1 ? _mediaItemFor(episodes[index]) : null;
    } catch (e, st) {
      unawaited(AudioDebugLog.add('  -> FEHLER: $e\n$st'));
      rethrow;
    }
  }

  @override
  Future<void> playFromMediaId(String mediaId, [Map<String, dynamic>? extras]) {
    unawaited(AudioDebugLog.add('playFromMediaId(mediaId="$mediaId", extras=$extras)'));
    return _startEpisode(mediaId);
  }

  // Manche Android-Auto-/Assistant-Versionen rufen statt playFromMediaId erst
  // prepareFromMediaId auf (z.B. bei "Ok Google, spiel Folge X") - ohne diese
  // Ueberschreibung bliebe das wirkungslos (BaseAudioHandler-Default tut nichts).
  @override
  Future<void> prepareFromMediaId(String mediaId, [Map<String, dynamic>? extras]) {
    unawaited(AudioDebugLog.add('prepareFromMediaId(mediaId="$mediaId", extras=$extras)'));
    return _startEpisode(mediaId);
  }

  // Android Auto ruft beim Antippen einer Folge offenbar sowohl
  // prepareFromMediaId als auch playFromMediaId auf (teils praktisch
  // gleichzeitig) - ohne Sperre starten beide denselben Ladevorgang parallel
  // und ueberschreiben sich gegenseitig den playbackState, wodurch faelschlich
  // ein Fehlerbildschirm erscheint, obwohl die Wiedergabe tatsaechlich laeuft.
  String? _startingMediaId;

  Future<void> _startEpisode(String mediaId) async {
    if (_startingMediaId == mediaId) {
      unawaited(AudioDebugLog.add('_startEpisode: bereits am Starten fuer "$mediaId", ignoriere doppelten Aufruf'));
      return;
    }
    _startingMediaId = mediaId;
    // Sofort auf "laedt" umschalten, statt erst nach dem Laden/Anspielen der
    // Audio-Datei ueberhaupt eine Zustandsaenderung zu melden - sonst wertet
    // Android Auto die fehlende Rueckmeldung waehrend des Ladens/Pufferns
    // moeglicherweise als fehlgeschlagen ("Auswahl konnte nicht geladen werden").
    playbackState.add(playbackState.value.copyWith(processingState: AudioProcessingState.loading));
    try {
      final episodes = await _loadEpisodes();
      final index = episodes.indexWhere((episode) => episode.guid == mediaId);
      if (index == -1) {
        unawaited(AudioDebugLog.add('_startEpisode: "$mediaId" NICHT in Folgenliste gefunden'));
        playbackState.add(playbackState.value.copyWith(processingState: AudioProcessingState.error));
        return;
      }
      unawaited(AudioDebugLog.add('_startEpisode: starte "${episodes[index].title}" ...'));
      await _service.playFromList(episodes, index);
      unawaited(AudioDebugLog.add('_startEpisode: playFromList() zurueckgekehrt, playerState=${_service.playerState}'));
    } catch (e, st) {
      // Fehler nicht unbehandelt durchreichen (sonst wertet Android Auto die
      // Anfrage als fehlgeschlagen, ohne dass die App danach noch reagiert),
      // sondern als Fehlerzustand melden - _syncState() korrigiert das wieder
      // auf "ready", sobald AudioPlayerService tatsaechlich weiterkommt.
      unawaited(AudioDebugLog.add('_startEpisode: FEHLER beim Starten von "$mediaId": $e\n$st'));
      playbackState.add(playbackState.value.copyWith(processingState: AudioProcessingState.error));
    } finally {
      if (_startingMediaId == mediaId) {
        _startingMediaId = null;
      }
    }
  }

  void _syncState() {
    final episode = _service.currentEpisode;
    if (episode != null) {
      mediaItem.add(MediaItem(
        id: episode.guid,
        title: episode.title,
        artist: 'Südsalat Podcast',
        duration: _service.duration.inMilliseconds > 0 ? _service.duration : null,
        artUri: _artUriFor(episode),
      ));
    }

    playbackState.add(playbackState.value.copyWith(
      controls: [
        MediaControl.rewind,
        _service.playerState == PlayerState.playing ? MediaControl.pause : MediaControl.play,
        MediaControl.stop,
        if (_service.hasNext) MediaControl.skipToNext,
      ],
      systemActions: const {
        MediaAction.seek,
        MediaAction.seekForward,
        MediaAction.seekBackward,
      },
      androidCompactActionIndices: const [0, 1, 2],
      processingState: AudioProcessingState.ready,
      playing: _service.playerState == PlayerState.playing,
      updatePosition: _service.position,
      bufferedPosition: _service.duration,
      speed: 1.0,
    ));
  }

  @override
  Future<void> play() => _service.play();

  @override
  Future<void> pause() => _service.pause();

  @override
  Future<void> stop() async {
    await _service.stop();
    await super.stop();
  }

  @override
  Future<void> seek(Duration position) => _service.seek(position);

  @override
  Future<void> skipToNext() => _service.playNext();

  @override
  Future<void> fastForward() => _service.seek(_service.position + const Duration(seconds: 15));

  @override
  Future<void> rewind() => _service.seek(_service.position - const Duration(seconds: 15));
}
