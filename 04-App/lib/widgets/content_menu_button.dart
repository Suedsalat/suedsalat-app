import 'package:flutter/material.dart';

import '../services/account_service.dart';

/// Gruende fuer eine Meldung - muessen zu Moderation::CATEGORIES im Backend passen.
const kReportCategories = {
  'insult': 'Beleidigung oder Hass',
  'spam': 'Spam oder Werbung',
  'image': 'Unangemessenes Bild',
  'rights': 'Verletzt meine Rechte',
  'other': 'Sonstiges',
};

/// Menue „⋮" an einem Beitrag (App 2.0): „Melden" fuer alle, auch Gaeste; „Nutzer ausblenden"
/// fuer Angemeldete bei Rezensionen, Fotos und Kommentaren. Bei eigenen Beitraegen gibt es kein
/// Menue. [contentType] wie im Backend: review, photo, movie_tip, location_tip, event.
class ContentMenuButton extends StatelessWidget {
  const ContentMenuButton({
    super.key,
    required this.contentType,
    required this.contentId,
    this.isOwn = false,
    this.allowHideAuthor = false,
    this.onHidden,
    this.color,
  });

  final String contentType;
  final int contentId;
  final bool isOwn;
  final bool allowHideAuthor;

  /// Nach „Nutzer ausblenden" - z. B. Liste neu laden.
  final VoidCallback? onHidden;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    if (isOwn) return const SizedBox.shrink();
    final canHide = allowHideAuthor && AccountService.instance.isLoggedIn;
    return PopupMenuButton<String>(
      icon: Icon(Icons.more_vert, color: color),
      tooltip: 'Mehr',
      onSelected: (value) {
        if (value == 'report') showReportSheet(context, contentType, contentId);
        if (value == 'hide') _hideAuthor(context);
      },
      itemBuilder: (context) => [
        const PopupMenuItem(
          value: 'report',
          child: ListTile(leading: Icon(Icons.flag_outlined), title: Text('Melden'), contentPadding: EdgeInsets.zero),
        ),
        if (canHide)
          const PopupMenuItem(
            value: 'hide',
            child: ListTile(
              leading: Icon(Icons.visibility_off_outlined),
              title: Text('Nutzer ausblenden'),
              contentPadding: EdgeInsets.zero,
            ),
          ),
      ],
    );
  }

  Future<void> _hideAuthor(BuildContext context) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Nutzer ausblenden?'),
        content: const Text(
          'Du siehst dann keine Beiträge dieser Person mehr. Die anderen sehen sie weiterhin, und die '
          'Person erfährt nichts davon. Rückgängig machen kannst du das unter Einstellungen › Mein Konto.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Abbrechen')),
          TextButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Ausblenden')),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    try {
      await AccountService.instance.hideAuthor(contentType, contentId);
      messenger.showSnackBar(const SnackBar(content: Text('Ausgeblendet.')));
      onHidden?.call();
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.toString())));
    }
  }
}

/// Meldeformular: Grund waehlen, optional kurz erklaeren, absenden.
Future<void> showReportSheet(BuildContext context, String contentType, int contentId) async {
  final messenger = ScaffoldMessenger.of(context);
  final result = await showModalBottomSheet<String>(
    context: context,
    isScrollControlled: true,
    builder: (context) => _ReportSheet(contentType: contentType, contentId: contentId),
  );
  if (result != null) messenger.showSnackBar(SnackBar(content: Text(result)));
}

class _ReportSheet extends StatefulWidget {
  const _ReportSheet({required this.contentType, required this.contentId});

  final String contentType;
  final int contentId;

  @override
  State<_ReportSheet> createState() => _ReportSheetState();
}

class _ReportSheetState extends State<_ReportSheet> {
  final _text = TextEditingController();
  String? _category;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (_category == null) {
      setState(() => _error = 'Bitte wähle einen Grund.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final already = await AccountService.instance.report(widget.contentType, widget.contentId, _category!, _text.text);
      if (mounted) {
        Navigator.of(context).pop(already
            ? 'Das hattest du schon gemeldet – wir schauen es uns an.'
            : 'Danke! Wir schauen uns den Beitrag an.');
      }
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Beitrag melden', style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 4),
              const Text('Was stimmt mit dem Beitrag nicht? Jenny und Thorsten bekommen deine Meldung.'),
              RadioGroup<String>(
                groupValue: _category,
                onChanged: (v) => setState(() {
                  _category = v;
                  _error = null;
                }),
                child: Column(
                  children: [
                    for (final entry in kReportCategories.entries)
                      RadioListTile<String>(value: entry.key, title: Text(entry.value), contentPadding: EdgeInsets.zero),
                  ],
                ),
              ),
              TextField(
                controller: _text,
                maxLength: 1000,
                maxLines: 3,
                decoration: const InputDecoration(labelText: 'Kurz erklären (optional)', alignLabelWithHint: true),
              ),
              if (_error != null)
                Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              const SizedBox(height: 8),
              ElevatedButton(
                onPressed: _busy ? null : _send,
                child: _busy
                    ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Meldung senden'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
