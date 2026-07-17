import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_html/flutter_html.dart';
import 'package:html/parser.dart' as html_parser;
import 'package:http/http.dart' as http;
import 'package:url_launcher/url_launcher.dart';

import '../../widgets/async_state_views.dart';

/// Zeigt die Datenschutzerklärung direkt in der App an (kein Verlassen zum
/// Browser). Der Inhalt wird live von der Homepage-Seite geladen und daraus
/// extrahiert, statt eine eigene Kopie zu pflegen - so bleibt der Text immer
/// automatisch mit der Homepage synchron, ohne dass hier je etwas manuell
/// nachgezogen werden muss.
///
/// Bewusst kein WebView: die Homepage-Seite laedt im Footer serverweit ein
/// Cookie-Banner samt Google Analytics (siehe datenschutz.html Abschnitt 6).
/// Ein WebView wuerde dieses Skript mit ausfuehren und wuerde damit genau der
/// Zusage in Abschnitt 2d/6 widersprechen, dass die App selbst kein Tracking
/// enthaelt. Stattdessen wird nur das reine HTML aus dem <main>-Bereich
/// geladen und ohne Skriptausfuehrung nativ gerendert.
class PrivacyScreen extends StatefulWidget {
  const PrivacyScreen({super.key});

  @override
  State<PrivacyScreen> createState() => _PrivacyScreenState();
}

class _PrivacyScreenState extends State<PrivacyScreen> {
  static const _url = 'https://www.xn--sdsalat-n2a.eu/seiten/datenschutz.html';

  late Future<String> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<String> _load() async {
    final response = await http.get(Uri.parse(_url));
    if (response.statusCode != 200) {
      throw Exception('Datenschutzerklärung konnte nicht geladen werden (${response.statusCode})');
    }

    // Die Seite deklariert UTF-8 nur per <meta charset>, nicht im HTTP-Header -
    // response.body wuerde sonst faelschlich Latin-1 annehmen und Umlaute zerstoeren.
    final document = html_parser.parse(utf8.decode(response.bodyBytes));
    final main = document.querySelector('main');
    if (main == null || main.innerHtml.trim().isEmpty) {
      throw Exception('Datenschutzerklärung hat ein unerwartetes Format.');
    }

    // "Zurück zur Hauptseite"-Link entfernen - das ist Homepage-Navigation,
    // die innerhalb der App keinen Sinn ergibt.
    for (final el in main.querySelectorAll('.back-btn')) {
      el.remove();
    }

    return main.innerHtml;
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _openLink(String url) async {
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Datenschutzerklärung')),
      body: FutureBuilder<String>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const LoadingStateView();
          }
          if (snapshot.hasError || !snapshot.hasData) {
            return ErrorStateView(onRetry: _reload);
          }
          return SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Html(
              data: snapshot.data!,
              onLinkTap: (url, attributes, element) {
                if (url != null) _openLink(url);
              },
            ),
          );
        },
      ),
    );
  }
}
