import 'package:chewie/chewie.dart';
import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

/// Eine Videoseite innerhalb des Galerie-Viewers.
///
/// Anders als beim frueheren eigenstaendigen Videobildschirm liegen hier
/// mehrere Seiten nebeneinander im PageView. Der Player haelt deshalb an,
/// sobald seine Seite nicht mehr die sichtbare ist - sonst liefe der Ton
/// weiter, waehrend man schon das naechste Bild ansieht.
class GalleryVideoPage extends StatefulWidget {
  final String videoUrl;

  /// Ob diese Seite gerade die sichtbare ist.
  final bool isActive;

  /// Nur beim direkt angetippten Video wird von selbst abgespielt. Blaettert
  /// man dagegen durch die Galerie, startet der Ton nicht ungefragt.
  final bool autoPlay;

  const GalleryVideoPage({
    super.key,
    required this.videoUrl,
    required this.isActive,
    required this.autoPlay,
  });

  @override
  State<GalleryVideoPage> createState() => _GalleryVideoPageState();
}

class _GalleryVideoPageState extends State<GalleryVideoPage> {
  VideoPlayerController? _videoController;
  ChewieController? _chewieController;
  String? _error;

  @override
  void initState() {
    super.initState();
    _initialize();
  }

  Future<void> _initialize() async {
    final controller = VideoPlayerController.networkUrl(Uri.parse(widget.videoUrl));
    _videoController = controller;
    try {
      await controller.initialize();
      if (!mounted) {
        await controller.dispose();
        return;
      }
      setState(() {
        _chewieController = ChewieController(
          videoPlayerController: controller,
          autoPlay: widget.isActive && widget.autoPlay,
          looping: false,
        );
      });
    } catch (_) {
      if (mounted) setState(() => _error = 'Video konnte nicht geladen werden.');
    }
  }

  @override
  void didUpdateWidget(covariant GalleryVideoPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Weggeblaettert: anhalten, aber die Stelle behalten, damit man dort
    // weitersehen kann, wenn man zurueckblaettert.
    if (oldWidget.isActive && !widget.isActive) {
      _videoController?.pause();
    }
  }

  @override
  void dispose() {
    _chewieController?.dispose();
    _videoController?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) {
      return Center(child: Text(_error!, style: const TextStyle(color: Colors.white)));
    }
    final chewie = _chewieController;
    final video = _videoController;
    if (chewie == null || video == null) {
      return const Center(child: CircularProgressIndicator(color: Colors.white));
    }
    return Center(
      child: AspectRatio(
        aspectRatio: video.value.aspectRatio,
        child: Chewie(controller: chewie),
      ),
    );
  }
}
