import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

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
  static const _headingFontFamily = 'FranklinGothicDemi';

  // Ueberschriften (Titel/AppBar) in Franklin Gothic Demi, Fliesstext bleibt Open Sans.
  static TextTheme _withHeadingFont(TextTheme base) {
    return base.copyWith(
      displayLarge: base.displayLarge?.copyWith(fontFamily: _headingFontFamily),
      displayMedium: base.displayMedium?.copyWith(fontFamily: _headingFontFamily),
      displaySmall: base.displaySmall?.copyWith(fontFamily: _headingFontFamily),
      headlineLarge: base.headlineLarge?.copyWith(fontFamily: _headingFontFamily),
      headlineMedium: base.headlineMedium?.copyWith(fontFamily: _headingFontFamily),
      headlineSmall: base.headlineSmall?.copyWith(fontFamily: _headingFontFamily),
      titleLarge: base.titleLarge?.copyWith(fontFamily: _headingFontFamily),
      titleMedium: base.titleMedium?.copyWith(fontFamily: _headingFontFamily),
      titleSmall: base.titleSmall?.copyWith(fontFamily: _headingFontFamily),
    );
  }

  static ThemeData light() {
    final textTheme = _withHeadingFont(GoogleFonts.openSansTextTheme());
    return ThemeData(
      brightness: Brightness.light,
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
    final textTheme = _withHeadingFont(GoogleFonts.openSansTextTheme());
    return ThemeData(
      brightness: Brightness.dark,
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
