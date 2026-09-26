import 'package:flutter/material.dart';

import '../../services/account_service.dart';
import 'code_screen.dart';

/// Anmeldung ohne Passwort: E-Mail-Adresse -> Code per Mail -> angemeldet.
/// Schliesst sich mit `true`, wenn die Anmeldung geklappt hat.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final email = _email.text.trim();
    if (!email.contains('@')) {
      setState(() => _error = 'Bitte gib deine E-Mail-Adresse an.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final account = AccountService.instance;
    try {
      final masked = await account.requestLoginCode(email);
      if (!mounted) return;
      var restored = false;
      final done = await Navigator.of(context).push<bool>(
        MaterialPageRoute(
          builder: (_) => CodeScreen(
            title: 'Anmelden',
            maskedEmail: masked,
            intro:
                'Falls es zu dieser Adresse ein Konto gibt, ist der Code unterwegs.',
            submitLabel: 'Anmelden',
            onSubmit: (code) async =>
                restored = await account.verify(email, code),
            onResend: () => account.requestLoginCode(email),
          ),
        ),
      );
      if (done == true && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              restored
                  ? 'Willkommen zurück, ${account.listener?.nickname ?? ''}! Dein Konto ist wiederhergestellt.'
                  : 'Hallo ${account.listener?.nickname ?? ''}, du bist angemeldet.',
            ),
          ),
        );
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Anmelden')),
      body: ListView(
        padding: EdgeInsets.fromLTRB(24, 24, 24, 24 + MediaQuery.viewPaddingOf(context).bottom),
        children: [
          const Text(
            'Gib die E-Mail-Adresse deines Hörerkontos ein. Wir schicken dir einen Code – ein Passwort gibt es nicht.',
          ),
          const SizedBox(height: 20),
          TextField(
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            autocorrect: false,
            autofocus: true,
            decoration: const InputDecoration(labelText: 'E-Mail-Adresse'),
            onSubmitted: (_) => _submit(),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
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
          const SizedBox(height: 16),
          Text(
            'Du hattest dein Konto gelöscht? Innerhalb von 30 Tagen holst du es mit einer Anmeldung zurück – '
            'mit Spitznamen und allen Beiträgen.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}
