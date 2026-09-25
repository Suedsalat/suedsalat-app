import 'package:flutter/material.dart';

import '../../services/account_service.dart';
import '../../widgets/async_state_views.dart';

/// Nutzer, deren Beitraege ich nicht mehr sehen moechte - hier wieder einblenden.
class HiddenUsersScreen extends StatefulWidget {
  const HiddenUsersScreen({super.key});

  @override
  State<HiddenUsersScreen> createState() => _HiddenUsersScreenState();
}

class _HiddenUsersScreenState extends State<HiddenUsersScreen> {
  late Future<List<HiddenUser>> _future = AccountService.instance.hiddenUsers();

  Future<void> _unhide(HiddenUser user) async {
    try {
      await AccountService.instance.unhideUser(user.id);
      setState(() => _future = AccountService.instance.hiddenUsers());
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('${user.nickname} ist wieder eingeblendet.')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(e.toString())));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Ausgeblendete Nutzer')),
      body: FutureBuilder<List<HiddenUser>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const LoadingStateView();
          }
          if (snapshot.hasError) {
            return ErrorStateView(
              onRetry: () => setState(
                () => _future = AccountService.instance.hiddenUsers(),
              ),
            );
          }
          final users = snapshot.data!;
          if (users.isEmpty) {
            return const Padding(
              padding: EdgeInsets.all(24),
              child: Text(
                'Du hast niemanden ausgeblendet.\n\nBei jedem Beitrag kannst du über das Menü „⋮“ den Verfasser '
                'ausblenden – dann siehst du seine Beiträge nicht mehr. Die anderen sehen sie weiterhin.',
              ),
            );
          }
          return ListView(
            children: [
              for (final user in users)
                ListTile(
                  leading: const Icon(Icons.visibility_off_outlined),
                  title: Text(user.nickname),
                  trailing: TextButton(
                    onPressed: () => _unhide(user),
                    child: const Text('Einblenden'),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
