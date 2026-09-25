class TipReview {
  final int id;
  final int rating;
  final String? reviewText;
  final String? reviewerName;
  final DateTime createdAt;

  /// Eigene Rezension des angemeldeten Hoerers - dann kein "Melden"/"Ausblenden" anbieten.
  final bool isOwn;

  const TipReview({
    required this.id,
    required this.rating,
    this.reviewText,
    this.reviewerName,
    required this.createdAt,
    this.isOwn = false,
  });

  factory TipReview.fromJson(Map<String, dynamic> json) {
    return TipReview(
      id: json['id'] as int,
      rating: json['rating'] as int,
      reviewText: json['review_text'] as String?,
      reviewerName: json['reviewer_name'] as String?,
      createdAt: DateTime.parse(json['created_at'] as String),
      isOwn: json['is_own'] == true,
    );
  }
}
