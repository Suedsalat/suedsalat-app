import 'package:audio_service/audio_service.dart';
import 'package:audioplayers/audioplayers.dart' show PlayerState;

import '../models/episode.dart';
import 'api_service.dart';
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

  @override
  Future<List<MediaItem>> getChildren(String parentMediaId, [Map<String, dynamic>? options]) async {
    if (parentMediaId != AudioService.browsableRootId) {
      return [];
    }
    final episodes = await _loadEpisodes();
    return episodes
        .map((episode) => MediaItem(
              id: episode.guid,
              title: episode.title,
              artist: 'Südsalat Podcast',
              artUri: episode.imageUrl != null ? Uri.tryParse(episode.imageUrl!) : null,
              playable: true,
            ))
        .toList();
  }

  @override
  Future<void> playFromMediaId(String mediaId, [Map<String, dynamic>? extras]) async {
    final episodes = await _loadEpisodes();
    final index = episodes.indexWhere((episode) => episode.guid == mediaId);
    if (index != -1) {
      await _service.playFromList(episodes, index);
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
        artUri: episode.imageUrl != null ? Uri.tryParse(episode.imageUrl!) : null,
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
  Future<void> fastForward() => _service.seek(_service.position + const Duration(seconds: 30));

  @override
  Future<void> rewind() => _service.seek(_service.position - const Duration(seconds: 15));
}
