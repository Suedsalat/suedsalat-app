import 'package:flutter/material.dart';

/// "Tipp von Angela" unter einem Film- oder Locationtipp. Steht bei allen Tipps
/// einheitlich - bei eingereichten mit dem Namen des Einsenders, bei eigenen mit
/// Thorsten oder Jenny -, statt wie frueher nur bei eingereichten vorne in der
/// Beschreibung.
class TipSubmitterLine extends StatelessWidget {
  final String name;

  const TipSubmitterLine({super.key, required this.name});

  @override
  Widget build(BuildContext context) {
    final color = Theme.of(context).colorScheme.onSurfaceVariant;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.person_outline, size: 14, color: color),
        const SizedBox(width: 4),
        Flexible(
          child: Text(
            'Tipp von $name',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(color: color),
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }
}
