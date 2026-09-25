import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:suedsalat_app/theme/app_theme.dart';

/// Die App darf Open Sans nicht zur Laufzeit von Google laden (Datenschutz,
/// siehe main.dart). Mit abgeschaltetem Nachladen muss deshalb jede Variante,
/// die das Theme anfordert, als Datei unter assets/google_fonts/ liegen - sonst
/// faellt der Text in der App an dieser Stelle still auf eine Ersatzschrift zurueck.
///
/// google_fonts schreibt die angeforderte Variante in den Familiennamen des Stils
/// (z. B. "OpenSans_regular", "OpenSans_500"). Daraus leitet der Test den
/// erwarteten Dateinamen ab, wie ihn das Paket in den Assets sucht.
void main() {
  // Variante laut google_fonts -> Namensteil der Schriftdatei.
  const dateiteil = {
    'regular': 'Regular',
    'italic': 'Italic',
    '300': 'Light',
    '500': 'Medium',
    '600': 'SemiBold',
    '700': 'Bold',
    '800': 'ExtraBold',
  };

  testWidgets('Jede angeforderte Open-Sans-Variante liegt in den App-Assets', (tester) async {
    GoogleFonts.config.allowRuntimeFetching = false;

    final angefordert = <String>{};
    for (final theme in [AppTheme.light(), AppTheme.dark()]) {
      final t = theme.textTheme;
      for (final style in <TextStyle?>[
        t.displayLarge, t.displayMedium, t.displaySmall,
        t.headlineLarge, t.headlineMedium, t.headlineSmall,
        t.titleLarge, t.titleMedium, t.titleSmall,
        t.bodyLarge, t.bodyMedium, t.bodySmall,
        t.labelLarge, t.labelMedium, t.labelSmall,
      ]) {
        final familie = style?.fontFamily ?? '';
        if (familie.startsWith('OpenSans_')) angefordert.add(familie.substring('OpenSans_'.length));
      }
    }

    expect(angefordert, isNotEmpty, reason: 'Das Theme nutzt Open Sans gar nicht mehr?');
    for (final variante in angefordert) {
      final teil = dateiteil[variante];
      expect(teil, isNotNull, reason: 'Unbekannte Variante "$variante" - Zuordnung im Test ergaenzen.');
      final datei = File('assets/google_fonts/OpenSans-$teil.ttf');
      expect(datei.existsSync(), isTrue,
          reason: 'Variante "$variante" wird angefordert, aber ${datei.path} fehlt.');
    }
  });
}
