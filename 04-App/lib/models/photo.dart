class Photo {
  final int id;
  final String imagePath;
  final String mediaType;
  final String? description;

  /// Wer die Veranstaltung bzw. das Foto eingereicht hat ("Tipp von ..."), null = ohne Namen.
  final String? submittedByName;
  final DateTime publishedAt;

  /// Gehoert dem angemeldeten Hoerer selbst - dann kein "Melden"/"Ausblenden" anbieten.
  final bool isOwn;

  const Photo({
    required this.id,
    required this.imagePath,
    this.mediaType = 'photo',
    this.description,
    this.submittedByName,
    required this.publishedAt,
    this.isOwn = false,
  });

  bool get isVideo => mediaType == 'video';

  factory Photo.fromJson(Map<String, dynamic> json) {
    return Photo(
      id: json['id'] as int,
      imagePath: json['image_path'] as String,
      mediaType: json['media_type'] as String? ?? 'photo',
      description: json['description'] as String?,
      submittedByName: _nameOrNull(json['submitted_by_name']),
      publishedAt: DateTime.parse(json['published_at'] as String),
      isOwn: json['is_own'] == true,
    );
  }
}

String? _nameOrNull(dynamic value) {
  final name = value is String ? value.trim() : '';
  return name.isEmpty ? null : name;
}
