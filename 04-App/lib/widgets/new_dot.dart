import 'package:flutter/material.dart';

/// Kleiner gruener Punkt, der auf neue (noch nicht gesehene) Inhalte hinweist.
class NewDot extends StatelessWidget {
  const NewDot({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 10,
      height: 10,
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.primary,
        shape: BoxShape.circle,
      ),
    );
  }
}
