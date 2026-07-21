import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';

/// Fuenf antippbare Mikro-Symbole zur Auswahl einer ganzzahligen Bewertung (1-5).
class MikroRatingInput extends StatefulWidget {
  final int initialRating;
  final ValueChanged<int> onChanged;
  final double iconSize;

  const MikroRatingInput({
    super.key,
    this.initialRating = 0,
    required this.onChanged,
    this.iconSize = 36,
  });

  @override
  State<MikroRatingInput> createState() => _MikroRatingInputState();
}

class _MikroRatingInputState extends State<MikroRatingInput> {
  late int _rating = widget.initialRating;

  void _select(int value) {
    setState(() => _rating = value);
    widget.onChanged(value);
  }

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 1; i <= 5; i++)
          IconButton(
            onPressed: () => _select(i),
            icon: ColorFiltered(
              colorFilter: ColorFilter.mode(
                i <= _rating ? AppColors.primary : const Color(0xFFBDBDBD),
                BlendMode.srcIn,
              ),
              child: Image.asset(
                'assets/images/mikro_rating.png',
                width: widget.iconSize,
                height: widget.iconSize,
              ),
            ),
          ),
      ],
    );
  }
}
