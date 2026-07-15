class Event {
  final int id;
  final String title;
  final DateTime eventDate;
  final String? eventTime;
  final String? eventEndTime;
  final String? description;
  final String? link;
  final String? episodeGuid;
  final int? episodeTimestampSeconds;
  final String? imagePath;

  const Event({
    required this.id,
    required this.title,
    required this.eventDate,
    this.eventTime,
    this.eventEndTime,
    this.description,
    this.link,
    this.episodeGuid,
    this.episodeTimestampSeconds,
    this.imagePath,
  });

  factory Event.fromJson(Map<String, dynamic> json) {
    return Event(
      id: json['id'] as int,
      title: json['title'] as String,
      eventDate: DateTime.parse(json['event_date'] as String),
      eventTime: json['event_time'] as String?,
      eventEndTime: json['event_end_time'] as String?,
      description: json['description'] as String?,
      link: json['link'] as String?,
      episodeGuid: json['episode_guid'] as String?,
      episodeTimestampSeconds: json['episode_timestamp_seconds'] as int?,
      imagePath: json['image_path'] as String?,
    );
  }
}
