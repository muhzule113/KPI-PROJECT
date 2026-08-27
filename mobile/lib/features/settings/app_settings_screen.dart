import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/settings/app_preferences.dart';

class AppSettingsScreen extends StatelessWidget {
  const AppSettingsScreen({super.key});

  Future<void> _pickTheme(BuildContext context) async {
    final preferences = context.read<AppPreferences>();
    final selected = await showOpsSelectionSheet<ThemeMode>(
      context: context,
      title: 'Pilih mode tampilan',
      selectedValue: preferences.themeMode,
      options: ThemeMode.values
          .map(
            (mode) => OpsSelectionOption<ThemeMode>(
              value: mode,
              label: themeModeLabel(mode),
              supportingText: switch (mode) {
                ThemeMode.system => 'Otomatis mengikuti pengaturan perangkat',
                ThemeMode.light => 'Gunakan tampilan terang sepanjang waktu',
                ThemeMode.dark => 'Gunakan tampilan gelap sepanjang waktu',
              },
              icon: themeModeIcon(mode),
            ),
          )
          .toList(),
    );
    if (selected != null) {
      await preferences.setThemeMode(selected);
    }
  }

  Future<void> _reset(BuildContext context) async {
    final preferences = context.read<AppPreferences>();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Reset tampilan?'),
        content: const Text(
          'Mode tampilan akan kembali ke gelap dan animasi akan diaktifkan kembali.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Reset'),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      await preferences.reset();
    }
  }

  @override
  Widget build(BuildContext context) {
    final preferences = context.watch<AppPreferences>();

    return Scaffold(
      appBar: AppBar(title: const Text('Pengaturan aplikasi')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
        children: [
          const OpsPageHeader(
            eyebrow: 'Preferensi aplikasi',
            title: 'Atur pengalaman kerja kamu.',
            subtitle:
                'Pengaturan ini tersimpan di perangkat dan tidak mengubah data KPI.',
          ),
          const SizedBox(height: AppTheme.spaceXl),
          OpsCard(
            padding: EdgeInsets.zero,
            child: Column(
              children: [
                ListTile(
                  leading: Icon(
                    themeModeIcon(preferences.themeMode),
                    color: AppTheme.primaryBright,
                  ),
                  title: const Text(
                    'Mode tampilan',
                    style: TextStyle(fontWeight: FontWeight.w700),
                  ),
                  subtitle: Text(themeModeLabel(preferences.themeMode)),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () => _pickTheme(context),
                ),
                const Divider(height: 1),
                SwitchListTile.adaptive(
                  secondary: const Icon(Icons.motion_photos_on_rounded),
                  title: const Text(
                    'Kurangi animasi',
                    style: TextStyle(fontWeight: FontWeight.w700),
                  ),
                  subtitle: const Text(
                    'Kurangi transisi dan gerakan dekoratif pada aplikasi.',
                  ),
                  value: preferences.reduceMotion,
                  onChanged: preferences.setReduceMotion,
                ),
              ],
            ),
          ),
          const SizedBox(height: AppTheme.spaceXl),
          OpsCard(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.info_outline_rounded, color: AppTheme.primaryBright),
                const SizedBox(width: AppTheme.spaceMd),
                Expanded(
                  child: Text(
                    'Mode mengikuti sistem akan menyesuaikan tampilan dengan pengaturan perangkat. Pilihan ini hanya berlaku di perangkat yang sedang digunakan.',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: AppTheme.textMuted,
                      height: 1.45,
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: AppTheme.spaceXl),
          OutlinedButton.icon(
            onPressed: () => _reset(context),
            icon: const Icon(Icons.restart_alt_rounded),
            label: const Text('Reset preferensi tampilan'),
          ),
        ],
      ),
    );
  }
}
