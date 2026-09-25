import 'json_helpers.dart';

class LocationTip {
  final int id;
  final String name;
  final String location;
  final String? description;
  final String? link;
  final String? episodeGuid;
  final int? episodeTimestampSeconds;
  final String? imagePath;

  /// Wer den Tipp gegeben hat ("Tipp von ..."), null = ohne Namen.
  final String? submittedByName;
  final DateTime createdAt;
  final double? avgRating;
  final int reviewCount;

  const LocationTip({
    required this.id,
    required this.name,
    required this.location,
    this.description,
    this.link,
    this.episodeGuid,
    this.episodeTimestampSeconds,
    this.imagePath,
    this.submittedByName,
    required this.createdAt,
    this.avgRating,
    this.reviewCount = 0,
  });

  factory LocationTip.fromJson(Map<String, dynamic> json) {
    return LocationTip(
      id: json['id'] as int,
      name: json['name'] as String,
      location: json['location'] as String,
      description: json['description'] as String?,
      link: json['link'] as String?,
      episodeGuid: json['episode_guid'] as String?,
      episodeTimestampSeconds: json['episode_timestamp_seconds'] as int?,
      imagePath: json['image_path'] as String?,
      submittedByName: _nameOrNull(json['submitted_by_name']),
      createdAt: DateTime.parse(json['created_at'] as String),
      avgRating: parseNullableDouble(json['avg_rating']),
      reviewCount: parseIntOrZero(json['review_count']),
    );
  }
}

String? _nameOrNull(dynamic value) {
  final name = value is String ? value.trim() : '';
  return name.isEmpty ? null : name;
}
