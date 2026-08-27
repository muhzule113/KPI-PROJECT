import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';
import '../../core/auth/auth_provider.dart';
import '../auth/login_screen.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  Map<String, dynamic>? _user;
  Map<String, dynamic>? _employee;

  @override
  void initState() {
    super.initState();
    _loadProfile();
  }

  Future<void> _loadProfile() async {
    try {
      final res = await ApiService.get('/auth/me');
      if (!mounted) return;
      setState(() {
        _user = res['data']?['user'];
        _employee = res['data']?['employee'];
      });
    } catch (_) {
      // Gagal fetch — tetap pakai data dari login
    }
  }

  Future<void> _openChangePassword() async {
    final currentController = TextEditingController();
    final newController = TextEditingController();
    final confirmController = TextEditingController();
    var obscureCurrent = true;
    var obscureNew = true;
    var obscureConfirm = true;

    final submitted = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (sheetContext) => StatefulBuilder(
        builder: (sheetContext, setSheetState) => OpsFormSheet(
          eyebrow: 'Keamanan akun',
          title: 'Ubah kata sandi',
          subtitle:
              'Gunakan kata sandi baru yang mudah kamu ingat, tetapi sulit ditebak.',
          footer: ElevatedButton.icon(
            onPressed: () => Navigator.of(sheetContext).pop(true),
            icon: const Icon(Icons.check_rounded),
            label: const Text('Simpan kata sandi'),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextField(
                controller: currentController,
                obscureText: obscureCurrent,
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(
                  labelText: 'Kata sandi saat ini',
                  prefixIcon: const Icon(Icons.lock_outline_rounded),
                  suffixIcon: IconButton(
                    tooltip: obscureCurrent
                        ? 'Tampilkan kata sandi'
                        : 'Sembunyikan kata sandi',
                    onPressed: () =>
                        setSheetState(() => obscureCurrent = !obscureCurrent),
                    icon: Icon(
                      obscureCurrent
                          ? Icons.visibility_rounded
                          : Icons.visibility_off_rounded,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: AppTheme.spaceMd),
              TextField(
                controller: newController,
                obscureText: obscureNew,
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(
                  labelText: 'Kata sandi baru',
                  helperText: 'Minimal 8 karakter',
                  prefixIcon: const Icon(Icons.key_rounded),
                  suffixIcon: IconButton(
                    tooltip: obscureNew
                        ? 'Tampilkan kata sandi'
                        : 'Sembunyikan kata sandi',
                    onPressed: () =>
                        setSheetState(() => obscureNew = !obscureNew),
                    icon: Icon(
                      obscureNew
                          ? Icons.visibility_rounded
                          : Icons.visibility_off_rounded,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: AppTheme.spaceMd),
              TextField(
                controller: confirmController,
                obscureText: obscureConfirm,
                textInputAction: TextInputAction.done,
                decoration: InputDecoration(
                  labelText: 'Ulangi kata sandi baru',
                  prefixIcon: const Icon(Icons.verified_user_outlined),
                  suffixIcon: IconButton(
                    tooltip: obscureConfirm
                        ? 'Tampilkan kata sandi'
                        : 'Sembunyikan kata sandi',
                    onPressed: () =>
                        setSheetState(() => obscureConfirm = !obscureConfirm),
                    icon: Icon(
                      obscureConfirm
                          ? Icons.visibility_rounded
                          : Icons.visibility_off_rounded,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );

    final currentPassword = currentController.text;
    final newPassword = newController.text;
    final confirmPassword = confirmController.text;
    currentController.dispose();
    newController.dispose();
    confirmController.dispose();

    if (submitted != true) return;
    if (!mounted) return;

    if (newPassword.trim().length < 8) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Kata sandi baru minimal 8 karakter.'),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
      return;
    }
    if (newPassword != confirmPassword) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Konfirmasi kata sandi tidak cocok.'),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
      return;
    }

    try {
      final res = await ApiService.post('/auth/change-password', {
        'current_password': currentPassword,
        'new_password': newPassword,
        'new_password_confirmation': confirmPassword,
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Kata sandi berhasil diperbarui.'),
          backgroundColor: AppTheme.primary,
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString().replaceAll('Exception: ', '')),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final user = _user ?? auth.user;
    final emp = _employee ?? auth.employee;
    final name = emp?['name'] ?? user?['name'] ?? 'User';

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _loadProfile,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
          children: [
            // Profile header
            OpsReveal(
              child: Center(
                child: Column(
                  children: [
                    CircleAvatar(
                      radius: 36,
                      backgroundColor: AppTheme.primary.withValues(alpha: 0.15),
                      child: Text(
                        name.isNotEmpty ? name[0].toUpperCase() : 'U',
                        style: const TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.bold,
                          color: AppTheme.primary,
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      name,
                      style: const TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.bold,
                        color: AppTheme.textInk,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      user?['email'] ?? '',
                      style: const TextStyle(
                        color: AppTheme.textMuted,
                        fontSize: 13,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),

            // Details Card
            OpsReveal(
              delay: const Duration(milliseconds: 80),
              child: OpsCard(
                padding: EdgeInsets.zero,
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
                      _infoRow(
                        'Status',
                        emp?['status'] == 'active'
                            ? 'Aktif'
                            : (emp?['status'] ?? '-'),
                      ),
                    ],
                  ),
                ),
              ),
            ),

            const SizedBox(height: 24),
            const Text(
              'Pengaturan & Akun',
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 14,
                color: AppTheme.textMuted,
              ),
            ),
            const SizedBox(height: 8),

            OpsReveal(
              delay: const Duration(milliseconds: 140),
              child: OpsCard(
                padding: EdgeInsets.zero,
                child: Column(
                  children: [
                    const ListTile(
                      leading: Icon(
                        Icons.info_outline_rounded,
                        color: AppTheme.textMuted,
                      ),
                      title: Text(
                        'Tentang Sistem KPI',
                        style: TextStyle(fontSize: 14),
                      ),
                      trailing: Text(
                        'v1.0.0',
                        style: TextStyle(
                          color: AppTheme.textMuted,
                          fontSize: 12,
                        ),
                      ),
                    ),
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(
                        Icons.lock_reset_rounded,
                        color: AppTheme.textMuted,
                      ),
                      title: const Text(
                        'Ubah Kata Sandi',
                        style: TextStyle(fontSize: 14),
                      ),
                      trailing: const Icon(
                        Icons.arrow_forward_ios_rounded,
                        size: 14,
                        color: AppTheme.textMuted,
                      ),
                      onTap: _openChangePassword,
                    ),
                  ],
                ),
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
      ),
    );
  }

  Widget _infoRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
        ),
        Text(
          value,
          style: const TextStyle(
            fontWeight: FontWeight.bold,
            fontSize: 13,
            color: AppTheme.textInk,
          ),
        ),
      ],
    );
  }
}
