import 'dart:async';
import 'dart:io';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import 'package:record/record.dart';

import '../../services/api_service.dart';

class FeedbackScreen extends StatefulWidget {
  /// Wenn ein Bildschirm ueber einen eigenen "XY-Tipp einreichen"-Button hierher
  /// verlinkt, ist die passende Kategorie schon vorausgewaehlt (z.B. 'termin_tipp').
  final String? initialType;

  const FeedbackScreen({super.key, this.initialType});

  @override
  State<FeedbackScreen> createState() => _FeedbackScreenState();
}

class _FeedbackScreenState extends State<FeedbackScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _messageController = TextEditingController();
  final _apiService = ApiService();
  final _picker = ImagePicker();

  static const _typeLabels = {
    'allgemein': 'Allgemeines Feedback',
    'termin_tipp': 'Veranstaltungstipp',
    'kino_tipp': 'Kino- und Filmtipp',
    'location_tipp': 'Locationtipp',
    'foto_vorschlag': 'Fotoempfehlung',
    'sprachnachricht': 'Sprachnachricht',
    'frage': 'Frage einreichen',
  };

  static const _messageLabels = {
    'allgemein': 'Deine Nachricht',
    'termin_tipp': 'Beschreibe deinen Veranstaltungstipp',
    'kino_tipp': 'Beschreibe deinen Kino- und Filmtipp',
    'location_tipp': 'Beschreibe deinen Locationtipp',
    'foto_vorschlag': 'Fotobeschreibung',
    'frage': 'Deine Frage',
  };

  static const _maxPhotoBytes = 8 * 1024 * 1024;
  static const _maxVideoBytes = 20 * 1024 * 1024;
  static const _maxRecordDuration = Duration(minutes: 5);

  String _type = 'allgemein';
  DateTime? _suggestedDate;
  File? _media;
  List<File> _photos = [];
  bool _submitting = false;
  String? _photoError;
  bool _showDateError = false;

  final _audioRecorder = AudioRecorder();
  final _previewPlayer = AudioPlayer();
  bool _isRecording = false;
  bool _isPreviewPlaying = false;
  File? _audioFile;
  Duration _recordDuration = Duration.zero;
  Timer? _recordTimer;
  String? _audioError;

  @override
  void initState() {
    super.initState();
    if (widget.initialType != null && _typeLabels.containsKey(widget.initialType)) {
      _type = widget.initialType!;
    }
    _apiService.trackView('feedback');
  }

  @override
  void dispose() {
    _nameController.dispose();
    _messageController.dispose();
    _recordTimer?.cancel();
    _audioRecorder.dispose();
    _previewPlayer.dispose();
    super.dispose();
  }

  Future<void> _startRecording() async {
    if (!await _audioRecorder.hasPermission()) {
      setState(() => _audioError = 'Ohne Mikrofon-Zugriff kann keine Sprachnachricht aufgenommen werden.');
      return;
    }
    final path = '${Directory.systemTemp.path}/feedback_${DateTime.now().millisecondsSinceEpoch}.m4a';
    await _audioRecorder.start(const RecordConfig(encoder: AudioEncoder.aacLc), path: path);
    _recordTimer?.cancel();
    setState(() {
      _isRecording = true;
      _audioFile = null;
      _audioError = null;
      _recordDuration = Duration.zero;
    });
    _recordTimer = Timer.periodic(const Duration(seconds: 1), (_) async {
      setState(() => _recordDuration += const Duration(seconds: 1));
      if (_recordDuration >= _maxRecordDuration) {
        await _stopRecording();
      }
    });
  }

  Future<void> _stopRecording() async {
    _recordTimer?.cancel();
    final path = await _audioRecorder.stop();
    setState(() {
      _isRecording = false;
      _audioFile = path != null ? File(path) : null;
    });
  }

  Future<void> _discardRecording() async {
    await _previewPlayer.stop();
    final file = _audioFile;
    setState(() {
      _audioFile = null;
      _isPreviewPlaying = false;
      _recordDuration = Duration.zero;
    });
    if (file != null && await file.exists()) {
      await file.delete();
    }
  }

  Future<void> _togglePreview() async {
    if (_isPreviewPlaying) {
      await _previewPlayer.stop();
      setState(() => _isPreviewPlaying = false);
      return;
    }
    final file = _audioFile;
    if (file == null) return;
    await _previewPlayer.play(DeviceFileSource(file.path));
    setState(() => _isPreviewPlaying = true);
    _previewPlayer.onPlayerComplete.first.then((_) {
      if (mounted) setState(() => _isPreviewPlaying = false);
    });
  }

  String _formatDuration(Duration d) {
    final minutes = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final seconds = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return '$minutes:$seconds';
  }

  Future<bool?> _askConsent() {
    return showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (context) => AlertDialog(
        title: const Text('Veröffentlichung im Podcast'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('Darf deine Sprachnachricht – ggf. gekürzt – im Podcast verwendet werden?'),
            const SizedBox(height: 20),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.of(context).pop(false),
                    child: const Text('Nein, bitte nicht.'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: ElevatedButton(
                    onPressed: () => Navigator.of(context).pop(true),
                    child: const Text('Ja, gerne'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _suggestedDate ?? now,
      firstDate: now.subtract(const Duration(days: 1)),
      lastDate: now.add(const Duration(days: 730)),
    );
    if (picked != null) {
      setState(() => _suggestedDate = picked);
    }
  }

  Future<void> _pickMedia() async {
    final choice = await showModalBottomSheet<({ImageSource source, bool isVideo})>(
      context: context,
      builder: (context) => SafeArea(
        child: Wrap(
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera),
              title: const Text('Foto aufnehmen'),
              onTap: () => Navigator.of(context).pop((source: ImageSource.camera, isVideo: false)),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library),
              title: const Text('Foto aus Galerie wählen'),
              onTap: () => Navigator.of(context).pop((source: ImageSource.gallery, isVideo: false)),
            ),
            ListTile(
              leading: const Icon(Icons.videocam),
              title: const Text('Video aufnehmen'),
              onTap: () => Navigator.of(context).pop((source: ImageSource.camera, isVideo: true)),
            ),
            ListTile(
              leading: const Icon(Icons.video_library),
              title: const Text('Video aus Galerie wählen'),
              onTap: () => Navigator.of(context).pop((source: ImageSource.gallery, isVideo: true)),
            ),
          ],
        ),
      ),
    );
    if (choice == null) return;

    if (choice.isVideo) {
      final picked = await _picker.pickVideo(
        source: choice.source,
        maxDuration: const Duration(seconds: 60),
      );
      if (picked == null) return;
      final file = File(picked.path);
      if (await file.length() > _maxVideoBytes) {
        setState(() => _photoError = 'Video ist zu groß (max. 20 MB).');
        return;
      }
      setState(() {
        _media = file;
        _photos = [];
        _photoError = null;
      });
      return;
    }

    // Fotos: aus der Galerie koennen mehrere auf einmal ausgewaehlt werden,
    // die Kamera liefert immer nur eines - beides wird an bereits ausgewaehlte
    // Fotos angehaengt statt sie zu ersetzen.
    final List<File> newPhotos;
    if (choice.source == ImageSource.gallery) {
      final picked = await _picker.pickMultiImage(maxWidth: 1600, imageQuality: 85);
      newPhotos = picked.map((p) => File(p.path)).toList();
    } else {
      final picked = await _picker.pickImage(source: choice.source, maxWidth: 1600, imageQuality: 85);
      newPhotos = picked != null ? [File(picked.path)] : [];
    }
    if (newPhotos.isEmpty) return;

    for (final photo in newPhotos) {
      if (await photo.length() > _maxPhotoBytes) {
        setState(() => _photoError = 'Ein Foto ist zu groß (max. 8 MB).');
        return;
      }
    }

    setState(() {
      _photos = [..._photos, ...newPhotos];
      _media = null;
      _photoError = null;
    });
  }

  void _removePhotoAt(int index) {
    setState(() => _photos = [..._photos]..removeAt(index));
  }

  Future<void> _submit() async {
    setState(() {
      _photoError = null;
      _showDateError = false;
      _audioError = null;
    });

    if (!_formKey.currentState!.validate()) return;

    if (_type == 'foto_vorschlag' && _media == null && _photos.isEmpty) {
      setState(() => _photoError = 'Bitte füge ein Foto oder Video hinzu.');
      return;
    }
    if (_type == 'termin_tipp' && _suggestedDate == null) {
      setState(() => _showDateError = true);
      return;
    }

    var consentPublish = false;
    if (_type == 'sprachnachricht') {
      if (_audioFile == null) {
        setState(() => _audioError = 'Bitte nimm zuerst eine Sprachnachricht auf.');
        return;
      }
      final consent = await _askConsent();
      if (consent == null || !mounted) return;
      consentPublish = consent;
    }

    setState(() => _submitting = true);
    try {
      await _apiService.submitFeedback(
        message: _messageController.text.trim(),
        type: _type,
        senderName: _nameController.text,
        media: _type == 'sprachnachricht' ? _audioFile : _media,
        photos: _type == 'sprachnachricht' ? null : _photos,
        suggestedDate: _type == 'termin_tipp' ? _suggestedDate : null,
        consentPublish: consentPublish,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          backgroundColor: Color(0xFF77B538),
          content: Center(
            child: Text(
              'Danke! Deine Nachricht wurde verschickt.',
              style: TextStyle(fontWeight: FontWeight.bold),
            ),
          ),
        ),
      );
      Navigator.of(context).pop();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString().replaceFirst('Exception: ', ''))),
      );
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isTerminTipp = _type == 'termin_tipp';
    final isFotoVorschlag = _type == 'foto_vorschlag';
    final isSprachnachricht = _type == 'sprachnachricht';

    return Scaffold(
      appBar: AppBar(title: const Text('Feedback')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const Text(
              'Hast du einen Veranstaltungstipp, einen Kino- und Filmtipp, einen Fotovorschlag, eine Frage oder einfach Feedback für uns? '
              'Schreib uns direkt – Jenny und Thorsten lesen jede Nachricht.',
            ),
            const SizedBox(height: 20),
            DropdownButtonFormField<String>(
              initialValue: _type,
              decoration: const InputDecoration(labelText: 'Worum geht es?'),
              items: _typeLabels.entries
                  .map((entry) => DropdownMenuItem(value: entry.key, child: Text(entry.value)))
                  .toList(),
              onChanged: (value) => setState(() {
                _type = value ?? 'allgemein';
                _photoError = null;
              }),
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _nameController,
              decoration: InputDecoration(
                labelText: isSprachnachricht ? 'Dein Name' : 'Dein Name (optional)',
              ),
              validator: isSprachnachricht
                  ? (value) => (value == null || value.trim().isEmpty) ? 'Bitte gib deinen Namen ein.' : null
                  : null,
            ),
            if (isTerminTipp) ...[
              const SizedBox(height: 16),
              InkWell(
                onTap: _pickDate,
                child: InputDecorator(
                  decoration: const InputDecoration(labelText: 'Datum der Veranstaltung'),
                  child: Text(
                    _suggestedDate != null
                        ? DateFormat('dd.MM.yyyy').format(_suggestedDate!)
                        : 'Datum auswählen',
                  ),
                ),
              ),
              if (_showDateError)
                Padding(
                  padding: const EdgeInsets.only(top: 6, left: 12),
                  child: Text(
                    'Bitte ein Datum auswählen.',
                    style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12),
                  ),
                ),
            ],
            if (!isSprachnachricht) ...[
              const SizedBox(height: 16),
              TextFormField(
                controller: _messageController,
                decoration: InputDecoration(
                  labelText: _messageLabels[_type],
                  alignLabelWithHint: true,
                ),
                maxLines: 5,
                maxLength: 2000,
                validator: (value) =>
                    (value == null || value.trim().isEmpty) ? 'Bitte gib eine Nachricht ein.' : null,
              ),
            ],
            if (isSprachnachricht) ...[
              const SizedBox(height: 8),
              if (_isRecording)
                OutlinedButton.icon(
                  onPressed: _stopRecording,
                  icon: const Icon(Icons.stop_circle, color: Colors.red),
                  label: Text('Aufnahme stoppen (${_formatDuration(_recordDuration)})'),
                )
              else if (_audioFile != null)
                Row(
                  children: [
                    IconButton(
                      onPressed: _togglePreview,
                      icon: Icon(_isPreviewPlaying ? Icons.pause_circle : Icons.play_circle),
                      iconSize: 36,
                    ),
                    Text(_formatDuration(_recordDuration)),
                    const Spacer(),
                    TextButton.icon(
                      onPressed: _discardRecording,
                      icon: const Icon(Icons.refresh),
                      label: const Text('Neu aufnehmen'),
                    ),
                  ],
                )
              else
                OutlinedButton.icon(
                  onPressed: _startRecording,
                  icon: const Icon(Icons.mic),
                  label: const Text('Sprachnachricht aufnehmen (Pflicht)'),
                ),
              if (_audioError != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6, left: 12),
                  child: Text(
                    _audioError!,
                    style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12),
                  ),
                ),
              const SizedBox(height: 12),
              Text(
                'Beim Absenden fragen wir dich noch, ob deine Sprachnachricht im Podcast verwendet werden darf.',
                style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant, fontSize: 12),
              ),
            ],
            if (!isSprachnachricht && _media != null)
              Stack(
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: Container(
                      height: 160,
                      width: double.infinity,
                      color: Colors.black87,
                      child: const Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.videocam, color: Colors.white, size: 40),
                          SizedBox(height: 8),
                          Text('Video ausgewählt', style: TextStyle(color: Colors.white)),
                        ],
                      ),
                    ),
                  ),
                  Positioned(
                    top: 4,
                    right: 4,
                    child: IconButton(
                      icon: const Icon(Icons.cancel, color: Colors.white),
                      style: IconButton.styleFrom(backgroundColor: Colors.black45),
                      onPressed: () => setState(() => _media = null),
                    ),
                  ),
                ],
              )
            else if (!isSprachnachricht && _photos.isNotEmpty) ...[
              SizedBox(
                height: 100,
                child: ListView.builder(
                  scrollDirection: Axis.horizontal,
                  itemCount: _photos.length,
                  itemBuilder: (context, index) => Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: Stack(
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(10),
                          child: Image.file(_photos[index], width: 100, height: 100, fit: BoxFit.cover),
                        ),
                        Positioned(
                          top: 2,
                          right: 2,
                          child: IconButton(
                            icon: const Icon(Icons.cancel, color: Colors.white),
                            style: IconButton.styleFrom(backgroundColor: Colors.black45),
                            onPressed: () => _removePhotoAt(index),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 8),
              TextButton.icon(
                onPressed: _pickMedia,
                icon: const Icon(Icons.add_a_photo),
                label: const Text('Weiteres Foto hinzufügen'),
              ),
            ] else if (!isSprachnachricht)
              OutlinedButton.icon(
                onPressed: _pickMedia,
                icon: const Icon(Icons.add_a_photo),
                label: Text(
                  isFotoVorschlag ? 'Foto/Video hinzufügen (Pflicht)' : 'Foto/Video hinzufügen (optional)',
                ),
              ),
            if (!isSprachnachricht && _photoError != null)
              Padding(
                padding: const EdgeInsets.only(top: 6, left: 12),
                child: Text(
                  _photoError!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12),
                ),
              ),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: _submitting ? null : _submit,
              child: _submitting
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Text('Absenden'),
            ),
          ],
        ),
      ),
    );
  }
}
