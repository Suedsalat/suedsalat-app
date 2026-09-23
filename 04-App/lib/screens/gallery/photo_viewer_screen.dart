import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

/// Ein einzelnes Bild im Viewer. Bewusst ein eigener kleiner Typ statt des
/// Photo-Modells: der Viewer wird auch von Veranstaltungen, Film- und
/// Locationtipps benutzt, die nur eine nackte Bild-URL haben.
class PhotoViewerItem {
  final String imageUrl;
  final String? description;
  final DateTime? publishedAt;

  const PhotoViewerItem({required this.imageUrl, this.description, this.publishedAt});
}

class PhotoViewerScreen extends StatefulWidget {
  final List<PhotoViewerItem> items;
  final int initialIndex;

  /// Wird bei jedem angezeigten Bild mit dessen Position gemeldet, damit die
  /// Galerie auch durchgewischte Fotos als gesehen markieren kann.
  final void Function(int index)? onPageShown;

  const PhotoViewerScreen.gallery({
    super.key,
    required this.items,
    required this.initialIndex,
    this.onPageShown,
  });

  /// Einzelnes Bild ohne Blaettern - so rufen Veranstaltungen, Film- und
  /// Locationtipps den Viewer auf.
  PhotoViewerScreen({
    super.key,
    required String imageUrl,
    String? description,
    DateTime? publishedAt,
  })  : items = [
          PhotoViewerItem(imageUrl: imageUrl, description: description, publishedAt: publishedAt),
        ],
        initialIndex = 0,
        onPageShown = null;

  @override
  State<PhotoViewerScreen> createState() => _PhotoViewerScreenState();
}

class _PhotoViewerScreenState extends State<PhotoViewerScreen> {
  late final PageController _pageController;
  late int _currentIndex;

  /// Solange in ein Bild hineingezoomt ist, muss das seitliche Blaettern
  /// abgeschaltet sein - sonst blaettert schon das Verschieben des Ausschnitts
  /// zum naechsten Foto weiter.
  final _transformController = TransformationController();
  bool _isZoomed = false;

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialIndex;
    _pageController = PageController(initialPage: widget.initialIndex);
    _transformController.addListener(_onTransformChanged);
    widget.onPageShown?.call(_currentIndex);
  }

  @override
  void dispose() {
    _transformController.removeListener(_onTransformChanged);
    _transformController.dispose();
    _pageController.dispose();
    super.dispose();
  }

  void _onTransformChanged() {
    final zoomed = _transformController.value.getMaxScaleOnAxis() > 1.01;
    if (zoomed != _isZoomed) setState(() => _isZoomed = zoomed);
  }

  void _onPageChanged(int index) {
    // Zoom zuruecksetzen, damit das naechste Bild nicht im Ausschnitt des
    // vorherigen startet.
    _transformController.value = Matrix4.identity();
    setState(() => _currentIndex = index);
    widget.onPageShown?.call(index);
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.items[_currentIndex];
    final hasCaption = item.description != null && item.description!.isNotEmpty;
    final hasMultiple = widget.items.length > 1;

    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        children: [
          Positioned.fill(
            child: PageView.builder(
              controller: _pageController,
              physics: _isZoomed
                  ? const NeverScrollableScrollPhysics()
                  : const PageScrollPhysics(),
              itemCount: widget.items.length,
              onPageChanged: _onPageChanged,
              itemBuilder: (context, index) {
                return InteractiveViewer(
                  // Nur das gerade sichtbare Bild haengt am Controller; die
                  // Nachbarseiten bekommen ihren eigenen, damit ihr Zoomzustand
                  // das Blaettern nicht beeinflusst.
                  transformationController: index == _currentIndex ? _transformController : null,
                  minScale: 1,
                  maxScale: 4,
                  child: Center(
                    child: Image.network(widget.items[index].imageUrl, fit: BoxFit.contain),
                  ),
                );
              },
            ),
          ),
          if (hasCaption || item.publishedAt != null)
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: SafeArea(
                child: Container(
                  width: double.infinity,
                  padding: const EdgeInsets.fromLTRB(16, 20, 16, 16),
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [Colors.transparent, Colors.black87],
                    ),
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (hasCaption)
                        Text(item.description!, style: const TextStyle(color: Colors.white)),
                      if (item.publishedAt != null) ...[
                        if (hasCaption) const SizedBox(height: 4),
                        Text(
                          DateFormat('dd.MM.yyyy').format(item.publishedAt!),
                          style: const TextStyle(color: Colors.white70, fontSize: 12),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          if (hasMultiple)
            Positioned(
              top: 8,
              left: 16,
              child: SafeArea(
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.black54,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    '${_currentIndex + 1} / ${widget.items.length}',
                    style: const TextStyle(color: Colors.white, fontSize: 13),
                  ),
                ),
              ),
            ),
          Positioned(
            top: 8,
            right: 8,
            child: SafeArea(
              child: IconButton(
                icon: const Icon(Icons.close, color: Colors.white, size: 32),
                onPressed: () => Navigator.of(context).pop(),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
