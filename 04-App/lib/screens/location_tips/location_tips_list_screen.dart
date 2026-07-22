import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../models/episode.dart';
import '../../models/location_tip.dart';
import '../../services/api_service.dart';
import '../../services/audio_player_service.dart';
import '../../services/seen_items_service.dart';
import '../../widgets/async_state_views.dart';
import '../../widgets/new_dot.dart';
import '../../widgets/rating/mikro_rating_display.dart';
import '../episodes/episode_player_screen.dart';
import '../gallery/photo_viewer_screen.dart';
import '../reviews/tip_reviews_screen.dart';

class LocationTipsListScreen extends StatefulWidget {
  const LocationTipsListScreen({super.key});

  @override
  State<LocationTipsListScreen> createState() => _LocationTipsListScreenState();
}

class _LocationTipsListScreenState extends State<LocationTipsListScreen> {
  final _api = ApiService();
  late Future<List<LocationTip>> _future;
  Set<String> _seenIds = {};

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<LocationTip>> _load() async {
    final tips = await _api.fetchLocationTips();
    await SeenItemsService.ensureBaseline('location_tip', tips.map((t) => t.id.toString()));
    final seen = await SeenItemsService.getSeen('location_tip');
    if (mounted) setState(() => _seenIds = seen);
    return tips;
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _openLink(String link) async {
    final uri = Uri.parse(link);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  Future<void> _openEpisode(BuildContext dialogContext, LocationTip tip) async {
    final guid = tip.episodeGuid;
    if (guid == null) return;

    Episode? episode;
    try {
      final episodes = await _api.fetchEpisodes();
      for (final e in episodes) {
        if (e.guid == guid) {
          episode = e;
          break;
        }
      }
    } catch (_) {
      episode = null;
    }

    if (!mounted) return;
    if (episode == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Die Folge konnte nicht gefunden werden.')),
      );
      return;
    }

    if (!dialogContext.mounted) return;
    Navigator.of(dialogContext).pop();
    await AudioPlayerService.instance.playEpisode(
      episode,
      startAt: tip.episodeTimestampSeconds != null
          ? Duration(seconds: tip.episodeTimestampSeconds!)
          : null,
    );
    if (!mounted) return;
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const EpisodePlayerScreen()),
    );
  }

  void _openReviews(LocationTip tip) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => TipReviewsScreen(tipType: 'location_tip', tipId: tip.id, tipTitle: tip.name),
      ),
    );
  }

  void _showTipDetails(LocationTip tip) {
    final id = tip.id.toString();
    if (!_seenIds.contains(id)) {
      SeenItemsService.markSeen('location_tip', id);
      setState(() => _seenIds = {..._seenIds, id});
    }

    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(tip.name),
        content: SingleChildScrollView(
          child: SizedBox(
            width: double.maxFinite,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (tip.imagePath != null && tip.imagePath!.isNotEmpty) ...[
                  InkWell(
                    borderRadius: BorderRadius.circular(10),
                    onTap: () {
                      Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => PhotoViewerScreen(imageUrl: tip.imagePath!),
                        ),
                      );
                    },
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(10),
                      child: SizedBox(
                        height: 160,
                        width: double.infinity,
                        child: Image.network(
                          tip.imagePath!,
                          fit: BoxFit.cover,
                          alignment: Alignment.topCenter,
                          cacheWidth: 800,
                          loadingBuilder: (context, child, progress) {
                            if (progress == null) return child;
                            return const Center(
                              child: SizedBox(
                                width: 28,
                                height: 28,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              ),
                            );
                          },
                          errorBuilder: (context, error, stackTrace) => const Center(
                            child: Icon(Icons.broken_image_outlined, color: Colors.grey),
                          ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
                Text(tip.location, style: Theme.of(context).textTheme.bodyMedium),
                const SizedBox(height: 8),
                MikroRatingDisplay(
                  avgRating: tip.avgRating,
                  reviewCount: tip.reviewCount,
                  onTap: () => _openReviews(tip),
                  iconSize: 28,
                ),
                if (tip.description != null && tip.description!.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Text(tip.description!),
                ],
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Schließen'),
          ),
          if (tip.episodeGuid != null)
            ElevatedButton(
              onPressed: () => _openEpisode(context, tip),
              child: const Text('Jetzt reinhören'),
            ),
          if (tip.link != null && tip.link!.isNotEmpty)
            ElevatedButton(
              onPressed: () {
                Navigator.of(context).pop();
                _openLink(tip.link!);
              },
              child: const Text('Mehr erfahren'),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _reload,
      child: FutureBuilder<List<LocationTip>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const LoadingStateView();
          }
          if (snapshot.hasError) {
            return ErrorStateView(onRetry: _reload);
          }
          final tips = snapshot.data ?? const [];
          if (tips.isEmpty) {
            return const EmptyStateView(message: 'Noch keine Locationtipps vorhanden.');
          }
          return ListView.builder(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(12),
            itemCount: tips.length,
            itemBuilder: (context, index) {
              final tip = tips[index];
              final isNew = !_seenIds.contains(tip.id.toString());
              return Card(
                clipBehavior: Clip.antiAlias,
                child: InkWell(
                  onTap: () => _showTipDetails(tip),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(8),
                          child: tip.imagePath != null && tip.imagePath!.isNotEmpty
                              ? Image.network(
                                  tip.imagePath!,
                                  width: 56,
                                  height: 56,
                                  fit: BoxFit.cover,
                                )
                              : Container(
                                  width: 56,
                                  height: 56,
                                  color: Theme.of(context).colorScheme.primary,
                                  child: const Icon(Icons.place, color: Colors.white),
                                ),
                        ),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(tip.name, style: Theme.of(context).textTheme.titleMedium),
                              Text(tip.location, style: Theme.of(context).textTheme.bodySmall),
                              const SizedBox(height: 4),
                              MikroRatingDisplay(
                                avgRating: tip.avgRating,
                                reviewCount: tip.reviewCount,
                                iconSize: 18,
                                onTap: () => _openReviews(tip),
                              ),
                            ],
                          ),
                        ),
                        if (isNew) const Padding(
                          padding: EdgeInsets.only(left: 8),
                          child: NewDot(),
                        ),
                      ],
                    ),
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
