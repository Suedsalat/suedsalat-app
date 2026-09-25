import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../models/episode.dart';
import '../models/event.dart';
import '../models/json_helpers.dart';
import '../models/location_tip.dart';
import '../models/movie_tip.dart';
import '../models/photo.dart';
import '../models/photo_comment.dart';
import '../models/tip_review.dart';
import 'auth_service.dart';
import 'stats_consent_service.dart';

/// Zusammenfassung der Rezensionen zu einem Filmtipp oder Locationtipp:
/// Durchschnittsbewertung (nur aus freigegebenen Rezensionen), Anzahl, Einzelrezensionen.
typedef TipReviewSummary = ({double? avgRating, int reviewCount, List<TipReview> reviews});

/// Fehler einer Server-Anfrage mit der Meldung des Servers - so, wie sie dem Nutzer angezeigt
/// werden kann (z. B. "Dieser Spitzname ist schon vergeben.").
class ApiException implements Exception {
  ApiException(this.message, this.statusCode);

  final String message;
  final int statusCode;

  @override
  String toString() => message;
}

/// Zugriff auf die öffentliche Lese-API des Backends (siehe 03-Backend).
///
/// Die Basis-URL wird gesetzt, sobald das Backend auf Strato erreichbar ist.
class ApiService {
  static const String _liveBaseUrl = 'https://www.xn--sdsalat-n2a.eu/APP/api';

  /// Server-Adresse. Normale Builds sprechen mit dem Live-Server; eine Test-App fuer den
  /// Testbereich wird mit --dart-define=API_BASE_URL=https://www.xn--sdsalat-n2a.eu/APP-test/api
  /// gebaut (siehe 03-Backend/TESTBEREICH.md).
  static const String baseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: _liveBaseUrl);

  /// Test-App: zeigt ein "TEST"-Band, damit sie niemand mit der echten verwechselt.
  static const bool isTestBackend = baseUrl != _liveBaseUrl;

  /// Admin-Bereich passend zum Server (live bzw. Testbereich).
  static String get adminLoginUrl => '${baseUrl.substring(0, baseUrl.length - '/api'.length)}/admin/login.php';

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

  /// JSON-Anfrage an die Konto-, Melde- und Einwilligungs-Schnittstellen (App 2.0).
  /// [path] relativ zu [baseUrl], z. B. 'listener/me.php'. Wirft [ApiException] mit der
  /// Fehlermeldung des Servers.
  Future<Map<String, dynamic>> sendJson(String method, String path, [Map<String, dynamic>? body]) async {
    final uri = Uri.parse('$baseUrl/$path');
    final response = await _authorizedRequest((headers) {
      final allHeaders = {...headers, 'Content-Type': 'application/json'};
      return method == 'GET'
          ? http.get(uri, headers: allHeaders)
          : http.post(uri, headers: allHeaders, body: jsonEncode(body ?? const {}));
    });
    var data = <String, dynamic>{};
    try {
      final decoded = jsonDecode(response.body);
      if (decoded is Map<String, dynamic>) data = decoded;
    } catch (_) {
      // Keine JSON-Antwort - unten mit Standardmeldung behandelt.
    }
    if (response.statusCode != 200) {
      final error = data['error'];
      throw ApiException(
        error is String ? error : 'Das hat leider nicht geklappt (${response.statusCode}).',
        response.statusCode,
      );
    }
    return data;
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
      throw Exception('Filmtipps konnten nicht geladen werden (${response.statusCode})');
    }
    final data = jsonDecode(response.body) as List<dynamic>;
    return data.map((e) => MovieTip.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<LocationTip>> fetchLocationTips() async {
    final response = await _authorizedRequest(
      (headers) => http.get(Uri.parse('$baseUrl/location-tips.php'), headers: headers),
    );
    if (response.statusCode != 200) {
      throw Exception('Locationtipps konnten nicht geladen werden (${response.statusCode})');
    }
    final data = jsonDecode(response.body) as List<dynamic>;
    return data.map((e) => LocationTip.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// Laedt Durchschnittsbewertung + freigegebene Einzelrezensionen zu einem Filmtipp
  /// oder Locationtipp. [tipType] ist entweder 'movie_tip' oder 'location_tip'.
  Future<TipReviewSummary> fetchTipReviews(String tipType, int tipId) async {
    final response = await _authorizedRequest(
      (headers) => http.get(
        Uri.parse('$baseUrl/tip-reviews.php?tip_type=$tipType&tip_id=$tipId'),
        headers: headers,
      ),
    );
    if (response.statusCode != 200) {
      throw Exception('Rezensionen konnten nicht geladen werden (${response.statusCode})');
    }
    final data = jsonDecode(response.body) as Map<String, dynamic>;
    final reviews = (data['reviews'] as List<dynamic>)
        .map((e) => TipReview.fromJson(e as Map<String, dynamic>))
        .toList();
    return (
      avgRating: parseNullableDouble(data['avg_rating']),
      reviewCount: parseIntOrZero(data['review_count']),
      reviews: reviews,
    );
  }

  /// Reicht eine Mikro-Bewertung (1-5) + Name + Rezensionstext ein (beides Pflicht).
  /// Die Rezension erscheint erst oeffentlich, nachdem sie im Adminbereich
  /// freigegeben wurde.
  Future<void> submitReview(String tipType, int tipId, int rating, String reviewerName, String reviewText) async {
    final response = await _authorizedRequest(
      (headers) => http.post(
        Uri.parse('$baseUrl/submit-review.php'),
        headers: headers,
        body: {
          'tip_type': tipType,
          'tip_id': tipId.toString(),
          'rating': rating.toString(),
          'reviewer_name': reviewerName.trim(),
          'review_text': reviewText.trim(),
        },
      ),
    );
    if (response.statusCode != 200) {
      String errorMessage = 'Rezension konnte nicht gesendet werden (${response.statusCode})';
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

  /// Kommentare zu einem Galerie-Foto (App 2.0), aelteste zuerst.
  Future<List<PhotoComment>> fetchPhotoComments(int photoId) async {
    final data = await sendJson('GET', 'gallery-comments.php?photo_id=$photoId');
    return _kommentare(data);
  }

  /// Kommentieren (nur angemeldet). Liefert die aktualisierte Liste.
  Future<List<PhotoComment>> postPhotoComment(int photoId, String text) async {
    final data = await sendJson('POST', 'gallery-comments.php', {'photo_id': photoId, 'text': text.trim()});
    return _kommentare(data);
  }

  Future<void> deletePhotoComment(int commentId) =>
      sendJson('POST', 'gallery-comments.php', {'action': 'delete', 'comment_id': commentId});

  List<PhotoComment> _kommentare(Map<String, dynamic> data) => (data['comments'] as List<dynamic>? ?? [])
      .map((e) => PhotoComment.fromJson(e as Map<String, dynamic>))
      .toList();

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

  /// Schickt eine Nachricht (optional mit Foto(s)/Video/Terminvorschlag) an Jenny und Thorsten.
  /// [media] ist fuer Video oder ein einzelnes Foto gedacht, [photos] fuer mehrere Fotos
  /// (werden als wiederholtes Formularfeld "media[]" gesendet, das Backend erkennt daran
  /// die Mehrfach-Einreichung).
  Future<void> submitFeedback({
    required String message,
    String type = 'allgemein',
    String? senderName,
    File? media,
    List<File>? photos,
    DateTime? suggestedDate,
    bool consentPublish = false,
    String? episodeGuid,
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
      if (episodeGuid != null && episodeGuid.isNotEmpty) {
        request.fields['episode_guid'] = episodeGuid;
      }
      if (suggestedDate != null) {
        request.fields['suggested_date'] =
            '${suggestedDate.year.toString().padLeft(4, '0')}-'
            '${suggestedDate.month.toString().padLeft(2, '0')}-'
            '${suggestedDate.day.toString().padLeft(2, '0')}';
      }
      if (photos != null && photos.isNotEmpty) {
        for (final photo in photos) {
          request.files.add(await http.MultipartFile.fromPath('media[]', photo.path));
        }
      } else if (media != null) {
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
  /// fuer die Nutzungsstatistik im Admin-Dashboard. Nur mit Einwilligung (StatsConsentService). Fehler werden bewusst
  /// verschluckt, da das reine Zaehlen nie den eigentlichen Bildschirmwechsel
  /// blockieren soll.
  Future<void> trackView(String screen) async {
    // Seit 2.0 nur mit Einwilligung - ohne wird gar nichts gesendet (§ 25 TDDDG).
    if (!StatsConsentService.instance.granted) return;
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

  /// Zaehlt anonym (ohne Personenbezug), dass eine Folge abgespielt wurde -
  /// fuer die Nutzungsstatistik im Admin-Dashboard. Fehler werden bewusst
  /// verschluckt, analog zu trackView().
  Future<void> trackEpisodePlay(String episodeGuid, {String? carContext}) async {
    if (!StatsConsentService.instance.granted) return;
    try {
      await _authorizedRequest(
        (headers) => http.post(
          Uri.parse('$baseUrl/track-episode-play.php'),
          headers: headers,
          body: {
            'episode_guid': episodeGuid,
            if (carContext != null) 'car_context': carContext,
          },
        ),
      );
    } catch (_) {
      // Netzwerkfehler ignorieren - reine Statistik, nicht kritisch.
    }
  }

  /// Zaehlt anonym, dass eine Folge eine Hoerdauer-Stufe erreicht hat
  /// (5/15/25/35/45 Minuten oder "bis zum Ende") - fuer die Trichter-
  /// Auswertung im Admin-Bereich. Fehler werden bewusst verschluckt.
  Future<void> trackEpisodeMilestone(String episodeGuid, String tier) async {
    if (!StatsConsentService.instance.granted) return;
    try {
      await _authorizedRequest(
        (headers) => http.post(
          Uri.parse('$baseUrl/track-episode-milestone.php'),
          headers: headers,
          body: {'episode_guid': episodeGuid, 'tier': tier},
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
