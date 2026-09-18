import 'dart:async';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/foundation.dart';

import '../models/episode.dart';
import 'api_service.dart';
import 'car_context_service.dart';
import 'listened_episodes_service.dart';

/// Haelt genau einen AudioPlayer als App-weiten Singleton, damit eine laufende
/// Folge weiterspielt, auch wenn der Nutzer den Player-Bildschirm verlaesst
/// und sich z.B. Termine oder Fotos anschaut. Merkt sich zusaetzlich die
/// aktuelle Abspielliste, um nach dem Ende einer Folge automatisch mit der
/// naechsten weiterzumachen.
class AudioPlayerService extends ChangeNotifier {
  AudioPlayerService._internal() {
    _configureAudioContext();
    _player.onPlayerStateChanged.listen((state) {
      playerState = state;
      notifyListeners();
    });
    _player.onPositionChanged.listen((newPosition) {
      position = newPosition;
      _checkMilestones();
      notifyListeners();
    });
    _player.onDurationChanged.listen((newDuration) {
      duration = newDuration;
      notifyListeners();
    });
    _player.onPlayerComplete.listen((_) async {
      // Ein "complete"-Ereignis weit vor dem tatsaechlichen Ende der Folge
      // ist kein echtes Ende, sondern ein Streaming-Aussetzer (z.B. kurzer
      // Netzwerk-Haenger), den manche Player-Backends faelschlich als Ende
      // melden statt als Fehler/Unterbrechung - gemeldeter Bug: die Folge
      // "springt" mitten drin auf "beendet". Nur als echtes Ende werten,
      // wenn die Position auch wirklich nahe am bekannten Ende liegt; sonst
      // an derselben Stelle weiterspielen statt faelschlich "gehoert" zu
      // markieren und zur naechsten Folge zu springen.
      const completionTolerance = Duration(seconds: 15);
      final reallyAtEnd = duration.inSeconds <= 0 ||
          position >= duration - completionTolerance;

      if (!reallyAtEnd) {
        final resumePosition = position;
        await _player.seek(resumePosition);
        await _player.resume();
        return;
      }

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
  final ApiService _api = ApiService();

  bool _audioContextConfigured = false;

  /// Stellt die iOS-Audiosession auf "playback" statt der Standard-Kategorie -
  /// ohne das pausiert iOS die Wiedergabe automatisch, sobald der Bildschirm
  /// sich sperrt/abschaltet (genau der gemeldete Bug: Player stoppt beim
  /// Wechsel in den Sperrbildschirm). "playback" ist die von Apple vorgesehene
  /// Kategorie fuer Audio, das auch im Hintergrund/bei gesperrtem Screen
  /// weiterlaufen soll (siehe zusaetzlich UIBackgroundModes in Info.plist).
  Future<void> _configureAudioContext() async {
    if (_audioContextConfigured) return;
    _audioContextConfigured = true;
    await AudioPlayer.global.setAudioContext(AudioContext(
      iOS: AudioContextIOS(
        category: AVAudioSessionCategory.playback,
        options: {
          AVAudioSessionOptions.allowAirPlay,
          AVAudioSessionOptions.allowBluetooth,
          AVAudioSessionOptions.allowBluetoothA2DP,
        },
      ),
      android: AudioContextAndroid(
        isSpeakerphoneOn: false,
        stayAwake: true,
        contentType: AndroidContentType.music,
        usageType: AndroidUsageType.media,
        audioFocus: AndroidAudioFocus.gain,
      ),
    ));
  }

  List<Episode> _queue = [];
  int _queueIndex = -1;

  // Hoerdauer-Stufen ("Trichter") fuer die anonyme Statistik im Admin-Bereich -
  // pro laufender Wiedergabe merkt sich _firedMilestones, welche Stufen schon
  // gemeldet wurden, damit z.B. Vor-/Zurueckspulen dieselbe Stufe nicht mehrfach zaehlt.
  static const _minuteMilestones = {
    '5min': Duration(minutes: 5),
    '15min': Duration(minutes: 15),
    '25min': Duration(minutes: 25),
    '35min': Duration(minutes: 35),
    '45min': Duration(minutes: 45),
  };
  final Set<String> _firedMilestones = {};

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
    await _configureAudioContext();
    final episode = _queue[_queueIndex];
    currentEpisode = episode;
    position = Duration.zero;
    duration = Duration.zero;
    notifyListeners();
    _firedMilestones.clear();
    await _player.play(UrlSource(episode.audioUrl));
    unawaited(_trackPlayWithCarContext(episode.guid));
  }

  /// Ermittelt beim Start einer Folge, ob gerade ueber Android Auto/CarPlay
  /// wiedergegeben wird, und meldet das zusammen mit dem Play-Ereignis - siehe
  /// CarContextService fuer die Erkennung, komplett anonym.
  Future<void> _trackPlayWithCarContext(String episodeGuid) async {
    final carContext = await CarContextService.detect();
    await _api.trackEpisodePlay(episodeGuid, carContext: carContext);
  }

  /// Prueft bei jedem Positions-Update, ob eine neue Hoerdauer-Stufe erreicht
  /// wurde, und meldet sie einmalig. "Bis zum Ende" wird bewusst schon eine
  /// Minute vor dem tatsaechlichen Ende ausgeloest (Restlaufzeit - 1 Minute),
  /// da manche Hoerer schon beim Abspann abschalten, bevor die Datei technisch
  /// zu Ende ist - eine strikte "letzte Sekunde"-Pruefung wuerde solche
  /// vollstaendigen Anhoerungen sonst nicht mitzaehlen.
  void _checkMilestones() {
    final episode = currentEpisode;
    if (episode == null) return;

    for (final entry in _minuteMilestones.entries) {
      if (!_firedMilestones.contains(entry.key) && position >= entry.value) {
        _firedMilestones.add(entry.key);
        unawaited(_api.trackEpisodeMilestone(episode.guid, entry.key));
      }
    }

    if (!_firedMilestones.contains('end') &&
        duration.inSeconds > 0 &&
        position.inSeconds >= (duration.inSeconds - 60).clamp(0, duration.inSeconds)) {
      _firedMilestones.add('end');
      unawaited(_api.trackEpisodeMilestone(episode.guid, 'end'));
    }
  }

  Future<void> togglePlayPause() async {
    if (playerState == PlayerState.playing) {
      await pause();
    } else {
      await play();
    }
  }

  /// Explizites Abspielen (statt togglePlayPause) - wird von der
  /// Android-Auto-/CarPlay-Bruecke (SuedsalatAudioHandler) gebraucht, da
  /// Fernsteuerungen dort play/pause als getrennte Kommandos senden, nicht
  /// als einen einzigen Umschalt-Knopf wie der In-App-Button.
  Future<void> play() async {
    if (playerState == PlayerState.completed) {
      await _player.seek(Duration.zero);
    }
    await _player.resume();
  }

  Future<void> pause() async {
    await _player.pause();
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
