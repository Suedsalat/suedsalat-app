import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../services/account_service.dart';
import '../../services/api_service.dart';
import '../../services/push_notification_service.dart';
import '../../services/stats_consent_service.dart';
import '../account/account_screen.dart';
import '../account/login_screen.dart';
import '../account/register_screen.dart';
import 'privacy_screen.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  static const _pushEnabledKey = 'push_enabled';

  bool _pushEnabled = true;
  bool _loaded = false;
  String? _versionLabel;
  String? _guestName;

  @override
  void initState() {
    super.initState();
    _loadPreference();
    _loadVersion();
    _loadGuestName();
  }

  Future<void> _loadGuestName() async {
    final name = await AccountService.instance.guestName();
    if (mounted) setState(() => _guestName = name);
  }

  Future<void> _forgetGuestName() async {
    await AccountService.instance.setGuestName(null);
    if (mounted) setState(() => _guestName = null);
  }

  void _push(Widget screen) {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
  }

  /// Konto-Bereich oben in den Einstellungen: angemeldet -> Mein Konto, sonst Anmelden/Registrieren
  /// und (fuer Gaeste) der gemerkte Name fuer Feedback.
  List<Widget> _accountTiles() {
    final listener = AccountService.instance.listener;
    if (listener != null) {
      return [
        ListTile(
          leading: const Icon(Icons.account_circle_outlined),
          title: Text(listener.nickname),
          subtitle: const Text('Mein Konto'),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => _push(const AccountScreen()),
        ),
      ];
    }
    return [
      const ListTile(
        leading: Icon(Icons.account_circle_outlined),
        title: Text('Du bist als Gast unterwegs'),
        subtitle: Text(
          'Mit einem kostenlosen Konto kannst du Tipps, Fotos und Rezensionen einreichen.',
        ),
      ),
      ListTile(
        leading: const SizedBox(width: 24),
        title: const Text('Registrieren'),
        onTap: () => _push(const RegisterScreen()),
      ),
      ListTile(
        leading: const SizedBox(width: 24),
        title: const Text('Anmelden'),
        onTap: () => _push(const LoginScreen()),
      ),
      if (_guestName != null)
        ListTile(
          leading: const Icon(Icons.badge_outlined),
          title: Text(_guestName!),
          subtitle: const Text(
            'Gemerkter Name für Feedback (nur auf diesem Gerät)',
          ),
          trailing: IconButton(
            icon: const Icon(Icons.delete_outline),
            tooltip: 'Namen vergessen',
            onPressed: _forgetGuestName,
          ),
        ),
    ];
  }

  Future<void> _loadPreference() async {
    final prefs = await SharedPreferences.getInstance();
    setState(() {
      _pushEnabled = prefs.getBool(_pushEnabledKey) ?? true;
      _loaded = true;
    });
  }

  Future<void> _loadVersion() async {
    final info = await PackageInfo.fromPlatform();
    if (!mounted) return;
    setState(
      () => _versionLabel = 'Version ${info.version} (${info.buildNumber})',
    );
  }

  Future<void> _setPushEnabled(bool value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_pushEnabledKey, value);
    setState(() => _pushEnabled = value);

    if (value) {
      await PushNotificationService.instance.enable();
    } else {
      await PushNotificationService.instance.disable();
    }
  }

  Future<void> _openUrl(String url) async {
    final uri = Uri.parse(url);
    try {
      final launched = await launchUrl(
        uri,
        mode: LaunchMode.externalApplication,
      );
      if (!launched) throw Exception('launch failed');
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Der Link konnte nicht geöffnet werden.'),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Einstellungen')),
      body: !_loaded
          ? const Center(child: CircularProgressIndicator())
          : ListenableBuilder(
              listenable: Listenable.merge([
                AccountService.instance,
                StatsConsentService.instance,
              ]),
              builder: (context, _) => ListView(
                children: [
                  ..._accountTiles(),
                  const Divider(),
                  SwitchListTile(
                    title: const Text('Push-Benachrichtigungen'),
                    subtitle: const Text(
                      'Bei neuer Folge oder neuer Veranstaltung',
                    ),
                    value: _pushEnabled,
                    onChanged: _setPushEnabled,
                  ),
                  SwitchListTile(
                    title: const Text('Anonyme Statistik'),
                    subtitle: const Text(
                      'Zählt nur Summen, z. B. wie oft eine Folge gehört wird – nie, wer was hört.',
                    ),
                    value: StatsConsentService.instance.granted,
                    onChanged: (value) =>
                        StatsConsentService.instance.decide(granted: value),
                  ),
                  const Divider(),
                  ListTile(
                    leading: const Icon(Icons.language),
                    title: const Text('Zur Homepage'),
                    onTap: () => _openUrl('https://www.xn--sdsalat-n2a.eu'),
                  ),
                  ListTile(
                    leading: const Icon(Icons.privacy_tip_outlined),
                    title: const Text('Datenschutzerklärung'),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => const PrivacyScreen()),
                    ),
                  ),
                  ListTile(
                    leading: const Icon(Icons.gavel_outlined),
                    title: const Text('Nutzungsbedingungen'),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => const PrivacyScreen.nutzungsbedingungen(),
                      ),
                    ),
                  ),
                  ListTile(
                    leading: const Icon(Icons.admin_panel_settings_outlined),
                    title: const Text('Admin-Bereich'),
                    subtitle: const Text('Für Jenny & Thorsten'),
                    onTap: () => _openUrl(ApiService.adminLoginUrl),
                  ),
                  const Divider(),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Impressum',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: 10),
                        const Text(
                          'App-Entwicklung\n'
                          'Thorsten Koch\n'
                          'Josef-Burghof-Str. 9\n'
                          '53919 Weilerswist\n\n'
                          'Telefon: 0177/2583191\n'
                          'E-Mail: info@südsalat.eu',
                        ),
                        if (_versionLabel != null) ...[
                          const SizedBox(height: 12),
                          Text(
                            _versionLabel!,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
