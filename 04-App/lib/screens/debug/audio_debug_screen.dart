import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/audio_debug_log.dart';

/// Temporaerer Diagnose-Bildschirm fuer die Fehlersuche bei Android Auto/CarPlay -
/// zeigt die zuletzt aufgezeichneten Aufrufe der Medien-Session (siehe
/// SuedsalatAudioHandler) an, ohne dass PC/USB-Debugging noetig ist. Nach
/// Abschluss der Fehlersuche wieder entfernen.
class AudioDebugScreen extends StatefulWidget {
  const AudioDebugScreen({super.key});

  @override
  State<AudioDebugScreen> createState() => _AudioDebugScreenState();
}

class _AudioDebugScreenState extends State<AudioDebugScreen> {
  List<String> _entries = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final entries = await AudioDebugLog.read();
    if (mounted) setState(() => _entries = entries.reversed.toList());
  }

  @override
  Widget build(BuildContext context) {
    final logText = _entries.join('\n');
    return Scaffold(
      appBar: AppBar(
        title: const Text('Auto-Diagnose'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _load,
            tooltip: 'Aktualisieren',
          ),
          IconButton(
            icon: const Icon(Icons.copy),
            tooltip: 'Kopieren',
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: logText));
              if (context.mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Log in die Zwischenablage kopiert.')),
                );
              }
            },
          ),
          IconButton(
            icon: const Icon(Icons.delete_outline),
            tooltip: 'Leeren',
            onPressed: () async {
              await AudioDebugLog.clear();
              await _load();
            },
          ),
        ],
      ),
      body: Padding(
        padding: const EdgeInsets.all(12),
        child: _entries.isEmpty
            ? const Center(child: Text('Noch keine Einträge. Erst eine Folge über Android Auto/CarPlay antippen, dann hier zurückkommen.'))
            : SingleChildScrollView(
                child: SelectableText(
                  logText,
                  style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
                ),
              ),
      ),
    );
  }
}
