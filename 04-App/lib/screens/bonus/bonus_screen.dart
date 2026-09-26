import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../models/bonus_item.dart';
import '../../services/api_service.dart';
import '../../services/audio_player_service.dart';
import '../../widgets/async_state_views.dart';
import '../episodes/episode_player_screen.dart';

/// Outtakes (App 2.0, intern "bonus"): nur fuer angemeldete Hoerer - die Kachel auf der Startseite
/// erscheint nur mit Konto. Abgespielt wird im normalen Player, also auch mit Sperrbildschirm
/// und gemerkter Wiedergabeposition.
class BonusScreen extends StatefulWidget {
  const BonusScreen({super.key, this.api});

  /// Nur fuer Tests.
  final ApiService? api;

  @override
  State<BonusScreen> createState() => _BonusScreenState();
}

class _BonusScreenState extends State<BonusScreen> {
  late final ApiService _api = widget.api ?? ApiService();
  late Future<List<BonusItem>> _future = _api.fetchBonus();

  Future<void> _reload() async {
    setState(() => _future = _api.fetchBonus());
    await _future;
  }

  void _play(List<BonusItem> items, int index) {
    AudioPlayerService.instance.playFromList([for (final b in items) b.toEpisode()], index);
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => const EpisodePlayerScreen()));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Outtakes')),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<List<BonusItem>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const LoadingStateView();
            }
            if (snapshot.hasError) {
              return ErrorStateView(onRetry: _reload);
            }
            final items = snapshot.data ?? const [];
            if (items.isEmpty) {
              return const EmptyStateView(message: 'Hier gibt es bald Outtakes aus dem Podcast.');
            }
            return ListView.builder(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(12),
              itemCount: items.length,
              itemBuilder: (context, index) {
                final item = items[index];
                return Card(
                  child: ListTile(
                    title: Text(item.title),
                    subtitle: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(DateFormat('dd.MM.yyyy').format(item.publishedAt)),
                        if (item.description != null && item.description!.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(item.description!, maxLines: 3, overflow: TextOverflow.ellipsis),
                        ],
                      ],
                    ),
                    isThreeLine: item.description != null && item.description!.isNotEmpty,
                    trailing: const Icon(Icons.play_circle_outline, size: 40),
                    onTap: () => _play(items, index),
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }
}
