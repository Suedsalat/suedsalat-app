import 'package:flutter/material.dart';

/// Ein Eintrag der unteren Navigationsleiste.
class AppBottomNavItem {
  final Widget icon;
  final String label;

  const AppBottomNavItem({required this.icon, required this.label});
}

/// Ersetzt Flutters Standard-`NavigationBar`, weil dessen Icon+Label-Block als
/// Ganzes vertikal zentriert wird - bei sechs Tabs mit unterschiedlich langen
/// Beschriftungen (manche brechen in zwei Zeilen um, andere nicht) "tanzen" die
/// Icons dadurch je nach Tab auf und ab. Hier bekommt das Icon eine feste
/// Position oben, die Beschriftung darunter einen fest hohen, zentrierten
/// Bereich - unabhaengig davon, ob der Text ein- oder zweizeilig ist.
class AppBottomNavBar extends StatelessWidget {
  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final List<AppBottomNavItem> items;

  const AppBottomNavBar({
    super.key,
    required this.selectedIndex,
    required this.onDestinationSelected,
    required this.items,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: theme.colorScheme.surfaceContainer,
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 76,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (var i = 0; i < items.length; i++)
                Expanded(
                  child: InkWell(
                    onTap: () => onDestinationSelected(i),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.start,
                      children: [
                        const SizedBox(height: 10),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
                          decoration: BoxDecoration(
                            color: i == selectedIndex
                                ? theme.colorScheme.secondaryContainer
                                : Colors.transparent,
                            borderRadius: BorderRadius.circular(16),
                          ),
                          child: items[i].icon,
                        ),
                        const SizedBox(height: 4),
                        SizedBox(
                          height: 28,
                          child: Center(
                            child: Text(
                              items[i].label,
                              textAlign: TextAlign.center,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.labelSmall?.copyWith(
                                color: i == selectedIndex
                                    ? theme.colorScheme.onSurface
                                    : theme.colorScheme.onSurfaceVariant,
                                fontWeight: i == selectedIndex ? FontWeight.w700 : FontWeight.w400,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
