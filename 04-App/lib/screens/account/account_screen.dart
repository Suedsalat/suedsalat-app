import 'package:flutter/material.dart';

import '../../services/account_service.dart';
import 'delete_account_screen.dart';
import 'hidden_users_screen.dart';

/// Mein Hoererkonto: Spitzname aendern, ausgeblendete Nutzer, abmelden, Konto loeschen.
class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  Future<void> _rename(BuildContext context, String current) async {
    final controller = TextEditingController(text: current);
    String? error;
    var busy = false;
    await showDialog<void>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setState) => AlertDialog(
          title: const Text('Spitzname ändern'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: controller,
                maxLength: 30,
                autofocus: true,
                decoration: InputDecoration(
                  labelText: 'Spitzname',
                  errorText: error,
                  errorMaxLines: 3,
                ),
              ),
              const Text(
                'Der neue Name erscheint sofort bei allen deinen Beiträgen.',
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: busy ? null : () => Navigator.of(context).pop(),
              child: const Text('Abbrechen'),
            ),
            TextButton(
              onPressed: busy
                  ? null
                  : () async {
                      setState(() {
                        busy = true;
                        error = null;
                      });
                      try {
                        await AccountService.instance.updateNickname(
                          controller.text,
                        );
                        if (context.mounted) Navigator.of(context).pop();
                      } catch (e) {
                        setState(() => error = e.toString());
                      } finally {
                        if (context.mounted) setState(() => busy = false);
                      }
                    },
              child: const Text('Speichern'),
            ),
          ],
        ),
      ),
    );
    controller.dispose();
  }

  Future<void> _logout(BuildContext context) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Abmelden?'),
        content: const Text(
          'Du bist dann wieder als Gast unterwegs. Dein Konto und deine Beiträge bleiben erhalten.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Abbrechen'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Abmelden'),
          ),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await AccountService.instance.logout();
    } catch (_) {
      // Lokal ist man trotzdem abgemeldet (siehe AccountService.logout).
    }
    if (context.mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Du bist abgemeldet.')));
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: AccountService.instance,
      builder: (context, _) {
        final listener = AccountService.instance.listener;
        return Scaffold(
          appBar: AppBar(title: const Text('Mein Konto')),
          body: listener == null
              ? const Center(child: Text('Du bist nicht angemeldet.'))
              : ListView(
                  children: [
                    ListTile(
                      leading: const Icon(Icons.person_outline),
                      title: Text(listener.nickname),
                      subtitle: const Text(
                        'Dein Spitzname – so erscheinst du in der App',
                      ),
                      trailing: const Icon(Icons.edit_outlined),
                      onTap: () => _rename(context, listener.nickname),
                    ),
                    ListTile(
                      leading: const Icon(Icons.badge_outlined),
                      title: Text('${listener.firstName} ${listener.lastName}'),
                      subtitle: const Text('Nie öffentlich sichtbar'),
                    ),
                    ListTile(
                      leading: const Icon(Icons.mail_outline),
                      title: Text(listener.email),
                      subtitle: const Text('Für den Anmeldecode'),
                    ),
                    if (listener.blocked)
                      ListTile(
                        leading: Icon(
                          Icons.block,
                          color: Theme.of(context).colorScheme.error,
                        ),
                        title: const Text('Für Beiträge gesperrt'),
                        subtitle: const Text(
                          'Den Grund haben wir dir per E-Mail geschickt. Fragen? info@südsalat.eu',
                        ),
                      ),
                    const Divider(),
                    ListTile(
                      leading: const Icon(Icons.visibility_off_outlined),
                      title: const Text('Ausgeblendete Nutzer'),
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => const HiddenUsersScreen(),
                        ),
                      ),
                    ),
                    ListTile(
                      leading: const Icon(Icons.logout),
                      title: const Text('Abmelden'),
                      onTap: () => _logout(context),
                    ),
                    ListTile(
                      leading: Icon(
                        Icons.delete_outline,
                        color: Theme.of(context).colorScheme.error,
                      ),
                      title: Text(
                        'Konto löschen',
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => const DeleteAccountScreen(),
                        ),
                      ),
                    ),
                  ],
                ),
        );
      },
    );
  }
}
