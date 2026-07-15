import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'screens/splash_screen.dart';
import 'services/push_notification_service.dart';
import 'theme/app_theme.dart';

const double _kMaxAppWidth = 840;

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Firebase.initializeApp();

  final prefs = await SharedPreferences.getInstance();
  if (prefs.getBool('push_enabled') ?? true) {
    PushNotificationService.instance.enable();
  }

  runApp(const SuedsalatApp());
}

class SuedsalatApp extends StatelessWidget {
  const SuedsalatApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Südsalat',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: ThemeMode.system,
      locale: const Locale('de'),
      supportedLocales: const [Locale('de')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) {
        final isDark = MediaQuery.platformBrightnessOf(context) == Brightness.dark;
        return Container(
          color: isDark ? AppColors.darkBackground : Colors.white,
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: _kMaxAppWidth),
              child: child,
            ),
          ),
        );
      },
      home: const SplashScreen(),
    );
  }
}
