import 'package:flutter/material.dart';

import '../services/api_service.dart';
import '../services/seen_items_service.dart';
import '../widgets/app_bottom_nav_bar.dart';
import '../widgets/mini_player_bar.dart';
import 'episodes/episodes_list_screen.dart';
import 'events/events_list_screen.dart';
import 'feedback/feedback_screen.dart';
import 'gallery/gallery_screen.dart';
import 'location_tips/location_tips_list_screen.dart';
import 'movie_tips/movie_tips_list_screen.dart';
import 'settings/settings_screen.dart';
import 'start/start_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  final _api = ApiService();
  int _currentIndex = 0;

  bool _hasNewEpisodes = false;
  bool _hasNewEvents = false;
  bool _hasNewPhotos = false;
  bool _hasNewMovieTips = false;
  bool _hasNewLocationTips = false;

  // Wird bei jedem Rueckkehren aus dem Hintergrund erhoeht, damit der aktuell
  // sichtbare Tab als neues Widget behandelt wird und seine Daten neu laedt
  // (reiner Tab-Wechsel loest das schon durch den Typwechsel aus, ein
  // App-Resume ohne Tab-Wechsel bisher nicht).
  int _refreshEpoch = 0;

  static const _titles = ['Start', 'Folgen', 'Veranstaltungen', 'Kino- und Filmtipps', 'Locationtipps', 'Galerie'];
  static const _screenKeys = ['start', 'episodes', 'events', 'movie_tips', 'location_tips', 'gallery'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _loadNewFlags();
    _api.trackView(_screenKeys[_currentIndex]);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      setState(() => _refreshEpoch++);
      _loadNewFlags();
    }
  }

  /// Prueft, ob es in einer Kategorie (Folgen/Termine/Fotos) noch nicht
  /// gesehene Eintraege gibt, fuer die gruenen "Neu"-Punkte auf Startseite
  /// und Navigationsleiste.
  Future<bool> _hasNew(String category, List<String> ids) async {
    await SeenItemsService.ensureBaseline(category, ids);
    final seen = await SeenItemsService.getSeen(category);
    return ids.any((id) => !seen.contains(id));
  }

  Future<void> _loadNewFlags() async {
    try {
      final episodes = await _api.fetchEpisodes();
      final events = await _api.fetchEvents();
      final photos = await _api.fetchGallery();
      final movieTips = await _api.fetchMovieTips();
      final locationTips = await _api.fetchLocationTips();

      final hasNewEpisodes = await _hasNew('episode', episodes.map((e) => e.guid).toList());
      final hasNewEvents = await _hasNew('event', events.map((e) => e.id.toString()).toList());
      final hasNewPhotos = await _hasNew('photo', photos.map((p) => p.id.toString()).toList());
      final hasNewMovieTips = await _hasNew('movie_tip', movieTips.map((t) => t.id.toString()).toList());
      final hasNewLocationTips = await _hasNew('location_tip', locationTips.map((t) => t.id.toString()).toList());

      if (!mounted) return;
      setState(() {
        _hasNewEpisodes = hasNewEpisodes;
        _hasNewEvents = hasNewEvents;
        _hasNewPhotos = hasNewPhotos;
        _hasNewMovieTips = hasNewMovieTips;
        _hasNewLocationTips = hasNewLocationTips;
      });
    } catch (_) {
      // Netzwerkfehler hier ignorieren - Badges bleiben einfach wie zuvor.
    }
  }

  void _navigateToTab(int index) {
    setState(() => _currentIndex = index);
    _loadNewFlags();
    _api.trackView(_screenKeys[index]);
  }

  void _openFeedbackWithType(String type) {
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => FeedbackScreen(initialType: type)),
    );
  }

  /// Direkter Einreichen-Button auf den Bildschirmen, die eine passende
  /// Feedback-Kategorie haben - erspart den Umweg ueber das Dropdown im
  /// allgemeinen Feedback-Formular.
  Widget? _buildTipFab() {
    switch (_currentIndex) {
      case 2:
        return FloatingActionButton.extended(
          onPressed: () => _openFeedbackWithType('termin_tipp'),
          icon: const Icon(Icons.add),
          label: const Text('Termintipp abgeben'),
        );
      case 3:
        return FloatingActionButton.extended(
          onPressed: () => _openFeedbackWithType('kino_tipp'),
          icon: const Icon(Icons.add),
          label: const Text('Kino- und Filmtipp einreichen'),
        );
      case 4:
        return FloatingActionButton.extended(
          onPressed: () => _openFeedbackWithType('location_tipp'),
          icon: const Icon(Icons.add),
          label: const Text('Locationtipp einreichen'),
        );
      case 5:
        return FloatingActionButton.extended(
          onPressed: () => _openFeedbackWithType('foto_vorschlag'),
          icon: const Icon(Icons.add_a_photo),
          label: const Text('Foto einreichen'),
        );
      default:
        return null;
    }
  }

  @override
  Widget build(BuildContext context) {
    final screens = [
      StartScreen(
        key: ValueKey('start-$_refreshEpoch'),
        onNavigateToTab: _navigateToTab,
        onRefresh: _loadNewFlags,
        hasNewEpisodes: _hasNewEpisodes,
        hasNewEvents: _hasNewEvents,
        hasNewPhotos: _hasNewPhotos,
        hasNewMovieTips: _hasNewMovieTips,
        hasNewLocationTips: _hasNewLocationTips,
      ),
      EpisodesListScreen(key: ValueKey('episodes-$_refreshEpoch')),
      EventsListScreen(key: ValueKey('events-$_refreshEpoch')),
      MovieTipsListScreen(key: ValueKey('movie-tips-$_refreshEpoch')),
      LocationTipsListScreen(key: ValueKey('location-tips-$_refreshEpoch')),
      GalleryScreen(key: ValueKey('gallery-$_refreshEpoch')),
    ];

    return Scaffold(
      appBar: AppBar(
        leading: _currentIndex == 0
            ? null
            : IconButton(
                icon: const Icon(Icons.arrow_back),
                tooltip: 'Zurück zur Startseite',
                onPressed: () => _navigateToTab(0),
              ),
        title: Text(_titles[_currentIndex]),
        actions: [
          IconButton(
            icon: Image.asset('assets/images/feedback_rand.png', width: 24, height: 24),
            tooltip: 'Feedback',
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const FeedbackScreen()),
              );
            },
          ),
          IconButton(
            icon: const Icon(Icons.settings),
            tooltip: 'Einstellungen',
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const SettingsScreen()),
              );
            },
          ),
        ],
      ),
      body: screens[_currentIndex],
      floatingActionButton: _buildTipFab(),
      bottomNavigationBar: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const MiniPlayerBar(),
          AppBottomNavBar(
            selectedIndex: _currentIndex,
            onDestinationSelected: _navigateToTab,
            items: [
              AppBottomNavItem(
                icon: Image.asset('assets/images/home.png', width: 32, height: 32),
                label: 'Start',
              ),
              AppBottomNavItem(
                icon: Badge(
                  backgroundColor: Theme.of(context).colorScheme.primary,
                  isLabelVisible: _hasNewEpisodes,
                  child: Image.asset('assets/images/folgen.png', width: 32, height: 32),
                ),
                label: 'Folgen',
              ),
              AppBottomNavItem(
                icon: Badge(
                  backgroundColor: Theme.of(context).colorScheme.primary,
                  isLabelVisible: _hasNewEvents,
                  child: Image.asset('assets/images/kalender.png', width: 32, height: 32),
                ),
                label: 'Veranstaltungen',
              ),
              AppBottomNavItem(
                icon: Badge(
                  backgroundColor: Theme.of(context).colorScheme.primary,
                  isLabelVisible: _hasNewMovieTips,
                  child: Image.asset('assets/images/kino.png', width: 32, height: 32),
                ),
                label: 'Kino- und Filme',
              ),
              AppBottomNavItem(
                icon: Badge(
                  backgroundColor: Theme.of(context).colorScheme.primary,
                  isLabelVisible: _hasNewLocationTips,
                  child: Image.asset('assets/images/location.png', width: 32, height: 32),
                ),
                label: 'Locations',
              ),
              AppBottomNavItem(
                icon: Badge(
                  backgroundColor: Theme.of(context).colorScheme.primary,
                  isLabelVisible: _hasNewPhotos,
                  child: Image.asset('assets/images/galerie.png', width: 32, height: 32),
                ),
                label: 'Galerie',
              ),
            ],
          ),
        ],
      ),
    );
  }
}
