import 'package:flutter/material.dart';

/// Wraps [child] in a scrollable that always accepts drag gestures, so
/// RefreshIndicator can still be pulled down even when the content itself
/// (loading spinner, error message, empty state) is too short to scroll.
class _ScrollableCenter extends StatelessWidget {
  final Widget child;

  const _ScrollableCenter({required this.child});

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: constraints.maxHeight),
          child: Center(child: child),
        ),
      ),
    );
  }
}

class LoadingStateView extends StatelessWidget {
  const LoadingStateView({super.key});

  @override
  Widget build(BuildContext context) {
    return const _ScrollableCenter(child: CircularProgressIndicator());
  }
}

class ErrorStateView extends StatelessWidget {
  final VoidCallback onRetry;

  const ErrorStateView({super.key, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return _ScrollableCenter(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.cloud_off, size: 48),
          const SizedBox(height: 12),
          const Text('Konnte nicht geladen werden.'),
          const SizedBox(height: 12),
          ElevatedButton(onPressed: onRetry, child: const Text('Erneut versuchen')),
        ],
      ),
    );
  }
}

class EmptyStateView extends StatelessWidget {
  final String message;

  const EmptyStateView({super.key, required this.message});

  @override
  Widget build(BuildContext context) {
    return _ScrollableCenter(child: Text(message));
  }
}
