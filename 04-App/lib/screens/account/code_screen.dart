import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Eingabe des sechsstelligen Codes aus der E-Mail - fuer Registrierung, Anmeldung und
/// sofortige Kontoloeschung. Schliesst sich mit `true`, sobald [onSubmit] ohne Fehler durchlaeuft.
class CodeScreen extends StatefulWidget {
  const CodeScreen({
    super.key,
    required this.title,
    required this.maskedEmail,
    required this.onSubmit,
    this.onResend,
    this.intro,
    this.submitLabel = 'Bestätigen',
  });

  final String title;
  final String maskedEmail;
  final Future<void> Function(String code) onSubmit;

  /// Neuen Code anfordern; liefert die (maskierte) Adresse.
  final Future<String> Function()? onResend;
  final String? intro;
  final String submitLabel;

  @override
  State<CodeScreen> createState() => _CodeScreenState();
}

class _CodeScreenState extends State<CodeScreen> {
  final _controller = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final code = _controller.text.trim();
    if (code.length != 6) {
      setState(() => _error = 'Bitte gib den sechsstelligen Code ein.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.onSubmit(code);
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final email = await widget.onResend!();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Neuer Code ist unterwegs an $email.')),
        );
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
      appBar: AppBar(title: Text(widget.title)),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          if (widget.intro != null) ...[
            Text(widget.intro!),
            const SizedBox(height: 12),
          ],
          Text(
            'Wir haben dir einen Code an ${widget.maskedEmail} geschickt. Er ist 15 Minuten gültig.',
          ),
          const SizedBox(height: 20),
          TextField(
            controller: _controller,
            keyboardType: TextInputType.number,
            autofocus: true,
            maxLength: 6,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 28, letterSpacing: 8),
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            decoration: const InputDecoration(
              labelText: 'Code',
              counterText: '',
            ),
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
                : Text(widget.submitLabel),
          ),
          const SizedBox(height: 24),
          Text(
            'Keine E-Mail bekommen? Schau bitte auch im Spam-Ordner nach.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          if (widget.onResend != null)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: _busy ? null : _resend,
                child: const Text('Neuen Code anfordern'),
              ),
            ),
        ],
      ),
    );
  }
}
