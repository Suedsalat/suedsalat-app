import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

class PhotoViewerScreen extends StatelessWidget {
  final String imageUrl;
  final String? description;
  final DateTime? publishedAt;

  const PhotoViewerScreen({super.key, required this.imageUrl, this.description, this.publishedAt});

  @override
  Widget build(BuildContext context) {
    final hasCaption = description != null && description!.isNotEmpty;
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        children: [
          Positioned.fill(
            child: InteractiveViewer(
              minScale: 1,
              maxScale: 4,
              child: Center(
                child: Image.network(imageUrl, fit: BoxFit.contain),
              ),
            ),
          ),
          if (hasCaption || publishedAt != null)
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
                        Text(description!, style: const TextStyle(color: Colors.white)),
                      if (publishedAt != null) ...[
                        if (hasCaption) const SizedBox(height: 4),
                        Text(
                          DateFormat('dd.MM.yyyy').format(publishedAt!),
                          style: const TextStyle(color: Colors.white70, fontSize: 12),
                        ),
                      ],
                    ],
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
