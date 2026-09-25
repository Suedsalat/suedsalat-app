import 'package:flutter/material.dart';

/// Name, den der Server fuer geloeschte Konten liefert (ListenerContent::FORMER_MEMBER).
const kFormerMember = 'Ehemaliges Mitglied';

/// "Tipp von Angela" unter Film- und Locationtipps und Veranstaltungen, "Foto von ..."
/// bei Galerie-Fotos. Steht ueberall einheitlich - bei Einsendungen mit dem Namen des
/// Einsenders, bei eigenen Beitraegen mit "Suedsalat" -, statt wie frueher nur bei
/// Einsendungen vorne in der Beschreibung.
class TipSubmitterLine extends StatelessWidget {
  final String name;

  /// Wort vor dem Namen, z. B. "Tipp von" oder "Foto von"; leer = nur der Name (Rezensionen).
  final String prefix;

  /// Farbe fuer dunklen Hintergrund (Foto-Vollbild); sonst aus dem Theme.
  final Color? color;

  const TipSubmitterLine({super.key, required this.name, this.prefix = 'Tipp von', this.color});

  /// Allein steht der Name wie geliefert; mit "Tipp von"/"Foto von" davor wird aus
  /// "Ehemaliges Mitglied" grammatisch richtig "Tipp von einem ehemaligen Mitglied".
  String get _label {
    if (prefix.isEmpty) return name;
    if (name == kFormerMember) return '$prefix einem ehemaligen Mitglied';
    return '$prefix $name';
  }

  @override
  Widget build(BuildContext context) {
    final color = this.color ?? Theme.of(context).colorScheme.onSurfaceVariant;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.person_outline, size: 14, color: color),
        const SizedBox(width: 4),
        Flexible(
          child: Text(
            _label,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(color: color),
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }
}
