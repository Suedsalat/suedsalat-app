import 'package:flutter/material.dart';

import '../../services/account_service.dart';
import '../home_screen.dart';
import 'login_screen.dart';
import 'register_screen.dart';

/// Startauswahl (App 2.0): erscheint, solange man weder angemeldet ist noch „Als Gast weiter"
/// gewaehlt hat - also auch einmal nach dem Update fuer alle bisherigen Nutzer.
class EntryScreen extends StatefulWidget {
  const EntryScreen({super.key});

  @override
  State<EntryScreen> createState() => _EntryScreenState();
}

class _EntryScreenState extends State<EntryScreen> {
  void _goHome() {
    Navigator.of(
      context,
    ).pushReplacement(MaterialPageRoute(builder: (_) => const HomeScreen()));
  }

  Future<void> _open(Widget screen) async {
    final done = await Navigator.of(
      context,
    ).push<bool>(MaterialPageRoute(builder: (_) => screen));
    if (done == true && mounted) _goHome();
  }

  Future<void> _asGuest() async {
    await AccountService.instance.chooseGuest();
    if (mounted) _goHome();
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
          children: [
            Center(child: Image.asset('assets/images/logo.png', width: 220)),
            const SizedBox(height: 16),
            Text(
              'Willkommen in der Südsalat-App!',
              style: text.headlineSmall,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            Text(
              'Hören, lesen und stöbern kannst du auch als Gast. Mit einem kostenlosen Hörerkonto '
              'reichst du außerdem Tipps, Fotos, Rezensionen und Sprachnachrichten ein – unter deinem '
              'Spitznamen.',
              style: text.bodyMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 32),
            ElevatedButton(
              onPressed: () => _open(const RegisterScreen()),
              child: const Text('Registrieren'),
            ),
            const SizedBox(height: 12),
            OutlinedButton(
              onPressed: () => _open(const LoginScreen()),
              child: const Text('Anmelden'),
            ),
            const SizedBox(height: 12),
            TextButton(
              onPressed: _asGuest,
              child: const Text('Als Gast weiter'),
            ),
            const SizedBox(height: 16),
            Text(
              'Du kannst dich auch später jederzeit in den Einstellungen registrieren oder anmelden.',
              style: text.bodySmall,
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}
