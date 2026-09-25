import 'package:flutter/material.dart';

/// Farbpalette aus der Homepage (siehe 02-Design/Design-System.md).
class AppColors {
  static const primary = Color(0xFF77B538);
  static const primaryHover = Color(0xFF55832E);
  static const secondary = Color(0xFFE2DDBF);
  static const textDark = Color(0xFF102024);

  static const darkBackground = Color(0xFF121212);
  static const darkSurfaceHeader = Color(0xFF1B1B1B);
  static const darkCard = Color(0xFF1F1F1F);
  static const darkText = Color(0xFFEEEEEE);
}

class AppTheme {
  static const _borderRadiusContainer = 12.0;
  static const _borderRadiusInput = 6.0;
  /// Hausschrift "Suedsalat" (aus Libre Franklin, auf das Logo abgestimmt - siehe pubspec.yaml).
  static const fontFamily = 'Suedsalat';

  // Ueberschriften (Titel/AppBar) fett wie "SUEDSALAT" im Logo, Fliesstext normal wie
  // "THEMEN AUS DEM LEBEN". Laufweite und Buchstabenabstaende stecken in der Schrift selbst.
  static TextStyle? _heading(TextStyle? s) => s?.copyWith(fontFamily: fontFamily, fontWeight: FontWeight.w700);

  static TextStyle? _body(TextStyle? s) => s?.copyWith(fontFamily: fontFamily);

  static TextTheme _textTheme(Brightness brightness) {
    final base = ThemeData(brightness: brightness, useMaterial3: true).textTheme;
    return base.copyWith(
      displayLarge: _heading(base.displayLarge),
      displayMedium: _heading(base.displayMedium),
      displaySmall: _heading(base.displaySmall),
      headlineLarge: _heading(base.headlineLarge),
      headlineMedium: _heading(base.headlineMedium),
      headlineSmall: _heading(base.headlineSmall),
      titleLarge: _heading(base.titleLarge),
      titleMedium: _heading(base.titleMedium),
      titleSmall: _heading(base.titleSmall),
      bodyLarge: _body(base.bodyLarge),
      bodyMedium: _body(base.bodyMedium),
      bodySmall: _body(base.bodySmall),
      labelLarge: _body(base.labelLarge),
      labelMedium: _body(base.labelMedium),
      labelSmall: _body(base.labelSmall),
    );
  }

  static ThemeData light() {
    final textTheme = _textTheme(Brightness.light);
    return ThemeData(
      brightness: Brightness.light,
      fontFamily: fontFamily,
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppColors.primary,
        brightness: Brightness.light,
      ).copyWith(
        primary: AppColors.primary,
        secondary: AppColors.secondary,
        onPrimary: Colors.white,
      ),
      scaffoldBackgroundColor: Colors.white,
      textTheme: textTheme.apply(bodyColor: AppColors.textDark, displayColor: AppColors.textDark),
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        centerTitle: true,
      ),
      cardTheme: CardThemeData(
        color: AppColors.secondary,
        elevation: 2,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_borderRadiusContainer)),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: Colors.white,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_borderRadiusInput)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(_borderRadiusInput)),
      ),
      sliderTheme: SliderThemeData(
        activeTrackColor: AppColors.primary,
        thumbColor: AppColors.primary,
        overlayColor: AppColors.primary.withValues(alpha: 0.2),
      ),
    );
  }

  static ThemeData dark() {
    final textTheme = _textTheme(Brightness.dark);
    return ThemeData(
      brightness: Brightness.dark,
      fontFamily: fontFamily,
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppColors.primary,
        brightness: Brightness.dark,
      ).copyWith(
        primary: AppColors.primary,
        secondary: AppColors.secondary,
        surface: AppColors.darkCard,
      ),
      scaffoldBackgroundColor: AppColors.darkBackground,
      textTheme: textTheme.apply(bodyColor: AppColors.darkText, displayColor: AppColors.darkText),
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.darkSurfaceHeader,
        foregroundColor: AppColors.darkText,
        centerTitle: true,
      ),
      cardTheme: CardThemeData(
        color: AppColors.darkCard,
        elevation: 2,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_borderRadiusContainer)),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: Colors.white,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_borderRadiusInput)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(_borderRadiusInput)),
      ),
      sliderTheme: SliderThemeData(
        activeTrackColor: AppColors.primary,
        thumbColor: AppColors.primary,
        overlayColor: AppColors.primary.withValues(alpha: 0.2),
      ),
    );
  }
}
