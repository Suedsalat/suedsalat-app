import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../services/account_service.dart';
import '../../services/stats_consent_service.dart';
import '../settings/privacy_screen.dart';
import 'code_screen.dart';
import 'login_screen.dart';

/// Nutzungsbedingungen auf der Homepage (Etappe 8 des 2.0-Plans).
const kTermsUrl =
    'https://www.xn--sdsalat-n2a.eu/seiten/nutzungsbedingungen.html';

/// Registrierung: Vorname, Nachname, E-Mail, Spitzname -> Code per Mail -> Konto.
/// Schliesst sich mit `true`, wenn das Konto angelegt ist.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _firstName = TextEditingController();
  final _lastName = TextEditingController();
  final _email = TextEditingController();
  final _nickname = TextEditingController();
  bool _acceptTerms = false;
  bool _allowStats = false;
  bool _busy = false;
  String? _error;

  // Die Statistik-Frage nur, wenn auf diesem Geraet noch nicht entschieden (sonst Einstellungen).
  final bool _askStats = !StatsConsentService.instance.decided;

  @override
  void dispose() {
    _firstName.dispose();
    _lastName.dispose();
    _email.dispose();
    _nickname.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (!_acceptTerms) {
      setState(() => _error = 'Bitte akzeptiere die Nutzungsbedingungen.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final account = AccountService.instance;
    try {
      Future<String> request() => account.register(
        firstName: _firstName.text,
        lastName: _lastName.text,
        email: _email.text,
        nickname: _nickname.text,
      );
      final masked = await request();
      if (!mounted) return;
      final done = await Navigator.of(context).push<bool>(
        MaterialPageRoute(
          builder: (_) => CodeScreen(
            title: 'E-Mail bestätigen',
            maskedEmail: masked,
            submitLabel: 'Konto anlegen',
            onSubmit: (code) => account.verify(_email.text, code),
            onResend: request,
          ),
        ),
      );
      if (done == true) {
        if (_askStats) {
          await StatsConsentService.instance.decide(granted: _allowStats);
        }
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                'Willkommen, ${account.listener?.nickname ?? ''}! Dein Konto ist angelegt.',
              ),
            ),
          );
          Navigator.of(context).pop(true);
        }
      }
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? _required(String? value, String label) =>
      (value == null || value.trim().isEmpty) ? 'Bitte gib $label an.' : null;

  @override
  Widget build(BuildContext context) {
    final linkStyle = TextStyle(
      color: Theme.of(context).colorScheme.primary,
      decoration: TextDecoration.underline,
    );
    return Scaffold(
      appBar: AppBar(title: const Text('Registrieren')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            const Text(
              'Mit einem kostenlosen Hörerkonto kannst du Tipps, Fotos, Rezensionen und Sprachnachrichten '
              'einreichen. Ein Passwort brauchst du nicht – du meldest dich mit einem Code per E-Mail an.',
            ),
            const SizedBox(height: 20),
            TextFormField(
              controller: _firstName,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(
                labelText: 'Vorname',
                helperText: 'Wird nie öffentlich angezeigt.',
              ),
              validator: (v) => _required(v, 'deinen Vornamen'),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _lastName,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(
                labelText: 'Nachname',
                helperText: 'Wird nie öffentlich angezeigt.',
              ),
              validator: (v) => _required(v, 'deinen Nachnamen'),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
              decoration: const InputDecoration(
                labelText: 'E-Mail-Adresse',
                helperText: 'Für den Anmeldecode, nie öffentlich.',
              ),
              validator: (v) => (v == null || !v.contains('@'))
                  ? 'Bitte gib eine gültige E-Mail-Adresse an.'
                  : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _nickname,
              maxLength: 30,
              decoration: const InputDecoration(
                labelText: 'Spitzname',
                helperText:
                    'Unter diesem Namen erscheinen deine Tipps, Fotos, Rezensionen und Kommentare in der App.',
                helperMaxLines: 3,
              ),
              validator: (v) => (v == null || v.trim().length < 2)
                  ? 'Bitte wähle einen Spitznamen (mindestens 2 Zeichen).'
                  : null,
            ),
            const SizedBox(height: 12),
            CheckboxListTile(
              value: _acceptTerms,
              onChanged: (v) => setState(() => _acceptTerms = v ?? false),
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              title: Text.rich(
                TextSpan(
                  children: [
                    const TextSpan(text: 'Ich akzeptiere die '),
                    TextSpan(
                      text: 'Nutzungsbedingungen',
                      style: linkStyle,
                      recognizer: TapGestureRecognizer()
                        ..onTap = () => launchUrl(
                          Uri.parse(kTermsUrl),
                          mode: LaunchMode.externalApplication,
                        ),
                    ),
                    const TextSpan(text: ' und bin mindestens 16 Jahre alt.'),
                  ],
                ),
              ),
            ),
            if (_askStats)
              CheckboxListTile(
                value: _allowStats,
                onChanged: (v) => setState(() => _allowStats = v ?? false),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                title: const Text('Anonyme Statistik erlauben (freiwillig)'),
                subtitle: const Text(
                  'Wir zählen nur Summen, z. B. wie oft eine Folge gehört wird – nie, wer was hört.',
                ),
              ),
            const SizedBox(height: 4),
            Text.rich(
              TextSpan(
                children: [
                  const TextSpan(
                    text: 'Wie wir mit deinen Daten umgehen, steht in der ',
                  ),
                  TextSpan(
                    text: 'Datenschutzerklärung',
                    style: linkStyle,
                    recognizer: TapGestureRecognizer()
                      ..onTap = () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => const PrivacyScreen(),
                        ),
                      ),
                  ),
                  const TextSpan(text: '.'),
                ],
              ),
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (_error != null) ...[
              const SizedBox(height: 16),
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            const SizedBox(height: 20),
            ElevatedButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Code anfordern'),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: _busy
                  ? null
                  : () async {
                      final done = await Navigator.of(context).push<bool>(
                        MaterialPageRoute(builder: (_) => const LoginScreen()),
                      );
                      if (done == true && context.mounted) {
                        Navigator.of(context).pop(true);
                      }
                    },
              child: const Text('Ich habe schon ein Konto – anmelden'),
            ),
          ],
        ),
      ),
    );
  }
}
