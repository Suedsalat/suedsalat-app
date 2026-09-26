import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../models/chapter.dart';
import '../../models/episode.dart';
import '../../services/audio_player_service.dart';
import '../../services/playback_position_service.dart';
import '../../widgets/chapter_list.dart';
import '../../widgets/mini_player_bar.dart';
import '../feedback/feedback_screen.dart';

/// Folge ansehen und abspielen.
///
/// Ohne [episode] (z. B. aus der Mini-Player-Leiste): die laufende Folge mit allen Bedienknoepfen.
/// Mit [episode] aus der Folgenliste: Ist es nicht die laufende Folge, erscheint erst nur die
/// Infoseite (Beschreibung, Kapitel) - die bisherige Folge spielt unten weiter, bis man auf
/// "Abspielen" oder ein Kapitel tippt (Wunsch Thorsten, 26.09.2026).
class EpisodePlayerScreen extends StatelessWidget {
  const EpisodePlayerScreen({super.key, this.episode, this.queue, this.index});

  /// Angezeigte Folge; null = die laufende.
  final Episode? episode;

  /// Liste, aus der [episode] kommt - danach geht es dort weiter (wie bisher).
  final List<Episode>? queue;
  final int? index;

  static String formatDuration(Duration d) {
    final hours = d.inHours;
    final minutes = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final seconds = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return hours > 0 ? '$hours:$minutes:$seconds' : '$minutes:$seconds';
  }

  Future<void> _start(AudioPlayerService service, Episode e, {Duration? at}) async {
    final list = queue ?? [e];
    final i = index ?? 0;
    await service.playFromList(list, i);
    if (at != null) await service.seek(at);
  }

  @override
  Widget build(BuildContext context) {
    final service = AudioPlayerService.instance;

    return AnimatedBuilder(
      animation: service,
      builder: (context, _) {
        final current = service.currentEpisode;
        final shown = episode ?? current;
        if (shown == null) {
          return Scaffold(
            appBar: AppBar(title: const Text('Folge')),
            body: const Center(child: Text('Keine Folge ausgewählt.')),
          );
        }
        final isCurrent = current != null && current.guid == shown.guid;

        // Kapitelzeilen ("00:00 Begruessung") stehen im Feed in der Beschreibung - hier als Liste.
        final parts = EpisodeChapters.of(shown);
        final currentIndex = isCurrent ? parts.indexAt(service.position) : -1;

        return Scaffold(
          appBar: AppBar(title: Text(isCurrent ? 'Wird abgespielt' : 'Folge')),
          // Laeuft gerade eine andere Folge, bleibt sie unten bedienbar.
          bottomNavigationBar: isCurrent ? null : const MiniPlayerBar(),
          body: SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 4),
              child: Column(
                children: [
                  Expanded(
                    child: SingleChildScrollView(
                      child: Column(
                        children: [
                          ClipRRect(
                            borderRadius: BorderRadius.circular(12),
                            child: shown.imageUrl != null
                                ? Image.network(shown.imageUrl!, height: 240, width: 240, fit: BoxFit.cover)
                                : Image.asset('assets/images/mikro_transparent.png', height: 240, width: 240, fit: BoxFit.contain),
                          ),
                          const SizedBox(height: 20),
                          Text(shown.title, style: Theme.of(context).textTheme.titleLarge, textAlign: TextAlign.center),
                          const SizedBox(height: 6),
                          Text(DateFormat('dd.MM.yyyy').format(shown.pubDate), style: Theme.of(context).textTheme.bodySmall),
                          // Infoseite: Abspielen gleich unter dem Titel, nicht erst unter der Beschreibung
                          // (Wunsch Thorsten 26.09.2026 - unten war er zu weit weg).
                          if (!isCurrent) _StartButton(episode: shown, onStart: () => _start(service, shown)),
                          if (currentIndex >= 0) ...[
                            const SizedBox(height: 8),
                            Text(
                              parts.chapters[currentIndex].title,
                              key: const ValueKey('aktuelles-kapitel'),
                              style: TextStyle(color: Theme.of(context).colorScheme.primary, fontWeight: FontWeight.w700),
                              textAlign: TextAlign.center,
                            ),
                          ],
                          if (parts.text.isNotEmpty) ...[
                            const SizedBox(height: 16),
                            Text(parts.text, textAlign: TextAlign.center),
                          ],
                          if (parts.chapters.isNotEmpty) ...[
                            const SizedBox(height: 16),
                            ChapterList(
                              chapters: parts.chapters,
                              currentIndex: currentIndex,
                              onTap: (chapter) async {
                                if (!isCurrent) {
                                  await _start(service, shown, at: chapter.start);
                                  return;
                                }
                                await service.seek(chapter.start);
                                if (service.playerState != PlayerState.playing) await service.play();
                              },
                            ),
                          ],
                          const SizedBox(height: 16),
                          OutlinedButton.icon(
                            onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const FeedbackScreen())),
                            icon: const Icon(Icons.feedback_outlined),
                            label: const Text('Feedback zu dieser Folge geben'),
                          ),
                        ],
                      ),
                    ),
                  ),
                  if (isCurrent) _Controls(service: service, chapters: parts),
                  const SizedBox(height: 8),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

/// Infoseite: grosser Knopf zum Starten - "Weiter bei …", wenn die Folge schon angefangen ist.
class _StartButton extends StatelessWidget {
  const _StartButton({required this.episode, required this.onStart});

  final Episode episode;
  final VoidCallback onStart;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Duration?>(
      future: PlaybackPositionService.resumePosition(episode.guid),
      builder: (context, snapshot) {
        final resume = snapshot.data;
        return Padding(
          padding: const EdgeInsets.only(top: 12),
          child: SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              key: const ValueKey('folge-abspielen'),
              onPressed: onStart,
              icon: const Icon(Icons.play_arrow),
              label: Text(resume != null ? 'Weiter bei ${EpisodePlayerScreen.formatDuration(resume)}' : 'Abspielen'),
            ),
          ),
        );
      },
    );
  }
}

/// Laufende Folge: Zeitleiste und Knoepfe (Kapitel zurueck, 10 s zurueck, Play/Pause, 30 s vor,
/// Kapitel vor). Die Kapitelknoepfe gibt es nur bei Folgen mit Kapiteln.
class _Controls extends StatelessWidget {
  const _Controls({required this.service, required this.chapters});

  final AudioPlayerService service;
  final EpisodeChapters chapters;

  @override
  Widget build(BuildContext context) {
    final maxSeconds = service.duration.inSeconds > 0 ? service.duration.inSeconds.toDouble() : 1.0;
    final currentSeconds = service.position.inSeconds.toDouble().clamp(0.0, maxSeconds);
    final hasChapters = chapters.chapters.isNotEmpty;
    final next = hasChapters ? chapters.nextStart(service.position) : null;
    final primary = Theme.of(context).colorScheme.primary;

    Future<void> jump(Duration by) async {
      var target = service.position + by;
      if (target.isNegative) target = Duration.zero;
      if (service.duration > Duration.zero && target > service.duration) target = service.duration;
      await service.seek(target);
    }

    return Column(
      children: [
        Slider(
          value: currentSeconds,
          max: maxSeconds,
          onChanged: (value) => service.seek(Duration(seconds: value.toInt())),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(EpisodePlayerScreen.formatDuration(service.position)),
              Text(EpisodePlayerScreen.formatDuration(service.duration)),
            ],
          ),
        ),
        const SizedBox(height: 8),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceEvenly,
          children: [
            if (hasChapters)
              IconButton(
                tooltip: 'Kapitel zurück',
                iconSize: 34,
                icon: const Icon(Icons.skip_previous),
                onPressed: () => service.seek(chapters.previousStart(service.position)),
              ),
            IconButton(
              tooltip: '10 Sekunden zurück',
              iconSize: 34,
              icon: const Icon(Icons.replay_10),
              onPressed: () => jump(const Duration(seconds: -10)),
            ),
            Material(
              color: primary,
              shape: const CircleBorder(),
              child: InkWell(
                customBorder: const CircleBorder(),
                onTap: service.togglePlayPause,
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Icon(
                    service.playerState == PlayerState.playing ? Icons.pause : Icons.play_arrow,
                    size: 48,
                    color: Colors.white,
                  ),
                ),
              ),
            ),
            IconButton(
              tooltip: '30 Sekunden vor',
              iconSize: 34,
              icon: const Icon(Icons.forward_30),
              onPressed: () => jump(const Duration(seconds: 30)),
            ),
            if (hasChapters)
              IconButton(
                tooltip: 'Nächstes Kapitel',
                iconSize: 34,
                icon: const Icon(Icons.skip_next),
                onPressed: next == null ? null : () => service.seek(next),
              ),
          ],
        ),
      ],
    );
  }
}
