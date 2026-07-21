class LocationTip {
  final int id;
  final String name;
  final String location;
  final String? description;
  final String? link;
  final String? episodeGuid;
  final int? episodeTimestampSeconds;
  final String? imagePath;
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
      createdAt: DateTime.parse(json['created_at'] as String),
      avgRating: (json['avg_rating'] as num?)?.toDouble(),
      reviewCount: (json['review_count'] as num?)?.toInt() ?? 0,
    );
  }
}
