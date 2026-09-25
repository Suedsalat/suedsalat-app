/// Kommentar unter einem Galerie-Foto (App 2.0). Der Name kommt live aus dem Hoererkonto.
class PhotoComment {
  final int id;
  final String authorName;
  final String text;
  final DateTime createdAt;

  /// Eigener Kommentar des angemeldeten Hoerers - dann "Loeschen" statt "Melden".
  final bool isOwn;

  const PhotoComment({
    required this.id,
    required this.authorName,
    required this.text,
    required this.createdAt,
    this.isOwn = false,
  });

  factory PhotoComment.fromJson(Map<String, dynamic> json) => PhotoComment(
    id: (json['id'] as num).toInt(),
    authorName: (json['author_name'] as String?)?.trim() ?? '',
    text: json['comment_text'] as String? ?? '',
    createdAt: DateTime.parse(json['created_at'] as String),
    isOwn: json['is_own'] == true,
  );
}
