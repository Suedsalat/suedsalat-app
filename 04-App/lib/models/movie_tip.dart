import 'json_helpers.dart';

class MovieTip {
  final int id;
  final String title;
  final String? description;
  final String? link;
  final String? episodeGuid;
  final int? episodeTimestampSeconds;
  final String? imagePath;
  final DateTime createdAt;
  final double? avgRating;
  final int reviewCount;

  const MovieTip({
    required this.id,
    required this.title,
    this.description,
    this.link,
    this.episodeGuid,
    this.episodeTimestampSeconds,
    this.imagePath,
    required this.createdAt,
    this.avgRating,
    this.reviewCount = 0,
  });

  factory MovieTip.fromJson(Map<String, dynamic> json) {
    return MovieTip(
      id: json['id'] as int,
      title: json['title'] as String,
      description: json['description'] as String?,
      link: json['link'] as String?,
      episodeGuid: json['episode_guid'] as String?,
      episodeTimestampSeconds: json['episode_timestamp_seconds'] as int?,
      imagePath: json['image_path'] as String?,
      createdAt: DateTime.parse(json['created_at'] as String),
      avgRating: parseNullableDouble(json['avg_rating']),
      reviewCount: parseIntOrZero(json['review_count']),
    );
  }
}
