import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../services/audio_player_service.dart';

class EpisodePlayerScreen extends StatelessWidget {
  const EpisodePlayerScreen({super.key});

  String _formatDuration(Duration d) {
    final hours = d.inHours;
    final minutes = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final seconds = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return hours > 0 ? '$hours:$minutes:$seconds' : '$minutes:$seconds';
  }

  @override
  Widget build(BuildContext context) {
    final service = AudioPlayerService.instance;

    return AnimatedBuilder(
      animation: service,
      builder: (context, _) {
        final episode = service.currentEpisode;
        if (episode == null) {
          return Scaffold(
            appBar: AppBar(title: const Text('Wird abgespielt')),
            body: const Center(child: Text('Keine Folge ausgewählt.')),
          );
        }

        final maxSeconds = service.duration.inSeconds > 0 ? service.duration.inSeconds.toDouble() : 1.0;
        final currentSeconds = service.position.inSeconds.toDouble().clamp(0, maxSeconds);
        final description = episode.description
            ?.replaceAll(RegExp(r'\s+'), ' ')
            .trim();

        return Scaffold(
          appBar: AppBar(title: const Text('Wird abgespielt')),
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
                            child: episode.imageUrl != null
                                ? Image.network(
                                    episode.imageUrl!,
                                    height: 240,
                                    width: 240,
                                    fit: BoxFit.cover,
                                  )
                                : Image.asset(
                                    'assets/images/mikro_transparent.png',
                                    height: 240,
                                    width: 240,
                                    fit: BoxFit.contain,
                                  ),
                          ),
                          const SizedBox(height: 20),
                          Text(
                            episode.title,
                            style: Theme.of(context).textTheme.titleLarge,
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: 6),
                          Text(
                            DateFormat('dd.MM.yyyy').format(episode.pubDate),
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                          if (description != null && description.isNotEmpty) ...[
                            const SizedBox(height: 16),
                            Text(description, textAlign: TextAlign.center),
                          ],
                        ],
                      ),
                    ),
                  ),
                  Slider(
                    value: currentSeconds.toDouble(),
                    max: maxSeconds,
                    onChanged: (value) => service.seek(Duration(seconds: value.toInt())),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(_formatDuration(service.position)),
                        Text(_formatDuration(service.duration)),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  Material(
                    color: Theme.of(context).colorScheme.primary,
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
