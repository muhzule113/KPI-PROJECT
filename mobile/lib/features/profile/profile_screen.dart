import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../app/theme/app_theme.dart';
import '../../core/auth/auth_provider.dart';
import '../auth/login_screen.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final user = auth.user;
    final emp = auth.employee;

    return Scaffold(
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Profile header
          Center(
            child: Column(
              children: [
                CircleAvatar(
                  radius: 36,
                  backgroundColor: AppTheme.primary.withValues(alpha: 0.15),
                  child: Text(
                    (emp?['name'] ?? user?['name'] ?? 'U')[0].toUpperCase(),
                    style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold, color: AppTheme.primary),
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  emp?['name'] ?? user?['name'] ?? 'User',
                  style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: AppTheme.textInk),
                ),
                const SizedBox(height: 4),
                Text(
                  user?['email'] ?? '',
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // Details Card
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  _infoRow('NIK Karyawan', emp?['employee_number'] ?? '-'),
                  const Divider(height: 20),
                  _infoRow('Jabatan', emp?['position'] ?? '-'),
                  const Divider(height: 20),
                  _infoRow('Cabang Toko', emp?['branch'] ?? '-'),
                  const Divider(height: 20),
                  _infoRow('Status', emp?['status'] == 'active' ? 'Aktif' : (emp?['status'] ?? '-')),
                ],
              ),
            ),
          ),

          const SizedBox(height: 24),
          const Text(
            'Pengaturan & Akun',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppTheme.textMuted),
          ),
          const SizedBox(height: 8),

          Card(
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.info_outline_rounded, color: AppTheme.textMuted),
                  title: const Text('Tentang Sistem KPI', style: TextStyle(fontSize: 14)),
                  trailing: const Text('v1.0.0', style: TextStyle(color: AppTheme.textMuted, fontSize: 12)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.lock_reset_rounded, color: AppTheme.textMuted),
                  title: const Text('Ubah Kata Sandi', style: TextStyle(fontSize: 14)),
                  trailing: const Icon(Icons.arrow_forward_ios_rounded, size: 14, color: AppTheme.textMuted),
                  onTap: () {},
                ),
              ],
            ),
          ),

          const SizedBox(height: 24),
          ElevatedButton.icon(
            icon: const Icon(Icons.logout_rounded),
            label: const Text('Keluar dari Akun (Logout)'),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.statusDanger,
            ),
            onPressed: () async {
              await auth.logout();
              if (context.mounted) {
                Navigator.of(context).pushReplacement(
                  MaterialPageRoute(builder: (_) => const LoginScreen()),
                );
              }
            },
          ),
          const SizedBox(height: 30),
        ],
      ),
    );
  }

  Widget _infoRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(color: AppTheme.textMuted, fontSize: 13)),
        Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppTheme.textInk)),
      ],
    );
  }
}
