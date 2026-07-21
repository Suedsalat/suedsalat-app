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
  final _api = ApiService();
  late Future<TipReviewSummary> _future;
  final _reviewTextController = TextEditingController();
  int _newRating = 0;
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void dispose() {
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
    if (_newRating < 1) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Bitte wähle zuerst eine Bewertung aus.')),
      );
      return;
    }
    setState(() => _submitting = true);
    try {
      await _api.submitReview(widget.tipType, widget.tipId, _newRating, _reviewTextController.text);
      if (!mounted) return;
      setState(() {
        _newRating = 0;
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
                                MikroRatingDisplay(avgRating: review.rating.toDouble(), reviewCount: 0, iconSize: 16),
                                const Spacer(),
                                Text(
                                  DateFormat('dd.MM.yyyy').format(review.createdAt),
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              ],
                            ),
                            if (review.reviewText != null && review.reviewText!.isNotEmpty) ...[
                              const SizedBox(height: 8),
                              Text(review.reviewText!),
                            ],
                          ],
                        ),
                      ),
                    ),
                const Divider(height: 40),
                Text('Deine Rezension', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 12),
                Center(
                  child: MikroRatingInput(
                    initialRating: _newRating,
                    onChanged: (value) => setState(() => _newRating = value),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _reviewTextController,
                  decoration: const InputDecoration(labelText: 'Rezensionstext (optional)'),
                  maxLines: 3,
                  maxLength: 500,
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
            );
          },
        ),
      ),
    );
  }
}
