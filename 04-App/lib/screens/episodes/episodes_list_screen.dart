import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../models/episode.dart';
import '../../services/api_service.dart';
import '../../services/audio_player_service.dart';
import '../../services/listened_episodes_service.dart';
import '../../services/seen_items_service.dart';
import '../../widgets/async_state_views.dart';
import '../../widgets/new_dot.dart';
import 'episode_player_screen.dart';

class EpisodesListScreen extends StatefulWidget {
  const EpisodesListScreen({super.key});

  @override
  State<EpisodesListScreen> createState() => _EpisodesListScreenState();
}

class _EpisodesListScreenState extends State<EpisodesListScreen> {
  final _api = ApiService();
  late Future<({List<Episode> episodes, Set<String> listened})> _future;
  Set<String> _seenGuids = {};

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<({List<Episode> episodes, Set<String> listened})> _load() async {
    final episodes = await _api.fetchEpisodes();
    final listened = await ListenedEpisodesService.getListened();
    await SeenItemsService.ensureBaseline('episode', episodes.map((e) => e.guid));
    final seen = await SeenItemsService.getSeen('episode');
    if (mounted) setState(() => _seenGuids = seen);
    return (episodes: episodes, listened: listened);
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  void _openEpisode(List<Episode> episodes, int index) {
    final episode = episodes[index];
    SeenItemsService.markSeen('episode', episode.guid);
    setState(() => _seenGuids = {..._seenGuids, episode.guid});
    AudioPlayerService.instance.playFromList(episodes, index);
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const EpisodePlayerScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _reload,
      child: FutureBuilder(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const LoadingStateView();
          }
          if (snapshot.hasError) {
            return ErrorStateView(onRetry: _reload);
          }
          final episodes = snapshot.data?.episodes ?? const [];
          final listened = snapshot.data?.listened ?? const {};
          if (episodes.isEmpty) {
            return const EmptyStateView(message: 'Noch keine Folgen vorhanden.');
          }
          return ListView.builder(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(12),
            itemCount: episodes.length,
            itemBuilder: (context, index) {
              final episode = episodes[index];
              final isListened = listened.contains(episode.guid);
              final isNew = !_seenGuids.contains(episode.guid);
              return Opacity(
                opacity: isListened ? 0.6 : 1.0,
                child: Card(
                  child: ListTile(
                    leading: episode.imageUrl != null
                        ? ClipRRect(
                            borderRadius: BorderRadius.circular(6),
                            child: Image.network(
                              episode.imageUrl!,
                              width: 56,
                              height: 56,
                              fit: BoxFit.cover,
                            ),
                          )
                        : Image.asset('assets/images/mikro_transparent.png', width: 48, height: 48),
                    title: Row(
                      children: [
                        Expanded(child: Text(episode.title)),
                        if (isNew) const Padding(
                          padding: EdgeInsets.only(left: 8),
                          child: NewDot(),
                        ),
                      ],
                    ),
                    subtitle: Text(
                      isListened
                          ? '${DateFormat('dd.MM.yyyy').format(episode.pubDate)} · Gehört'
                          : DateFormat('dd.MM.yyyy').format(episode.pubDate),
                    ),
                    trailing: Icon(
                      isListened ? Icons.check_circle : Icons.play_circle_outline,
                      size: isListened ? 28 : 40,
                      color: isListened ? Colors.green : null,
                    ),
                    onTap: () => _openEpisode(episodes, index),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
