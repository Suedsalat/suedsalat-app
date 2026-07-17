import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../models/episode.dart';
import '../models/event.dart';
import '../models/movie_tip.dart';
import '../models/photo.dart';
import 'auth_service.dart';

/// Zugriff auf die öffentliche Lese-API des Backends (siehe 03-Backend).
///
/// Die Basis-URL wird gesetzt, sobald das Backend auf Strato erreichbar ist.
class ApiService {
  static const String baseUrl = 'https://www.xn--sdsalat-n2a.eu/APP/api';

  /// Fuehrt [send] mit einem gueltigen Access-Token im Authorization-Header aus.
  /// Bei 401 wird einmal mit erzwungenem Token-Refresh wiederholt (deckt
  /// Uhr-Drift/Races ab, ohne bei echten Server-Fehlern in eine Schleife zu laufen).
  Future<http.Response> _authorizedRequest(
    Future<http.Response> Function(Map<String, String> headers) send,
  ) async {
    final token = await AuthService.instance.getValidAccessToken();
    final response = await send({'Authorization': 'Bearer $token'});
    if (response.statusCode != 401) {
      return response;
    }
    final freshToken = await AuthService.instance.forceRefresh();
    return send({'Authorization': 'Bearer $freshToken'});
  }

  Future<List<Episode>> fetchEpisodes() async {
    final response = await _authorizedRequest(
      (headers) => http.get(Uri.parse('$baseUrl/episodes.php'), headers: headers),
    );
    if (response.statusCode != 200) {
      throw Exception('Folgen konnten nicht geladen werden (${response.statusCode})');
    }
    final data = jsonDecode(response.body) as List<dynamic>;
    return data.map((e) => Episode.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<Event>> fetchEvents() async {
    final response = await _authorizedRequest(
      (headers) => http.get(Uri.parse('$baseUrl/events.php'), headers: headers),
    );
    if (response.statusCode != 200) {
      throw Exception('Termine konnten nicht geladen werden (${response.statusCode})');
    }
    final data = jsonDecode(response.body) as List<dynamic>;
    return data.map((e) => Event.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<MovieTip>> fetchMovieTips() async {
    final response = await _authorizedRequest(
      (headers) => http.get(Uri.parse('$baseUrl/movie-tips.php'), headers: headers),
    );
    if (response.statusCode != 200) {
      throw Exception('Kinotipps konnten nicht geladen werden (${response.statusCode})');
    }
    final data = jsonDecode(response.body) as List<dynamic>;
    return data.map((e) => MovieTip.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<Photo>> fetchGallery() async {
    final response = await _authorizedRequest(
      (headers) => http.get(Uri.parse('$baseUrl/gallery.php'), headers: headers),
    );
    if (response.statusCode != 200) {
      throw Exception('Galerie konnte nicht geladen werden (${response.statusCode})');
    }
    final data = jsonDecode(response.body) as List<dynamic>;
    return data.map((e) => Photo.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// Schickt eine Nachricht (optional mit Foto/Video/Terminvorschlag) an Jenny und Thorsten.
  Future<void> submitFeedback({
    required String message,
    String type = 'allgemein',
    String? senderName,
    File? media,
    DateTime? suggestedDate,
    bool consentPublish = false,
  }) async {
    Future<http.StreamedResponse> buildAndSend(Map<String, String> headers) async {
      final request = http.MultipartRequest('POST', Uri.parse('$baseUrl/feedback.php'));
      request.headers.addAll(headers);
      request.fields['message'] = message;
      request.fields['type'] = type;
      request.fields['consent_publish'] = consentPublish ? '1' : '0';
      if (senderName != null && senderName.trim().isNotEmpty) {
        request.fields['sender_name'] = senderName.trim();
      }
      if (suggestedDate != null) {
        request.fields['suggested_date'] =
            '${suggestedDate.year.toString().padLeft(4, '0')}-'
            '${suggestedDate.month.toString().padLeft(2, '0')}-'
            '${suggestedDate.day.toString().padLeft(2, '0')}';
      }
      if (media != null) {
        request.files.add(await http.MultipartFile.fromPath('media', media.path));
      }
      return request.send();
    }

    var token = await AuthService.instance.getValidAccessToken();
    var streamedResponse = await buildAndSend({'Authorization': 'Bearer $token'});
    if (streamedResponse.statusCode == 401) {
      token = await AuthService.instance.forceRefresh();
      streamedResponse = await buildAndSend({'Authorization': 'Bearer $token'});
    }
    final response = await http.Response.fromStream(streamedResponse);

    if (response.statusCode != 200) {
      String errorMessage = 'Nachricht konnte nicht gesendet werden (${response.statusCode})';
      try {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        if (data['error'] is String) {
          errorMessage = data['error'] as String;
        }
      } catch (_) {
        // Antwort war kein JSON - Standardfehlermeldung verwenden.
      }
      throw Exception(errorMessage);
    }
  }

  /// Zaehlt anonym (ohne Personenbezug), dass ein App-Bereich geoeffnet wurde -
  /// fuer die Nutzungsstatistik im Admin-Dashboard. Fehler werden bewusst
  /// verschluckt, da das reine Zaehlen nie den eigentlichen Bildschirmwechsel
  /// blockieren soll.
  Future<void> trackView(String screen) async {
    try {
      await _authorizedRequest(
        (headers) => http.post(
          Uri.parse('$baseUrl/track-view.php'),
          headers: headers,
          body: {'screen': screen},
        ),
      );
    } catch (_) {
      // Netzwerkfehler ignorieren - reine Statistik, nicht kritisch.
    }
  }

  Future<void> registerPushToken(String deviceToken, String platform) async {
    await _authorizedRequest(
      (headers) => http.post(
        Uri.parse('$baseUrl/register-push-token.php'),
        headers: {...headers, 'Content-Type': 'application/json'},
        body: jsonEncode({'device_token': deviceToken, 'platform': platform}),
      ),
    );
  }

  Future<void> unregisterPushToken(String deviceToken) async {
    await _authorizedRequest(
      (headers) => http.post(
        Uri.parse('$baseUrl/register-push-token.php'),
        headers: {...headers, 'Content-Type': 'application/json'},
        body: jsonEncode({'device_token': deviceToken, 'action': 'unregister'}),
      ),
    );
  }
}
