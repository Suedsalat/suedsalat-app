import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';

/// Zeigt eine Mikro-Bewertung (5 Mikros) mit Teilfuellung an, z.B. 4,5 von 5
/// Mikros -> vier ganz gefuellte + ein halb gefuelltes Mikro. Optional antippbar,
/// um die Rezensionen zu diesem Eintrag zu oeffnen.
class MikroRatingDisplay extends StatelessWidget {
  final double? avgRating;
  final int reviewCount;
  final double iconSize;
  final VoidCallback? onTap;
  /// false fuer die Anzeige einer einzelnen Rezension (dort gibt es keine
  /// sinnvolle "Anzahl", das waere immer 0/"Noch keine Bewertungen").
  final bool showCountLabel;

  const MikroRatingDisplay({
    super.key,
    required this.avgRating,
    required this.reviewCount,
    this.iconSize = 18,
    this.onTap,
    this.showCountLabel = true,
  });

  @override
  Widget build(BuildContext context) {
    final rating = avgRating ?? 0;
    final row = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 0; i < 5; i++) ...[
          _MikroIcon(fill: (rating - i).clamp(0, 1).toDouble(), size: iconSize),
          if (i < 4) const SizedBox(width: 1),
        ],
        if (showCountLabel) ...[
          SizedBox(width: iconSize * 0.35),
          Text(
            reviewCount > 0 ? '($reviewCount)' : 'Noch keine Bewertungen',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ],
    );

    if (onTap == null) return row;
    // Mindest-Tippbereich (Material-Empfehlung ~48dp Hoehe), damit die Bewertung auch bei
    // kleiner Icon-Groesse zuverlaessig antippbar bleibt, nicht nur exakt auf den Icons.
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Padding(
        padding: EdgeInsets.symmetric(vertical: (48 - iconSize).clamp(8, 24) / 2),
        child: row,
      ),
    );
  }
}

class _MikroIcon extends StatelessWidget {
  final double fill;
  final double size;

  const _MikroIcon({required this.fill, required this.size});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        children: [
          ColorFiltered(
            colorFilter: const ColorFilter.mode(Color(0xFFBDBDBD), BlendMode.srcIn),
            child: Image.asset('assets/images/mikro_rating.png', width: size, height: size),
          ),
          if (fill > 0)
            ClipRect(
              child: Align(
                alignment: Alignment.centerLeft,
                widthFactor: fill,
                child: ColorFiltered(
                  colorFilter: const ColorFilter.mode(AppColors.primary, BlendMode.srcIn),
                  child: Image.asset('assets/images/mikro_rating.png', width: size, height: size),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
