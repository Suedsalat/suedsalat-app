import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/foundation.dart';

import '../models/episode.dart';
import 'listened_episodes_service.dart';

/// Haelt genau einen AudioPlayer als App-weiten Singleton, damit eine laufende
/// Folge weiterspielt, auch wenn der Nutzer den Player-Bildschirm verlaesst
/// und sich z.B. Termine oder Fotos anschaut. Merkt sich zusaetzlich die
/// aktuelle Abspielliste, um nach dem Ende einer Folge automatisch mit der
/// naechsten weiterzumachen.
class AudioPlayerService extends ChangeNotifier {
  AudioPlayerService._internal() {
    _player.onPlayerStateChanged.listen((state) {
      playerState = state;
      notifyListeners();
    });
    _player.onPositionChanged.listen((newPosition) {
      position = newPosition;
      notifyListeners();
    });
    _player.onDurationChanged.listen((newDuration) {
      duration = newDuration;
      notifyListeners();
    });
    _player.onPlayerComplete.listen((_) async {
      final finished = currentEpisode;
      if (finished != null) {
        await ListenedEpisodesService.markListened(finished.guid);
      }
      if (hasNext) {
        await playNext();
      } else {
        await _player.seek(Duration.zero);
        position = Duration.zero;
        playerState = PlayerState.paused;
        notifyListeners();
      }
    });
  }

  static final AudioPlayerService instance = AudioPlayerService._internal();

  final AudioPlayer _player = AudioPlayer();

  List<Episode> _queue = [];
  int _queueIndex = -1;

  Episode? currentEpisode;
  PlayerState playerState = PlayerState.stopped;
  Duration position = Duration.zero;
  Duration duration = Duration.zero;

  bool get hasNext => _queueIndex >= 0 && _queueIndex + 1 < _queue.length;

  /// Startet Wiedergabe einer Folge aus einer Liste (z.B. der Folgenuebersicht),
  /// damit nach dem Ende automatisch die naechste Folge aus derselben Liste
  /// weiterspielt.
  Future<void> playFromList(List<Episode> episodes, int index) async {
    _queue = episodes;
    _queueIndex = index;
    await _playCurrent();
  }

  Future<void> playNext() async {
    if (!hasNext) return;
    _queueIndex++;
    await _playCurrent();
  }

  /// Spielt eine einzelne Folge losgeloest von einer Liste ab (z.B. per
  /// Verlinkung von einem Termin aus) und springt optional direkt zu [startAt].
  Future<void> playEpisode(Episode episode, {Duration? startAt}) async {
    _queue = [episode];
    _queueIndex = 0;
    await _playCurrent();
    if (startAt != null) {
      await seek(startAt);
    }
  }

  Future<void> _playCurrent() async {
    if (_queueIndex < 0 || _queueIndex >= _queue.length) return;
    final episode = _queue[_queueIndex];
    currentEpisode = episode;
    position = Duration.zero;
    duration = Duration.zero;
    notifyListeners();
    await _player.play(UrlSource(episode.audioUrl));
  }

  Future<void> togglePlayPause() async {
    if (playerState == PlayerState.playing) {
      await _player.pause();
    } else if (playerState == PlayerState.completed) {
      await _player.seek(Duration.zero);
      await _player.resume();
    } else {
      await _player.resume();
    }
  }

  /// Springt zu [newPosition]. Aktualisiert `position` sofort selbst, statt nur
  /// auf das naechste `onPositionChanged`-Event vom Player zu warten - sonst
  /// springt die Wiedergabe zwar sofort hoerbar, die Zeitleiste in der UI zeigt
  /// aber noch kurz (oder je nach Plattform dauerhaft sichtbar falsch) 00:00 an.
  Future<void> seek(Duration newPosition) async {
    await _player.seek(newPosition);
    position = newPosition;
    notifyListeners();
  }

  Future<void> stop() async {
    await _player.stop();
    currentEpisode = null;
    _queue = [];
    _queueIndex = -1;
    position = Duration.zero;
    notifyListeners();
  }
}
