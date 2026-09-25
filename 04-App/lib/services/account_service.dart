import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_service.dart';

/// Das Konto eines registrierten Hoerers, wie es der Server liefert (Listener::publicProfile).
class ListenerProfile {
  const ListenerProfile({
    required this.id,
    required this.firstName,
    required this.lastName,
    required this.email,
    required this.nickname,
    required this.blocked,
  });

  final int id;
  final String firstName;
  final String lastName;
  final String email;
  final String nickname;
  final bool blocked;

  factory ListenerProfile.fromJson(Map<String, dynamic> json) =>
      ListenerProfile(
        id: (json['id'] as num).toInt(),
        firstName: json['first_name'] as String? ?? '',
        lastName: json['last_name'] as String? ?? '',
        email: json['email'] as String? ?? '',
        nickname: json['nickname'] as String? ?? '',
        blocked: json['blocked'] == true,
      );

  Map<String, dynamic> toJson() => {
    'id': id,
    'first_name': firstName,
    'last_name': lastName,
    'email': email,
    'nickname': nickname,
    'blocked': blocked,
  };
}

/// Hoerer, den man ausgeblendet hat (Einstellungen > Ausgeblendete Nutzer).
typedef HiddenUser = ({int id, String nickname});

/// Gast oder registrierter Hoerer (App 2.0, siehe 01-Brainstorming/Konzept-2.0-Hoererkonto.md).
///
/// Das Konto haengt am Geraet (der Server verknuepft die Installation mit dem Konto) - es gibt
/// kein eigenes Konto-Token. Der Stand wird lokal zwischengespeichert, damit die App auch ohne
/// Netz weiss, ob jemand angemeldet ist; beim Start gleicht [refresh] mit dem Server ab
/// (z. B. wenn auf einem anderen Geraet das Konto geloescht oder hier gesperrt wurde).
class AccountService extends ChangeNotifier {
  AccountService._();

  static final AccountService instance = AccountService._();

  static const _profileKey = 'listener_profile';
  static const _entryChosenKey = 'entry_choice_done';
  static const _guestNameKey = 'guest_sender_name';

  final _api = ApiService();

  ListenerProfile? _listener;
  bool _entryChosen = false;
  bool _loaded = false;

  ListenerProfile? get listener => _listener;
  bool get isLoggedIn => _listener != null;

  /// Darf Tipps, Fotos, Rezensionen, Sprachnachrichten und Kommentare einreichen.
  bool get canContribute => _listener != null && !_listener!.blocked;

  /// Hat die Startauswahl (Gast / Anmelden / Registrieren) schon hinter sich.
  bool get entryChosen => _entryChosen || _listener != null;
  bool get loaded => _loaded;

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    _entryChosen = prefs.getBool(_entryChosenKey) ?? false;
    final cached = prefs.getString(_profileKey);
    _listener = null;
    if (cached != null) {
      try {
        _listener = ListenerProfile.fromJson(
          jsonDecode(cached) as Map<String, dynamic>,
        );
      } catch (_) {
        // Beschaedigter Zwischenstand - dann eben als Gast, refresh() holt den echten Stand.
      }
    }
    _loaded = true;
    notifyListeners();
  }

  /// Abgleich mit dem Server. Ohne Netz bleibt der gespeicherte Stand.
  Future<void> refresh() async {
    try {
      final data = await _api.sendJson('GET', 'listener/me.php');
      final json = data['listener'];
      await _setListener(
        json is Map<String, dynamic> ? ListenerProfile.fromJson(json) : null,
      );
    } catch (_) {
      // Offline oder Server nicht erreichbar.
    }
  }

  Future<void> chooseGuest() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_entryChosenKey, true);
    _entryChosen = true;
    notifyListeners();
  }

  /// Registrierung anfordern - der Server schickt einen Code. Liefert die maskierte Adresse.
  Future<String> register({
    required String firstName,
    required String lastName,
    required String email,
    required String nickname,
  }) async {
    final data = await _api.sendJson('POST', 'listener/register.php', {
      'first_name': firstName.trim(),
      'last_name': lastName.trim(),
      'email': email.trim(),
      'nickname': nickname.trim(),
      'accept_terms': true,
    });
    return data['email'] as String? ?? email;
  }

  /// Anmeldecode anfordern. Liefert die maskierte Adresse (auch wenn es kein Konto gibt -
  /// der Server verraet nicht, welche Adressen registriert sind).
  Future<String> requestLoginCode(String email) async {
    final data = await _api.sendJson('POST', 'listener/login.php', {
      'email': email.trim(),
    });
    return data['email'] as String? ?? email;
  }

  /// Code bestaetigen (Registrierung oder Anmeldung). Liefert true, wenn ein Konto innerhalb
  /// der Rueckkehrfrist wiederhergestellt wurde.
  Future<bool> verify(String email, String code) async {
    final data = await _api.sendJson('POST', 'listener/verify.php', {
      'email': email.trim(),
      'code': code.trim(),
    });
    await _setListener(
      ListenerProfile.fromJson(data['listener'] as Map<String, dynamic>),
    );
    await chooseGuest(); // Startauswahl erledigt
    return data['restored'] == true;
  }

  Future<void> logout() async {
    try {
      await _api.sendJson('POST', 'listener/logout.php');
    } finally {
      await _setListener(null);
    }
  }

  Future<void> updateNickname(String nickname) async {
    final data = await _api.sendJson('POST', 'listener/update.php', {
      'nickname': nickname.trim(),
    });
    await _setListener(
      ListenerProfile.fromJson(data['listener'] as Map<String, dynamic>),
    );
  }

  /// Konto mit 30 Tagen Rueckkehrfrist stilllegen. Danach ist man hier als Gast unterwegs.
  Future<void> deleteWithGracePeriod({
    required bool deleteTexts,
    required bool deletePhotos,
  }) async {
    await _api.sendJson('POST', 'listener/delete.php', {
      'mode': 'grace',
      'delete_texts': deleteTexts,
      'delete_photos': deletePhotos,
    });
    await _setListener(null);
  }

  /// Sofortige Loeschung anfordern - der Server schickt einen Bestaetigungscode.
  /// Liefert die maskierte Adresse.
  Future<String> requestImmediateDeletion({
    required bool deleteTexts,
    required bool deletePhotos,
  }) async {
    final data = await _api.sendJson('POST', 'listener/delete.php', {
      'mode': 'now',
      'delete_texts': deleteTexts,
      'delete_photos': deletePhotos,
    });
    return data['email'] as String? ?? '';
  }

  Future<void> confirmImmediateDeletion(String code) async {
    await _api.sendJson('POST', 'listener/delete-confirm.php', {
      'code': code.trim(),
    });
    await _setListener(null);
  }

  Future<List<HiddenUser>> hiddenUsers() async {
    final data = await _api.sendJson('GET', 'listener/hidden-users.php');
    return (data['hidden_users'] as List<dynamic>? ?? [])
        .map(
          (e) => (
            id: ((e as Map<String, dynamic>)['id'] as num).toInt(),
            nickname: e['nickname'] as String,
          ),
        )
        .toList();
  }

  Future<void> unhideUser(int listenerId) => _api.sendJson(
    'POST',
    'listener/hidden-users.php',
    {'listener_id': listenerId},
  );

  /// Verfasser eines Beitrags fuer mich ausblenden. [contentType] wie beim Melden.
  Future<void> hideAuthor(String contentType, int contentId) => _api.sendJson(
    'POST',
    'listener/hide-author.php',
    {'content_type': contentType, 'content_id': contentId},
  );

  /// Beitrag melden (auch fuer Gaeste). Liefert true, wenn schon gemeldet war.
  Future<bool> report(
    String contentType,
    int contentId,
    String category,
    String? text,
  ) async {
    final data = await _api.sendJson('POST', 'report.php', {
      'content_type': contentType,
      'content_id': contentId,
      'category': category,
      if (text != null && text.trim().isNotEmpty) 'text': text.trim(),
    });
    return data['already_reported'] == true;
  }

  /// Fuer Gaeste: zuletzt verwendeter Name beim Feedback (nur auf dem Geraet).
  Future<String?> guestName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_guestNameKey);
  }

  Future<void> setGuestName(String? name) async {
    final prefs = await SharedPreferences.getInstance();
    if (name == null || name.trim().isEmpty) {
      await prefs.remove(_guestNameKey);
    } else {
      await prefs.setString(_guestNameKey, name.trim());
    }
  }

  Future<void> _setListener(ListenerProfile? profile) async {
    final prefs = await SharedPreferences.getInstance();
    if (profile == null) {
      await prefs.remove(_profileKey);
    } else {
      await prefs.setString(_profileKey, jsonEncode(profile.toJson()));
    }
    _listener = profile;
    notifyListeners();
  }
}
