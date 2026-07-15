import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

class NewsletterScreen extends StatefulWidget {
  const NewsletterScreen({super.key});

  @override
  State<NewsletterScreen> createState() => _NewsletterScreenState();
}

class _NewsletterScreenState extends State<NewsletterScreen> {
  final _emailController = TextEditingController();
  bool _submitting = false;

  @override
  void dispose() {
    _emailController.dispose();
    super.dispose();
  }

  Future<void> _subscribe() async {
    final email = _emailController.text.trim();
    if (email.isEmpty || !email.contains('@')) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Bitte gib eine gültige E-Mail-Adresse ein.')),
      );
      return;
    }

    setState(() => _submitting = true);
    try {
      final response = await http.post(
        Uri.parse('https://www.xn--sdsalat-n2a.eu/newsletter/newsletter.php'),
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: {'email': email},
      );
      if (response.statusCode != 200) {
        throw Exception('Fehler beim Anmelden (${response.statusCode})');
      }
      if (!mounted) return;
      _emailController.clear();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Fast geschafft! Bitte bestätige die Anmeldung über den Link in der E-Mail.'),
        ),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Anmeldung fehlgeschlagen. Bitte später erneut versuchen.')),
      );
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Newsletter')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text('Newsletter abonnieren', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 8),
          const Text('Werde direkt informiert, wenn es neue Folgen gibt!'),
          const SizedBox(height: 20),
          TextField(
            controller: _emailController,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(labelText: 'Deine E-Mail'),
          ),
          const SizedBox(height: 8),
          const Text(
            'Mit der Anmeldung stimmst du dem Empfang des Newsletters zu und erklärst dich mit der '
            'Datenschutzerklärung einverstanden. Die Anmeldung erfolgt über Double-Opt-In.',
            style: TextStyle(fontSize: 12),
          ),
          const SizedBox(height: 16),
          ElevatedButton(
            onPressed: _submitting ? null : _subscribe,
            child: _submitting
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Text('Anmelden'),
          ),
        ],
      ),
    );
  }
}
