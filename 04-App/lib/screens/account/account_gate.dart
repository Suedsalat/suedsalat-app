import 'package:flutter/material.dart';

import '../../services/account_service.dart';
import 'login_screen.dart';
import 'register_screen.dart';

/// Vor jedem oeffentlichen Beitrag (Tipp, Foto, Rezension, Sprachnachricht, Kommentar):
/// true, wenn es losgehen darf. Gaeste sehen den Knopf trotzdem und bekommen hier den Weg
/// zur Registrierung angeboten; gesperrte Konten einen Hinweis.
Future<bool> ensureCanContribute(BuildContext context) async {
  final account = AccountService.instance;
  if (account.canContribute) return true;

  if (account.isLoggedIn) {
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (context) => AlertDialog(
        title: const Text('Dein Konto ist für Beiträge gesperrt'),
        content: const Text(
          'Du kannst weiter alles hören, lesen und uns schreiben. Tipps, Fotos, Rezensionen und '
          'Kommentare sind aber nicht mehr möglich. Den Grund haben wir dir per E-Mail geschickt.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('OK'),
          ),
        ],
      ),
    );
    return false;
  }

  final choice = await showDialog<String>(
    context: context,
    barrierDismissible: false,
    builder: (context) => AlertDialog(
      title: const Text('Zum Mitmachen kostenlos registrieren'),
      content: const Text(
        'Tipps, Fotos, Rezensionen und Sprachnachrichten gibt es mit einem kostenlosen Hörerkonto. '
        'Deine Beiträge erscheinen dann unter deinem Spitznamen in der App.\n\n'
        'Schriftliches Feedback und Fragen kannst du uns auch als Gast schicken.',
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Später'),
        ),
        TextButton(
          onPressed: () => Navigator.of(context).pop('login'),
          child: const Text('Anmelden'),
        ),
        TextButton(
          onPressed: () => Navigator.of(context).pop('register'),
          child: const Text('Registrieren'),
        ),
      ],
    ),
  );
  if (choice == null || !context.mounted) return false;

  await Navigator.of(context).push<bool>(
    MaterialPageRoute(
      builder: (_) =>
          choice == 'login' ? const LoginScreen() : const RegisterScreen(),
    ),
  );
  return account.canContribute;
}
