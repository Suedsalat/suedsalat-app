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

  /// Anzahl sichtbarer Kommentare (App 2.0).
  final int commentCount;

  const Photo({
    required this.id,
    required this.imagePath,
    this.mediaType = 'photo',
    this.description,
    this.submittedByName,
    required this.publishedAt,
    this.isOwn = false,
    this.commentCount = 0,
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
      commentCount: (json['comment_count'] as num?)?.toInt() ?? 0,
    );
  }
}

String? _nameOrNull(dynamic value) {
  final name = value is String ? value.trim() : '';
  return name.isEmpty ? null : name;
}
