import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../app/theme/app_theme.dart';
import '../../core/auth/auth_provider.dart';
import '../dashboard/dashboard_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _emailController = TextEditingController(text: 'teknisi@toko.com');
  final _passwordController = TextEditingController(text: 'password');
  bool _obscurePassword = true;

  void _login() async {
    final auth = context.read<AuthProvider>();
    try {
      await auth.login(_emailController.text.trim(), _passwordController.text);
      if (mounted) {
        Navigator.of(context).pushReplacement(
          MaterialPageRoute(builder: (_) => const DashboardScreen()),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.toString().replaceAll('Exception: ', '')),
            backgroundColor: AppTheme.statusDanger,
          ),
        );
      }
    }
  }

  void _quickFill(String email) {
    setState(() {
      _emailController.text = email;
      _passwordController.text = 'password';
    });
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Brand Header
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(
                      colors: [AppTheme.primary, Color(0xFF059669)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: [
                      BoxShadow(
                        color: AppTheme.primary.withValues(alpha: 0.3),
                        blurRadius: 16,
                        offset: const Offset(0, 6),
                      ),
                    ],
                  ),
                  child: const Icon(
                    Icons.assessment_rounded,
                    color: AppTheme.surface,
                    size: 36,
                  ),
                ),
                const SizedBox(height: 20),
                const Text(
                  'Sistem KPI Toko HP',
                  style: TextStyle(
                    fontSize: 26,
                    fontWeight: FontWeight.w800,
                    color: AppTheme.textInk,
                    letterSpacing: -0.5,
                  ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Penilaian kinerja objektif, transparan & terukur.',
                  style: TextStyle(
                    fontSize: 14,
                    color: AppTheme.textMuted,
                  ),
                ),
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: AppTheme.statusRevision.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: const Text(
                    'Mode Demo — gunakan akun di bawah',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.statusRevision),
                  ),
                ),
                const SizedBox(height: 28),

                // Form
                TextField(
                  controller: _emailController,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(
                    labelText: 'Alamat Email',
                    prefixIcon: Icon(Icons.email_outlined),
                  ),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _passwordController,
                  obscureText: _obscurePassword,
                  decoration: InputDecoration(
                    labelText: 'Kata Sandi',
                    prefixIcon: const Icon(Icons.lock_outline),
                    suffixIcon: IconButton(
                      icon: Icon(_obscurePassword ? Icons.visibility_off : Icons.visibility),
                      tooltip: _obscurePassword ? 'Tampilkan kata sandi' : 'Sembunyikan kata sandi',
                      onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                    ),
                  ),
                ),
                const SizedBox(height: 24),

                ElevatedButton(
                  onPressed: auth.isLoading ? null : _login,
                  child: auth.isLoading
                      ? const SizedBox(
                          height: 22,
                          width: 22,
                          child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                        )
                      : const Text('Masuk ke Akun'),
                ),

                const SizedBox(height: 32),
                const Divider(),
                const SizedBox(height: 12),
                const Text(
                  'Pilih Akun Demo Cepat:',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.textMuted),
                ),
                const SizedBox(height: 12),

                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _demoChip('Teknisi', 'teknisi@toko.com', AppTheme.primary),
                    _demoChip('Supervisor', 'supervisor@toko.com', AppTheme.statusUnderReview),
                    _demoChip('Manager', 'manager@toko.com', AppTheme.statusSubmitted),
                    _demoChip('Kasir', 'kasir@toko.com', AppTheme.statusVerified),
                    _demoChip('CS', 'cs@toko.com', AppTheme.statusRevision),
                    _demoChip('Admin', 'admin_staff@toko.com', AppTheme.textMuted),
                    _demoChip('Gudang', 'gudang@toko.com', Colors.teal),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _demoChip(String label, String email, Color color) {
    return ActionChip(
      avatar: CircleAvatar(
        radius: 6,
        backgroundColor: color,
      ),
      label: Text(label, style: const TextStyle(fontSize: 12)),
      backgroundColor: AppTheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: const BorderSide(color: AppTheme.border),
      ),
      onPressed: () => _quickFill(email),
    );
  }
}
