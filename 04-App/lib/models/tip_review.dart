class TipReview {
  final int id;
  final int rating;
  final String? reviewText;
  final DateTime createdAt;

  const TipReview({
    required this.id,
    required this.rating,
    this.reviewText,
    required this.createdAt,
  });

  factory TipReview.fromJson(Map<String, dynamic> json) {
    return TipReview(
      id: json['id'] as int,
      rating: json['rating'] as int,
      reviewText: json['review_text'] as String?,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }
}
