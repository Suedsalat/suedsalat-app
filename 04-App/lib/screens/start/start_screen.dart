import 'package:flutter/material.dart';

import '../../widgets/new_dot.dart';
import '../feedback/feedback_screen.dart';
import '../newsletter/newsletter_screen.dart';

class StartScreen extends StatelessWidget {
  final void Function(int tabIndex) onNavigateToTab;
  final Future<void> Function() onRefresh;
  final bool hasNewEpisodes;
  final bool hasNewEvents;
  final bool hasNewPhotos;
  final bool hasNewMovieTips;
  final bool hasNewLocationTips;

  const StartScreen({
    super.key,
    required this.onNavigateToTab,
    required this.onRefresh,
    this.hasNewEpisodes = false,
    this.hasNewEvents = false,
    this.hasNewPhotos = false,
    this.hasNewMovieTips = false,
    this.hasNewLocationTips = false,
  });

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: RefreshIndicator(
        onRefresh: onRefresh,
        child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
        children: [
          Center(
            child: Image.asset('assets/images/logo.png', width: 220),
          ),
          const SizedBox(height: 12),
          Text(
            'Willkommen',
            style: Theme.of(context).textTheme.headlineSmall,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 4),
          Text(
            'in der Südsalat-App!',
            style: Theme.of(context).textTheme.headlineSmall,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 16),
          Text(
            'Hier findest du alle Folgen, Veranstaltungen, Kino- und Filmtipps, Locationtipps und Fotos rund um den Podcast.',
            style: Theme.of(context).textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 28),
          _StartTile(
            leading: Image.asset('assets/images/folgen.png', width: 40, height: 40),
            title: 'Folgen',
            subtitle: 'Alle Episoden direkt zum Anhören',
            showNewDot: hasNewEpisodes,
            onTap: () => onNavigateToTab(1),
          ),
          const SizedBox(height: 12),
          _StartTile(
            leading: Image.asset('assets/images/kalender.png', width: 40, height: 40),
            title: 'Veranstaltungen',
            subtitle: 'Termine, die als nächstes anstehen',
            showNewDot: hasNewEvents,
            onTap: () => onNavigateToTab(2),
          ),
          const SizedBox(height: 12),
          _StartTile(
            leading: Image.asset('assets/images/kino.png', width: 40, height: 40),
            title: 'Kino- und Filmtipps',
            subtitle: 'Empfehlungen für einen Film- oder Kinoabend',
            showNewDot: hasNewMovieTips,
            onTap: () => onNavigateToTab(3),
          ),
          const SizedBox(height: 12),
          _StartTile(
            leading: Image.asset('assets/images/location.png', width: 40, height: 40),
            title: 'Locationtipps',
            subtitle: 'Restaurants, Museen und Ausflugsziele',
            showNewDot: hasNewLocationTips,
            onTap: () => onNavigateToTab(4),
          ),
          const SizedBox(height: 12),
          _StartTile(
            leading: Image.asset('assets/images/galerie.png', width: 40, height: 40),
            title: 'Galerie',
            subtitle: 'Eure und unsere Fotos zu unserem Podcast',
            showNewDot: hasNewPhotos,
            onTap: () => onNavigateToTab(5),
          ),
          const SizedBox(height: 12),
          _StartTile(
            leading: Image.asset('assets/images/newsletter.png', width: 40, height: 40),
            title: 'Newsletter',
            subtitle: 'Erhalte die neuesten Infos direkt aus erster Hand',
            onTap: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const NewsletterScreen()),
              );
            },
          ),
          const SizedBox(height: 12),
          _StartTile(
            leading: Image.asset('assets/images/feedback.png', width: 40, height: 40),
            title: 'Feedback',
            subtitle: 'Schreib oder sprich uns eine Nachricht',
            onTap: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const FeedbackScreen()),
              );
            },
          ),
        ],
        ),
      ),
    );
  }
}

class _StartTile extends StatelessWidget {
  final Widget leading;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool showNewDot;

  const _StartTile({
    required this.leading,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.showNewDot = false,
  });

  @override
  Widget build(BuildContext context) {
    // Feste Hoehe, damit alle Kacheln gleich gross sind, egal ob der Untertitel
    // ein- oder zweizeilig ist (manche Beschreibungen sind deutlich laenger).
    return SizedBox(
      height: 88,
      child: Card(
        child: ListTile(
          leading: leading,
          title: Text(title),
          subtitle: Text(subtitle, maxLines: 2, overflow: TextOverflow.ellipsis),
          trailing: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (showNewDot) ...[
                const NewDot(),
                const SizedBox(width: 8),
              ],
              const Icon(Icons.chevron_right),
            ],
          ),
          onTap: onTap,
        ),
      ),
    );
  }
}
