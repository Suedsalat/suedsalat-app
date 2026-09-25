import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:suedsalat_app/theme/app_theme.dart';

/// Die App nutzt nur die Hausschrift Suedsalat aus assets/fonts/ (freie Lizenz, nichts wird nachgeladen).
/// Schlaegt fehl, wenn das Theme eine andere Schrift oder eine Staerke anfordert, fuer die keine
/// Datei hinterlegt ist - dann wuerde Flutter still eine Ersatzschrift nehmen.
void main() {
  final pubspec = File('pubspec.yaml').readAsStringSync();
  final hinterlegt = {
    for (final m in RegExp(r'asset: (assets/fonts/Suedsalat-\w+\.ttf)\s+weight: (\d+)').allMatches(pubspec))
      int.parse(m.group(2)!): m.group(1)!,
  };

  test('Schriftdateien liegen vor', () {
    expect(hinterlegt.keys, containsAll([400, 500, 600, 700]));
    for (final datei in hinterlegt.values) {
      expect(File(datei).existsSync(), isTrue, reason: datei);
    }
    expect(File('assets/fonts/Suedsalat-OFL.txt').existsSync(), isTrue, reason: 'Lizenztext muss mit');
  });

  for (final (name, theme) in [('hell', AppTheme.light()), ('dunkel', AppTheme.dark())]) {
    test('Theme $name: nur Suedsalat in hinterlegten Staerken', () {
      final t = theme.textTheme;
      final stile = [
        t.displayLarge, t.displayMedium, t.displaySmall, t.headlineLarge, t.headlineMedium, t.headlineSmall,
        t.titleLarge, t.titleMedium, t.titleSmall, t.bodyLarge, t.bodyMedium, t.bodySmall,
        t.labelLarge, t.labelMedium, t.labelSmall,
      ];
      for (final s in stile) {
        expect(s?.fontFamily, AppTheme.fontFamily);
        final gewicht = (s?.fontWeight ?? FontWeight.w400).value;
        expect(hinterlegt.containsKey(gewicht), isTrue, reason: 'Staerke $gewicht ohne Datei');
      }
      expect(t.titleLarge?.fontWeight, FontWeight.w700, reason: 'Ueberschriften fett wie im Logo');
    });
  }

  test('Keine lizenzpflichtige Franklin Gothic mehr in der App', () {
    expect(Directory('assets/fonts').listSync().where((f) => f.path.toLowerCase().contains('franklingothic')), isEmpty);
    expect(pubspec.contains('google_fonts'), isFalse);
  });
}
