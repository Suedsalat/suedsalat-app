class Photo {
  final int id;
  final String imagePath;
  final String mediaType;
  final String? description;
  final DateTime publishedAt;

  const Photo({
    required this.id,
    required this.imagePath,
    this.mediaType = 'photo',
    this.description,
    required this.publishedAt,
  });

  bool get isVideo => mediaType == 'video';

  factory Photo.fromJson(Map<String, dynamic> json) {
    return Photo(
      id: json['id'] as int,
      imagePath: json['image_path'] as String,
      mediaType: json['media_type'] as String? ?? 'photo',
      description: json['description'] as String?,
      publishedAt: DateTime.parse(json['published_at'] as String),
    );
  }
}
