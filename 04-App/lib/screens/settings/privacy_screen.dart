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
///
/// Dieselbe Ansicht zeigt auch die Nutzungsbedingungen (PrivacyScreen.nutzungsbedingungen).
class PrivacyScreen extends StatefulWidget {
  static const datenschutzUrl =
      'https://www.xn--sdsalat-n2a.eu/seiten/datenschutz.html';
  static const nutzungsbedingungenUrl =
      'https://www.xn--sdsalat-n2a.eu/seiten/nutzungsbedingungen.html';

  final String url;
  final String title;

  const PrivacyScreen({super.key})
    : url = datenschutzUrl,
      title = 'Datenschutzerklärung';

  const PrivacyScreen.nutzungsbedingungen({super.key})
    : url = nutzungsbedingungenUrl,
      title = 'Nutzungsbedingungen';

  @override
  State<PrivacyScreen> createState() => _PrivacyScreenState();
}

class _PrivacyScreenState extends State<PrivacyScreen> {
  late Future<String> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<String> _load() async {
    final response = await http.get(Uri.parse(widget.url));
    if (response.statusCode != 200) {
      throw Exception(
        '${widget.title} konnte nicht geladen werden (${response.statusCode})',
      );
    }

    // Die Seite deklariert UTF-8 nur per <meta charset>, nicht im HTTP-Header -
    // response.body wuerde sonst faelschlich Latin-1 annehmen und Umlaute zerstoeren.
    final document = html_parser.parse(utf8.decode(response.bodyBytes));
    final main = document.querySelector('main');
    if (main == null || main.innerHtml.trim().isEmpty) {
      throw Exception('${widget.title} hat ein unerwartetes Format.');
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

  /// Links in den Texten sind relativ zur Homepage-Seite ("impressum.html"). Verweise zwischen
  /// Datenschutzerklaerung und Nutzungsbedingungen oeffnen sich direkt in der App.
  Future<void> _openLink(String url) async {
    final uri = Uri.parse(widget.url).resolve(url);
    final ziel = uri.toString();
    if (ziel == PrivacyScreen.datenschutzUrl ||
        ziel == PrivacyScreen.nutzungsbedingungenUrl) {
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => ziel == PrivacyScreen.datenschutzUrl
              ? const PrivacyScreen()
              : const PrivacyScreen.nutzungsbedingungen(),
        ),
      );
      return;
    }
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
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
