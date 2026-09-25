import 'package:flutter/material.dart';

import '../../services/account_service.dart';
import 'code_screen.dart';

/// Konto loeschen (App 2.0, Konzept Abschnitt 10): Standard 30 Tage Rueckkehrfrist, wahlweise
/// sofort endgueltig (mit Code). Eigene Texte und eigene Fotos koennen mitgeloescht werden -
/// Standard ist: nur das Konto, die Beitraege bleiben als „Ehemaliges Mitglied".
class DeleteAccountScreen extends StatefulWidget {
  const DeleteAccountScreen({super.key});

  @override
  State<DeleteAccountScreen> createState() => _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends State<DeleteAccountScreen> {
  bool _deleteTexts = false;
  bool _deletePhotos = false;
  bool _immediate = false;
  bool _busy = false;
  String? _error;

  Future<void> _submit() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(
          _immediate ? 'Konto sofort endgültig löschen?' : 'Konto löschen?',
        ),
        content: Text(
          _immediate
              ? 'Das lässt sich nicht rückgängig machen. Wir schicken dir zur Sicherheit einen Code per E-Mail.'
              : 'Dein Konto wird sofort stillgelegt und nach 30 Tagen endgültig gelöscht. '
                    'Meldest du dich vorher wieder an, ist alles wieder da.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Abbrechen'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(
              'Löschen',
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;

    setState(() {
      _busy = true;
      _error = null;
    });
    final account = AccountService.instance;
    try {
      if (_immediate) {
        Future<String> request() => account.requestImmediateDeletion(
          deleteTexts: _deleteTexts,
          deletePhotos: _deletePhotos,
        );
        final masked = await request();
        if (!mounted) return;
        final done = await Navigator.of(context).push<bool>(
          MaterialPageRoute(
            builder: (_) => CodeScreen(
              title: 'Löschung bestätigen',
              maskedEmail: masked,
              submitLabel: 'Endgültig löschen',
              onSubmit: account.confirmImmediateDeletion,
              onResend: request,
            ),
          ),
        );
        if (done != true) return;
      } else {
        await account.deleteWithGracePeriod(
          deleteTexts: _deleteTexts,
          deletePhotos: _deletePhotos,
        );
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _immediate
                ? 'Dein Konto ist gelöscht. Die Bestätigung haben wir dir per E-Mail geschickt.'
                : 'Dein Konto ist stillgelegt. Alle Einzelheiten stehen in der E-Mail, die wir dir geschickt haben.',
          ),
        ),
      );
      Navigator.of(context).popUntil((route) => route.isFirst);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Scaffold(
      appBar: AppBar(title: const Text('Konto löschen')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          const Text(
            'Gelöscht werden immer dein Konto, dein Vor- und Nachname, deine E-Mail-Adresse und dein Spitzname.',
          ),
          const SizedBox(height: 12),
          const Text(
            'Deine Beiträge bleiben normalerweise stehen – ohne Namen, als „Ehemaliges Mitglied“. '
            'Wenn du möchtest, löschen wir sie mit:',
          ),
          CheckboxListTile(
            value: _deleteTexts,
            onChanged: (v) => setState(() => _deleteTexts = v ?? false),
            controlAffinity: ListTileControlAffinity.leading,
            contentPadding: EdgeInsets.zero,
            title: const Text('Meine Texte löschen'),
            subtitle: const Text('Rezensionen und Kommentare'),
          ),
          CheckboxListTile(
            value: _deletePhotos,
            onChanged: (v) => setState(() => _deletePhotos = v ?? false),
            controlAffinity: ListTileControlAffinity.leading,
            contentPadding: EdgeInsets.zero,
            title: const Text('Meine Fotos löschen'),
            subtitle: const Text(
              'Eigene Fotos und Videos in der Galerie und bei Tipps',
            ),
          ),
          Text(
            'Tipps, die wir aus deinen Vorschlägen übernommen haben, und deine Nachrichten an uns bleiben '
            'immer erhalten – ebenfalls ohne deinen Namen.',
            style: text.bodySmall,
          ),
          const SizedBox(height: 20),
          Text('Wann?', style: text.titleMedium),
          RadioGroup<bool>(
            groupValue: _immediate,
            onChanged: (v) => setState(() => _immediate = v ?? false),
            child: const Column(
              children: [
                RadioListTile<bool>(
                  value: false,
                  contentPadding: EdgeInsets.zero,
                  title: Text('Mit 30 Tagen Rückkehrfrist'),
                  subtitle: Text(
                    'Du kannst es dir bis dahin anders überlegen – einfach wieder anmelden.',
                  ),
                ),
                RadioListTile<bool>(
                  value: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text('Sofort und endgültig'),
                  subtitle: Text(
                    'Nicht rückgängig zu machen. Du bestätigst mit einem Code per E-Mail.',
                  ),
                ),
              ],
            ),
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
            style: ElevatedButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
              foregroundColor: Theme.of(context).colorScheme.onError,
            ),
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Konto löschen'),
          ),
        ],
      ),
    );
  }
}
