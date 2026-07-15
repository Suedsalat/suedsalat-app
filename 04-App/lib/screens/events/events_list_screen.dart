import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../models/episode.dart';
import '../../models/event.dart';
import '../../services/api_service.dart';
import '../../services/audio_player_service.dart';
import '../../services/seen_items_service.dart';
import '../../widgets/async_state_views.dart';
import '../../widgets/new_dot.dart';
import '../episodes/episode_player_screen.dart';
import '../gallery/photo_viewer_screen.dart';

class EventsListScreen extends StatefulWidget {
  const EventsListScreen({super.key});

  @override
  State<EventsListScreen> createState() => _EventsListScreenState();
}

class _EventsListScreenState extends State<EventsListScreen> {
  final _api = ApiService();
  late Future<List<Event>> _future;
  Set<String> _seenIds = {};

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<Event>> _load() async {
    final events = await _api.fetchEvents();
    await SeenItemsService.ensureBaseline('event', events.map((e) => e.id.toString()));
    final seen = await SeenItemsService.getSeen('event');
    if (mounted) setState(() => _seenIds = seen);
    return events;
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

  Future<void> _openEpisode(BuildContext dialogContext, Event event) async {
    final guid = event.episodeGuid;
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
      startAt: event.episodeTimestampSeconds != null
          ? Duration(seconds: event.episodeTimestampSeconds!)
          : null,
    );
    if (!mounted) return;
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const EpisodePlayerScreen()),
    );
  }

  void _showEventDetails(Event event) {
    final id = event.id.toString();
    if (!_seenIds.contains(id)) {
      SeenItemsService.markSeen('event', id);
      setState(() => _seenIds = {..._seenIds, id});
    }

    String timeText = '';
    if (event.eventTime != null) {
      timeText = ' · ${event.eventTime!.substring(0, 5)}';
      if (event.eventEndTime != null) {
        timeText += '–${event.eventEndTime!.substring(0, 5)}';
      }
      timeText += ' Uhr';
    }
    final dateText = '${DateFormat('dd.MM.yyyy').format(event.eventDate)}$timeText';

    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(event.title),
        content: SingleChildScrollView(
          child: SizedBox(
            width: double.maxFinite,
            child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (event.imagePath != null && event.imagePath!.isNotEmpty) ...[
                InkWell(
                  borderRadius: BorderRadius.circular(10),
                  onTap: () {
                    Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => PhotoViewerScreen(imageUrl: event.imagePath!),
                      ),
                    );
                  },
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: SizedBox(
                      height: 160,
                      width: double.infinity,
                      child: Image.network(
                        event.imagePath!,
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
              Text(dateText, style: Theme.of(context).textTheme.bodyMedium),
              if (event.description != null && event.description!.isNotEmpty) ...[
                const SizedBox(height: 16),
                Text(event.description!),
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
          if (event.episodeGuid != null)
            ElevatedButton(
              onPressed: () => _openEpisode(context, event),
              child: const Text('Jetzt reinhören'),
            ),
          if (event.link != null && event.link!.isNotEmpty)
            ElevatedButton(
              onPressed: () {
                Navigator.of(context).pop();
                _openLink(event.link!);
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
      child: FutureBuilder<List<Event>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const LoadingStateView();
          }
          if (snapshot.hasError) {
            return ErrorStateView(onRetry: _reload);
          }
          final events = snapshot.data ?? const [];
          if (events.isEmpty) {
            return const EmptyStateView(message: 'Aktuell sind keine Veranstaltungen geplant.');
          }
          return ListView.builder(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(12),
            itemCount: events.length,
            itemBuilder: (context, index) {
              final event = events[index];
              final hasDescription = event.description != null && event.description!.isNotEmpty;
              final hasLink = event.link != null && event.link!.isNotEmpty;
              final hasMoreInfo = hasDescription || hasLink;
              final isNew = !_seenIds.contains(event.id.toString());
              return Card(
                clipBehavior: Clip.antiAlias,
                child: InkWell(
                  onTap: () => _showEventDetails(event),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                        _DateBadge(date: event.eventDate),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Text(
                            event.title,
                            style: Theme.of(context).textTheme.titleMedium,
                          ),
                        ),
                        if (isNew) const Padding(
                          padding: EdgeInsets.only(left: 8),
                          child: NewDot(),
                        ),
                        if (hasMoreInfo) ...[
                          const SizedBox(width: 8),
                          const _MoreInfoChip(),
                        ],
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

class _DateBadge extends StatelessWidget {
  final DateTime date;

  const _DateBadge({required this.date});

  static const _monthAbbr = [
    'JAN', 'FEB', 'MÄR', 'APR', 'MAI', 'JUN',
    'JUL', 'AUG', 'SEP', 'OKT', 'NOV', 'DEZ',
  ];

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 56,
      padding: const EdgeInsets.symmetric(vertical: 8),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.primary,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            date.day.toString().padLeft(2, '0'),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 22,
              fontWeight: FontWeight.bold,
              height: 1.1,
            ),
          ),
          Text(
            _monthAbbr[date.month - 1],
            style: const TextStyle(
              color: Colors.white,
              fontSize: 12,
              fontWeight: FontWeight.w600,
              letterSpacing: 0.5,
            ),
          ),
        ],
      ),
    );
  }
}

class _MoreInfoChip extends StatelessWidget {
  const _MoreInfoChip();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.primary,
        borderRadius: BorderRadius.circular(999),
      ),
      child: const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.info_outline, size: 14, color: Colors.white),
          SizedBox(width: 4),
          Text(
            'Mehr Infos',
            style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}
