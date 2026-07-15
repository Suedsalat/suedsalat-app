class Episode {
  final String guid;
  final String title;
  final String? description;
  final String audioUrl;
  final String? imageUrl;
  final String? duration;
  final DateTime pubDate;

  const Episode({
    required this.guid,
    required this.title,
    this.description,
    required this.audioUrl,
    this.imageUrl,
    this.duration,
    required this.pubDate,
  });

  factory Episode.fromJson(Map<String, dynamic> json) {
    return Episode(
      guid: json['guid'] as String,
      title: json['title'] as String,
      description: json['description'] as String?,
      audioUrl: json['audio_url'] as String,
      imageUrl: json['image_url'] as String?,
      duration: json['duration'] as String?,
      pubDate: DateTime.parse(json['pub_date'] as String),
    );
  }
}
