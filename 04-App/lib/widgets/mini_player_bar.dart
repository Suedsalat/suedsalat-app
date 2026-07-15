import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/material.dart';

import '../screens/episodes/episode_player_screen.dart';
import '../services/audio_player_service.dart';

/// Kleine Leiste ueber der Navigation, solange eine Folge laeuft oder pausiert
/// ist - so laeuft die Wiedergabe weiter, auch wenn man Termine oder Fotos
/// anschaut, und man kommt per Antippen zurueck zum vollen Player.
class MiniPlayerBar extends StatelessWidget {
  const MiniPlayerBar({super.key});

  @override
  Widget build(BuildContext context) {
    final service = AudioPlayerService.instance;

    return AnimatedBuilder(
      animation: service,
      builder: (context, _) {
        final episode = service.currentEpisode;
        if (episode == null) return const SizedBox.shrink();

        return Material(
          color: Theme.of(context).colorScheme.primary,
          child: InkWell(
            onTap: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const EpisodePlayerScreen()),
              );
            },
            child: SafeArea(
              top: false,
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                child: Row(
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(6),
                      child: episode.imageUrl != null
                          ? Image.network(episode.imageUrl!, width: 36, height: 36, fit: BoxFit.cover)
                          : Image.asset('assets/images/mikro_transparent.png', width: 36, height: 36),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        episode.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: Colors.white),
                      ),
                    ),
                    IconButton(
                      icon: Icon(
                        service.playerState == PlayerState.playing
                            ? Icons.pause
                            : Icons.play_arrow,
                        color: Colors.white,
                      ),
                      onPressed: service.togglePlayPause,
                    ),
                    IconButton(
                      icon: const Icon(Icons.close, color: Colors.white),
                      tooltip: 'Wiedergabe beenden',
                      onPressed: service.stop,
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
