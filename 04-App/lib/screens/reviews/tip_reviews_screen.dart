import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../services/api_service.dart';
import '../../widgets/async_state_views.dart';
import '../../widgets/rating/mikro_rating_display.dart';
import '../../widgets/rating/mikro_rating_input.dart';

/// Zeigt Durchschnittsbewertung + freigegebene Einzelrezensionen zu einem
/// Kino-/Filmtipp oder Locationtipp und erlaubt das Einreichen einer eigenen
/// Rezension. [tipType] ist 'movie_tip' oder 'location_tip'.
class TipReviewsScreen extends StatefulWidget {
  final String tipType;
  final int tipId;
  final String tipTitle;

  const TipReviewsScreen({
    super.key,
    required this.tipType,
    required this.tipId,
    required this.tipTitle,
  });

  @override
  State<TipReviewsScreen> createState() => _TipReviewsScreenState();
}

class _TipReviewsScreenState extends State<TipReviewsScreen> {
  final _formKey = GlobalKey<FormState>();
  final _api = ApiService();
  late Future<TipReviewSummary> _future;
  final _nameController = TextEditingController();
  final _reviewTextController = TextEditingController();
  int _newRating = 0;
  bool _submitting = false;
  String? _ratingError;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _reviewTextController.dispose();
    super.dispose();
  }

  Future<TipReviewSummary> _load() {
    return _api.fetchTipReviews(widget.tipType, widget.tipId);
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _submit() async {
    setState(() => _ratingError = _newRating < 1 ? 'Bitte wähle eine Bewertung aus.' : null);
    if (!_formKey.currentState!.validate() || _ratingError != null) return;

    setState(() => _submitting = true);
    try {
      await _api.submitReview(
        widget.tipType,
        widget.tipId,
        _newRating,
        _nameController.text,
        _reviewTextController.text,
      );
      if (!mounted) return;
      setState(() {
        _newRating = 0;
        _nameController.clear();
        _reviewTextController.clear();
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          backgroundColor: Color(0xFF77B538),
          content: Text('Danke! Deine Rezension wird nach kurzer Prüfung sichtbar.'),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString().replaceFirst('Exception: ', ''))),
      );
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.tipTitle)),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<TipReviewSummary>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const LoadingStateView();
            }
            if (snapshot.hasError) {
              return ErrorStateView(onRetry: _reload);
            }
            final summary = snapshot.data!;
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              children: [
                Center(
                  child: MikroRatingDisplay(
                    avgRating: summary.avgRating,
                    reviewCount: summary.reviewCount,
                    iconSize: 32,
                  ),
                ),
                const SizedBox(height: 24),
                if (summary.reviews.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12),
                    child: Text('Noch keine Rezensionen zu diesem Eintrag.'),
                  )
                else
                  for (final review in summary.reviews)
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                MikroRatingDisplay(
                                  avgRating: review.rating.toDouble(),
                                  reviewCount: 0,
                                  iconSize: 16,
                                  showCountLabel: false,
                                ),
                                const Spacer(),
                                Text(
                                  DateFormat('dd.MM.yyyy').format(review.createdAt),
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              ],
                            ),
                            if (review.reviewerName != null && review.reviewerName!.isNotEmpty) ...[
                              const SizedBox(height: 6),
                              Text(
                                review.reviewerName!,
                                style: Theme.of(context).textTheme.labelLarge,
                              ),
                            ],
                            if (review.reviewText != null && review.reviewText!.isNotEmpty) ...[
                              const SizedBox(height: 4),
                              Text(review.reviewText!),
                            ],
                          ],
                        ),
                      ),
                    ),
                const Divider(height: 40),
                Text('Deine Rezension', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 12),
                Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Center(
                        child: MikroRatingInput(
                          initialRating: _newRating,
                          onChanged: (value) => setState(() {
                            _newRating = value;
                            _ratingError = null;
                          }),
                        ),
                      ),
                      if (_ratingError != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Text(
                            _ratingError!,
                            textAlign: TextAlign.center,
                            style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12),
                          ),
                        ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _nameController,
                        decoration: const InputDecoration(labelText: 'Dein Name'),
                        validator: (value) =>
                            (value == null || value.trim().isEmpty) ? 'Bitte gib deinen Namen ein.' : null,
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _reviewTextController,
                        decoration: const InputDecoration(labelText: 'Deine Meinung'),
                        maxLines: 3,
                        maxLength: 500,
                        validator: (value) => (value == null || value.trim().isEmpty)
                            ? 'Bitte schreibe eine kurze Rezension.'
                            : null,
                      ),
                      const SizedBox(height: 8),
                      ElevatedButton(
                        onPressed: _submitting ? null : _submit,
                        child: _submitting
                            ? const SizedBox(
                                height: 20,
                                width: 20,
                                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                              )
                            : const Text('Rezension abschicken'),
                      ),
                    ],
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}
