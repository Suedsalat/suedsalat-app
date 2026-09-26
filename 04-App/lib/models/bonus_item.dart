import 'episode.dart';

/// Outtakes (App 2.0, intern "bonus"): eigene Audiodateien, nur fuer angemeldete Hoerer.
class BonusItem {
  final int id;
  final String title;
  final String? description;
  final String audioUrl;
  final DateTime publishedAt;

  const BonusItem({
    required this.id,
    required this.title,
    this.description,
    required this.audioUrl,
    required this.publishedAt,
  });

  factory BonusItem.fromJson(Map<String, dynamic> json) => BonusItem(
        id: (json['id'] as num).toInt(),
        title: json['title'] as String,
        description: json['description'] as String?,
        audioUrl: json['audio_url'] as String,
        publishedAt: DateTime.parse(json['published_at'] as String),
      );

  /// Guid-Praefix, an dem der Player Outtakes von Podcast-Folgen unterscheidet
  /// (keine Folgen-Statistik, eigener Merkplatz fuer die Wiedergabeposition).
  static const guidPrefix = 'bonus-';

  /// Fuer den gemeinsamen Player (Sperrbildschirm, Android Auto, Wiedergabeposition).
  Episode toEpisode() => Episode(
        guid: '$guidPrefix$id',
        title: title,
        description: description,
        audioUrl: audioUrl,
        pubDate: publishedAt,
      );
}
