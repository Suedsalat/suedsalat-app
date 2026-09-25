import 'package:flutter/material.dart';

import '../../services/stats_consent_service.dart';
import '../settings/privacy_screen.dart';

/// Frage nach der anonymen Statistik (§ 25 TDDDG). Beide Antworten sind gleich gross und gleich
/// gestaltet - Ablehnen darf nicht schwerer sein als Zustimmen. Der Text gehoert zur Fassung
/// StatsConsentService.textVersion: bei inhaltlicher Aenderung dort (und im Backend) erhoehen.
Future<void> showStatsConsentDialog(BuildContext context) async {
  final granted = await showDialog<bool>(
    context: context,
    barrierDismissible: false,
    builder: (context) => PopScope(
      canPop: false,
      child: AlertDialog(
        title: const Text('Hilfst du uns mit einer anonymen Statistik?'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Wir würden gern zählen, welche Folgen gehört und welche Bereiche der App geöffnet '
                'werden – zum Beispiel, wie viele eine Folge bis zum Ende hören. Dafür schickt die App '
                'dabei jeweils eine kurze Meldung an unseren eigenen Server.\n\n'
                'Wir sehen nur Summen, nie, wer was gehört hat. Nichts davon geht an Google, Apple '
                'oder andere.\n\n'
                'Du kannst deine Entscheidung jederzeit in den Einstellungen ändern.',
              ),
              TextButton(
                style: TextButton.styleFrom(padding: EdgeInsets.zero),
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const PrivacyScreen()),
                ),
                child: const Text('Datenschutzerklärung'),
              ),
            ],
          ),
        ),
        actionsAlignment: MainAxisAlignment.spaceEvenly,
        actions: [
          Row(
            children: [
              Expanded(
                child: ElevatedButton(
                  onPressed: () => Navigator.of(context).pop(false),
                  child: const Text('Nein, danke'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: ElevatedButton(
                  onPressed: () => Navigator.of(context).pop(true),
                  child: const Text('Ja, gern'),
                ),
              ),
            ],
          ),
        ],
      ),
    ),
  );
  if (granted != null) {
    await StatsConsentService.instance.decide(granted: granted);
  }
}
