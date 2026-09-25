import 'package:audio_service/audio_service.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'screens/splash_screen.dart';
import 'services/account_service.dart';
import 'services/api_service.dart';
import 'services/audio_handler.dart';
import 'services/push_notification_service.dart';
import 'services/stats_consent_service.dart';
import 'theme/app_theme.dart';

const double _kMaxAppWidth = 840;

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Open Sans liegt unter assets/google_fonts/ in der App. Ohne diese Zeile wuerde
  // google_fonts die Schrift beim ersten Start von Googles Servern nachladen und
  // dabei die IP-Adresse des Nutzers an Google uebermitteln (Datenschutzerklaerung
  // Abschnitt 2 h - der Absatz kann raus, sobald diese Version ueberall laeuft).
  GoogleFonts.config.allowRuntimeFetching = false;
  // Die freie Schriftlizenz (SIL OFL) verlangt, dass sie mitgeliefert wird.
  LicenseRegistry.addLicense(() async* {
    final license = await rootBundle.loadString('assets/google_fonts/OFL.txt');
    yield LicenseEntryWithLineBreaks(['google_fonts'], license);
  });

  await Firebase.initializeApp();

  // Konto und Statistik-Einwilligung (App 2.0) aus dem lokalen Speicher - der Abgleich mit dem
  // Server laeuft im Hintergrund, damit der Start nicht auf das Netz wartet.
  await AccountService.instance.load();
  await StatsConsentService.instance.load();
  AccountService.instance.refresh();
  StatsConsentService.instance.sync();

  final prefs = await SharedPreferences.getInstance();
  if (prefs.getBool('push_enabled') ?? true) {
    PushNotificationService.instance.enable();
  }

  // Registriert die App als Medien-App beim Betriebssystem (Sperrbildschirm-
  // Steuerung, Android Auto, CarPlay-Standardbildschirm "Wird wiedergegeben").
  // Siehe SuedsalatAudioHandler fuer die eigentliche Anbindung an den
  // bestehenden AudioPlayerService.
  await AudioService.init(
    builder: () => SuedsalatAudioHandler(),
    config: const AudioServiceConfig(
      androidNotificationChannelId: 'eu.suedsalat.suedsalat_app.audio',
      androidNotificationChannelName: 'Südsalat Wiedergabe',
      androidNotificationOngoing: true,
      // Eigenes einfarbiges Icon statt des Standardwerts 'mipmap/ic_launcher' -
      // das grosse bunte App-Icon ist als Benachrichtigungs-Symbol ungeeignet
      // und liess die Benachrichtigung (und damit vermutlich auch Android Auto,
      // das darauf angewiesen ist) bisher vermutlich stillschweigend scheitern.
      androidNotificationIcon: 'drawable/ic_notification',
      // Markenfarbe Gruen (#77B538) - faerbt Benachrichtigung/Android-Auto-
      // Oberflaeche im Südsalat-Look statt eines Android-Standardtons.
      notificationColor: Color(0xFF77B538),
    ),
  );

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
              // Test-App gegen den Testbereich: unuebersehbares Band oben links.
              child: ApiService.isTestBackend
                  ? Banner(message: 'TEST', location: BannerLocation.topStart, child: child)
                  : child,
            ),
          ),
        );
      },
      home: const SplashScreen(),
    );
  }
}
