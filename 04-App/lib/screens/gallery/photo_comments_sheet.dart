import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../models/photo_comment.dart';
import '../../services/account_service.dart';
import '../../services/api_service.dart';
import '../../widgets/content_menu_button.dart';
import '../../widgets/tip_submitter_line.dart';
import '../account/account_gate.dart';

/// Kommentare unter einem Galerie-Foto (App 2.0) als Fenster von unten. Lesen darf jeder,
/// schreiben nur angemeldete Hoerer. Liefert die neue Anzahl, damit die Foto-Ansicht die Zahl
/// an der Sprechblase nachziehen kann.
Future<int?> showPhotoComments(
  BuildContext context,
  int photoId, {
  ApiService? api,
}) {
  return showModalBottomSheet<int>(
    context: context,
    isDismissible: false,
    enableDrag: false,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (_) =>
        PhotoCommentsSheet(photoId: photoId, api: api ?? ApiService()),
  );
}

class PhotoCommentsSheet extends StatefulWidget {
  const PhotoCommentsSheet({
    super.key,
    required this.photoId,
    required this.api,
  });

  final int photoId;
  final ApiService api;

  @override
  State<PhotoCommentsSheet> createState() => _PhotoCommentsSheetState();
}

class _PhotoCommentsSheetState extends State<PhotoCommentsSheet> {
  final _text = TextEditingController();
  List<PhotoComment>? _kommentare;
  bool _laden = true;
  bool _senden = false;
  String? _fehler;

  @override
  void initState() {
    super.initState();
    _neuLaden();
  }

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  Future<void> _neuLaden() async {
    setState(() {
      _laden = true;
      _fehler = null;
    });
    try {
      final k = await widget.api.fetchPhotoComments(widget.photoId);
      if (mounted) setState(() => _kommentare = k);
    } catch (e) {
      if (mounted) {
        setState(
          () => _fehler = 'Die Kommentare konnten nicht geladen werden.',
        );
      }
    } finally {
      if (mounted) setState(() => _laden = false);
    }
  }

  Future<void> _absenden() async {
    final text = _text.text.trim();
    if (text.isEmpty) return;
    if (!await ensureCanContribute(context) || !mounted) return;
    setState(() {
      _senden = true;
      _fehler = null;
    });
    try {
      final k = await widget.api.postPhotoComment(widget.photoId, text);
      if (!mounted) return;
      _text.clear();
      FocusScope.of(context).unfocus();
      setState(() => _kommentare = k);
    } catch (e) {
      if (mounted) setState(() => _fehler = e.toString());
    } finally {
      if (mounted) setState(() => _senden = false);
    }
  }

  Future<void> _loeschen(PhotoComment k) async {
    final ok = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (context) => AlertDialog(
        title: const Text('Kommentar löschen?'),
        content: const Text('Dein Kommentar verschwindet sofort für alle.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Abbrechen'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Löschen'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await widget.api.deletePhotoComment(k.id);
      await _neuLaden();
    } catch (e) {
      if (mounted) setState(() => _fehler = e.toString());
    }
  }

  Widget _eintrag(PhotoComment k) {
    final klein = Theme.of(context).textTheme.bodySmall;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 4, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Oben klein, wer geschrieben hat - wie bei Rezensionen
          Row(
            children: [
              Expanded(
                child: TipSubmitterLine(name: k.authorName, prefix: ''),
              ),
              Text(
                DateFormat('dd.MM.yyyy HH:mm').format(k.createdAt),
                style: klein,
              ),
              if (k.isOwn)
                IconButton(
                  icon: const Icon(Icons.delete_outline, size: 20),
                  tooltip: 'Kommentar löschen',
                  onPressed: () => _loeschen(k),
                )
              else
                ContentMenuButton(
                  contentType: 'comment',
                  contentId: k.id,
                  allowHideAuthor: true,
                  onHidden: _neuLaden,
                ),
            ],
          ),
          Text(k.text),
        ],
      ),
    );
  }

  Widget _eingabe() {
    final account = AccountService.instance;
    // Unten Platz fuer die Navigationsleiste des Handys (App laeuft randlos) bzw. die Tastatur -
    // sonst liegt die Leiste ueber dem Hinweis oder dem Eingabefeld.
    final unten = MediaQuery.viewInsetsOf(context).bottom > MediaQuery.viewPaddingOf(context).bottom
        ? MediaQuery.viewInsetsOf(context).bottom
        : MediaQuery.viewPaddingOf(context).bottom;
    if (!account.canContribute) {
      return Padding(
        padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + unten),
        child: account.isLoggedIn
            ? const Text(
                'Dein Konto ist für Beiträge gesperrt – Kommentare sind deshalb nicht möglich.',
              )
            : Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Kommentieren geht mit einem kostenlosen Hörerkonto.',
                    ),
                  ),
                  TextButton(
                    onPressed: () async {
                      if (await ensureCanContribute(context) && mounted) {
                        setState(() {});
                      }
                    },
                    child: const Text('Mitmachen'),
                  ),
                ],
              ),
      );
    }
    return Padding(
      padding: EdgeInsets.fromLTRB(
        16,
        8,
        8,
        8 + unten,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: TextField(
              controller: _text,
              minLines: 1,
              maxLines: 4,
              maxLength: 1000,
              textCapitalization: TextCapitalization.sentences,
              decoration: InputDecoration(
                hintText: 'Kommentar als ${account.listener!.nickname} …',
                counterText: '',
              ),
            ),
          ),
          IconButton(
            icon: _senden
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.send),
            tooltip: 'Senden',
            onPressed: _senden ? null : _absenden,
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final k = _kommentare ?? const <PhotoComment>[];
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Navigator.of(context).pop(k.length);
      },
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.7,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 4, 0),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      'Kommentare',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close),
                    tooltip: 'Schließen',
                    onPressed: () => Navigator.of(context).pop(k.length),
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: _laden && _kommentare == null
                  ? const Center(child: CircularProgressIndicator())
                  : k.isEmpty
                  ? const Center(
                      child: Text(
                        'Noch keine Kommentare – schreib den ersten!',
                      ),
                    )
                  : ListView.separated(
                      itemCount: k.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (_, i) => _eintrag(k[i]),
                    ),
            ),
            if (_fehler != null)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Text(
                  _fehler!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            const Divider(height: 1),
            _eingabe(),
          ],
        ),
      ),
    );
  }
}
