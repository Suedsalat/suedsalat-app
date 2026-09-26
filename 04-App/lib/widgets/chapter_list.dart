import 'package:flutter/material.dart';

import '../models/chapter.dart';

/// Kapitelliste im Player: Antippen springt hin, das laufende Kapitel ist markiert.
class ChapterList extends StatelessWidget {
  const ChapterList({super.key, required this.chapters, required this.currentIndex, required this.onTap});

  final List<Chapter> chapters;
  final int currentIndex;
  final void Function(Chapter chapter) onTap;

  static String label(Duration d) {
    String zwei(int n) => n.toString().padLeft(2, '0');
    return d.inHours > 0
        ? '${d.inHours}:${zwei(d.inMinutes.remainder(60))}:${zwei(d.inSeconds.remainder(60))}'
        : '${zwei(d.inMinutes)}:${zwei(d.inSeconds.remainder(60))}';
  }

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;
    return Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            child: Text('Kapitel', style: Theme.of(context).textTheme.titleMedium),
          ),
          for (var i = 0; i < chapters.length; i++)
            ListTile(
              dense: true,
              selected: i == currentIndex,
              selectedColor: primary,
              leading: Text(
                label(chapters[i].start),
                style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()]),
              ),
              title: Text(chapters[i].title),
              trailing: i == currentIndex ? const Icon(Icons.graphic_eq) : null,
              onTap: () => onTap(chapters[i]),
            ),
        ],
      ),
    );
  }
}
