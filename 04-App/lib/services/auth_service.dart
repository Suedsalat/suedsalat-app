import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

import 'api_service.dart';

/// Verwaltet die anonyme Geraete-Identitaet der App gegenueber dem Backend
/// (Access-/Refresh-Token, siehe 03-Backend/api/auth/*.php). Kein Nutzerlogin -
/// nur ein Nachweis "dieses Geraet hat sich registriert", damit die API nicht
/// komplett offen ist und einzelne Geraete bei Missbrauch gesperrt werden koennen.
class AuthService {
  AuthService._();

  static final AuthService instance = AuthService._();

  /// Wird beim Release-Build per --dart-define=APP_SECRET=... gesetzt (Codemagic),
  /// damit der Wert nicht im Klartext im Git-Verlauf steht. Kein Kryptografie-Schutz -
  /// aus einem decompilierten Build laesst er sich extrahieren, siehe 03-Backend/.env.example.
  static const _appSecret = String.fromEnvironment('APP_SECRET', defaultValue: '');

  static const _deviceUuidKey = 'device_uuid';
  static const _refreshTokenKey = 'refresh_token';

  final _storage = const FlutterSecureStorage();

  String? _accessToken;
  DateTime? _accessTokenExpiry;
  Future<String>? _inFlightRefresh;

  String get _platform => Platform.isIOS ? 'ios' : 'android';

  /// Liefert ein gueltiges Access-Token, holt bei Bedarf ein neues (Refresh oder
  /// Neu-Registrierung). Parallele Aufrufe teilen sich denselben In-Flight-Refresh,
  /// damit nicht mehrere der 7 ApiService-Aufrufstellen gleichzeitig refreshen.
  Future<String> getValidAccessToken() async {
    final token = _accessToken;
    final expiry = _accessTokenExpiry;
    if (token != null && expiry != null && expiry.isAfter(DateTime.now().add(const Duration(seconds: 30)))) {
      return token;
    }
    final inFlight = _inFlightRefresh;
    if (inFlight != null) {
      return inFlight;
    }
    final future = _refreshOrRegister();
    _inFlightRefresh = future;
    try {
      return await future;
    } finally {
      _inFlightRefresh = null;
    }
  }

  /// Erzwingt ein neues Access-Token unabhaengig vom In-Memory-Stand - z. B. nach
  /// einer 401-Antwort trotz vermeintlich noch gueltigem Token (Uhr-Drift o.ae.).
  Future<String> forceRefresh() async {
    _accessToken = null;
    _accessTokenExpiry = null;
    return getValidAccessToken();
  }

  Future<String> _refreshOrRegister() async {
    final refreshToken = await _storage.read(key: _refreshTokenKey);
    if (refreshToken != null) {
      try {
        return await _refresh(refreshToken);
      } catch (_) {
        // Refresh-Token ungueltig/abgelaufen/widerrufen -> neu registrieren.
      }
    }
    return _register();
  }

  Future<String> _refresh(String refreshToken) async {
    final response = await http.post(
      Uri.parse('${ApiService.baseUrl}/auth/refresh.php'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'refresh_token': refreshToken}),
    );
    if (response.statusCode != 200) {
      throw Exception('Token-Refresh fehlgeschlagen (${response.statusCode})');
    }
    return _storeTokenResponse(response.body);
  }

  Future<String> _register() async {
    final deviceUuid = await _deviceUuid();
    final response = await http.post(
      Uri.parse('${ApiService.baseUrl}/auth/device.php'),
      headers: {
        'Content-Type': 'application/json',
        'X-App-Secret': _appSecret,
      },
      body: jsonEncode({'device_uuid': deviceUuid, 'platform': _platform}),
    );
    if (response.statusCode != 200) {
      throw Exception('Geräte-Registrierung fehlgeschlagen (${response.statusCode})');
    }
    return _storeTokenResponse(response.body);
  }

  Future<String> _storeTokenResponse(String body) async {
    final data = jsonDecode(body) as Map<String, dynamic>;
    final accessToken = data['access_token'] as String;
    final refreshToken = data['refresh_token'] as String;
    final expiresIn = data['expires_in'] as int;

    await _storage.write(key: _refreshTokenKey, value: refreshToken);
    _accessToken = accessToken;
    _accessTokenExpiry = DateTime.now().add(Duration(seconds: expiresIn));
    return accessToken;
  }

  Future<String> _deviceUuid() async {
    final existing = await _storage.read(key: _deviceUuidKey);
    if (existing != null) {
      return existing;
    }
    final generated = const Uuid().v4();
    await _storage.write(key: _deviceUuidKey, value: generated);
    return generated;
  }
}
