import 'dart:io';

import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../models/episode.dart';
import 'audio_handler.dart';
import 'audio_player_service.dart';
import 'listened_episodes_service.dart';
import 'playback_position_service.dart';

/// Dart-Seite des CarPlay-Menues (ios/Runner/CarPlaySceneDelegate.swift).
///
/// iOS fragt die Folgenliste ab ("episodes") und meldet, welche Folge angetippt wurde ("play").
/// Abgespielt wird ueber denselben SuedsalatAudioHandler wie bei Android Auto - also dieselbe
/// Wiedergabe wie am Handy. Wechselt die laufende Folge, sagt Dart iOS Bescheid
/// ("episodesChanged"), damit Markierung "läuft" und "Gehört" im Auto stimmen.
class CarPlayService {
  static const _channel = MethodChannel('eu.suedsalat.suedsalat_app/carplay');

  /// Nach so langer Zeit holt eine neue Anfrage aus dem Auto die Liste frisch vom Server.
  static const _refreshAfter = Duration(minutes: 5);

  static SuedsalatAudioHandler? _handler;
  static DateTime? _lastFetch;
  static String? _lastGuid;

  static void init(SuedsalatAudioHandler handler) {
    if (!Platform.isIOS) return;
    _handler = handler;
    _channel.setMethodCallHandler(_handle);
    AudioPlayerService.instance.addListener(_onPlayerChanged);
  }

  static Future<dynamic> _handle(MethodCall call) async {
    final handler = _handler;
    if (handler == null) return null;
    switch (call.method) {
      case 'episodes':
        final refresh = _lastFetch == null || DateTime.now().difference(_lastFetch!) > _refreshAfter;
        final episodes = await handler.episodes(refresh: refresh);
        if (refresh) _lastFetch = DateTime.now();
        return buildItems(
          episodes,
          listened: await ListenedEpisodesService.getListened(),
          positions: await PlaybackPositionService.all(),
          currentGuid: AudioPlayerService.instance.currentEpisode?.guid,
        );
      case 'play':
        final guid = (call.arguments as Map?)?['guid'] as String?;
        if (guid != null) await handler.playFromMediaId(guid);
        return null;
    }
    throw MissingPluginException();
  }

  static void _onPlayerChanged() {
    final guid = AudioPlayerService.instance.currentEpisode?.guid;
    if (guid == _lastGuid) return;
    _lastGuid = guid;
    _channel.invokeMethod('episodesChanged').catchError((_) => null);
  }

  /// Eintraege fuer die CarPlay-Liste: Titel, Zeile darunter (Datum, "Gehört" oder
  /// "Weiter bei …"), Cover, Fortschritt (0-1) und ob die Folge gerade laeuft.
  static List<Map<String, Object?>> buildItems(
    List<Episode> episodes, {
    required Set<String> listened,
    required Map<String, Duration> positions,
    String? currentGuid,
  }) {
    final date = DateFormat('dd.MM.yyyy');
    return [
      for (final e in episodes)
        () {
          final isListened = listened.contains(e.guid);
          final position = positions[e.guid];
          final length = parseDuration(e.duration);
          final detail = isListened
              ? '${date.format(e.pubDate)} · Gehört'
              : position != null
                  ? '${date.format(e.pubDate)} · Weiter bei ${PlaybackPositionService.format(position)}'
                  : date.format(e.pubDate);
          double? progress;
          if (isListened) {
            progress = 1.0;
          } else if (position != null && length != null && length.inSeconds > 0) {
            progress = (position.inSeconds / length.inSeconds).clamp(0.0, 1.0);
          }
          return <String, Object?>{
            'guid': e.guid,
            'title': e.title,
            'detail': detail,
            'imageUrl': e.imageUrl ?? SuedsalatAudioHandler.fallbackArtUrl,
            'playing': e.guid == currentGuid,
            'progress': ?progress,
          };
        }(),
    ];
  }

  /// Laenge aus dem RSS-Feed: "1:02:03", "45:10" oder reine Sekunden. null, wenn unbekannt.
  static Duration? parseDuration(String? raw) {
    if (raw == null || raw.trim().isEmpty) return null;
    final parts = raw.trim().split(':').map(int.tryParse).toList();
    if (parts.any((p) => p == null) || parts.length > 3) return null;
    var seconds = 0;
    for (final p in parts) {
      seconds = seconds * 60 + p!;
    }
    return Duration(seconds: seconds);
  }
}
