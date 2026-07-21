import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../models/photo.dart';
import '../../services/api_service.dart';
import '../../services/seen_items_service.dart';
import '../../widgets/async_state_views.dart';
import '../../widgets/new_dot.dart';
import 'photo_viewer_screen.dart';
import 'video_player_screen.dart';

class GalleryScreen extends StatefulWidget {
  const GalleryScreen({super.key});

  @override
  State<GalleryScreen> createState() => _GalleryScreenState();
}

class _GalleryScreenState extends State<GalleryScreen> {
  final _api = ApiService();
  late Future<List<Photo>> _future;
  Set<String> _seenIds = {};

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<Photo>> _load() async {
    final photos = await _api.fetchGallery();
    await SeenItemsService.ensureBaseline('photo', photos.map((p) => p.id.toString()));
    final seen = await SeenItemsService.getSeen('photo');
    if (mounted) setState(() => _seenIds = seen);
    return photos;
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  void _openItem(Photo photo) {
    final id = photo.id.toString();
    SeenItemsService.markSeen('photo', id);
    setState(() => _seenIds = {..._seenIds, id});
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => photo.isVideo
            ? VideoPlayerScreen(videoUrl: photo.imagePath)
            : PhotoViewerScreen(
                imageUrl: photo.imagePath,
                description: photo.description,
                publishedAt: photo.publishedAt,
              ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _reload,
      child: FutureBuilder<List<Photo>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const LoadingStateView();
          }
          if (snapshot.hasError) {
            return ErrorStateView(onRetry: _reload);
          }
          final photos = snapshot.data ?? const [];
          if (photos.isEmpty) {
            return const EmptyStateView(message: 'Noch keine Fotos vorhanden.');
          }
          return GridView.builder(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(12),
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 2,
              mainAxisSpacing: 12,
              crossAxisSpacing: 12,
              childAspectRatio: 0.8,
            ),
            itemCount: photos.length,
            itemBuilder: (context, index) {
              final photo = photos[index];
              final isNew = !_seenIds.contains(photo.id.toString());
              return Card(
                clipBehavior: Clip.antiAlias,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Stack(
                        children: [
                          InkWell(
                            onTap: () => _openItem(photo),
                            child: photo.isVideo
                                ? Container(
                                    width: double.infinity,
                                    height: double.infinity,
                                    color: Colors.black87,
                                    child: const Icon(
                                      Icons.play_circle_fill,
                                      color: Colors.white,
                                      size: 48,
                                    ),
                                  )
                                : Image.network(
                                    photo.imagePath,
                                    fit: BoxFit.cover,
                                    width: double.infinity,
                                    height: double.infinity,
                                  ),
                          ),
                          if (isNew) const Positioned(
                            top: 8,
                            right: 8,
                            child: NewDot(),
                          ),
                        ],
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (photo.description != null)
                            Text(
                              photo.description!,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          Text(
                            DateFormat('dd.MM.yyyy').format(photo.publishedAt),
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              );
            },
          );
        },
      ),
    );
  }
}
