import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/auth/auth_provider.dart';
import '../dashboard/dashboard_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

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
    final reducedMotion = MediaQuery.disableAnimationsOf(context);

    return Scaffold(
      body: Stack(
        children: [
          // Quiet ambient light, clipped so it never creates scroll overflow.
          Positioned(
            top: -120,
            right: -80,
            child: IgnorePointer(
              child: Container(
                width: 300,
                height: 300,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: RadialGradient(
                    colors: [
                      AppTheme.primary.withValues(alpha: 0.26),
                      AppTheme.primary.withValues(alpha: 0),
                    ],
                  ),
                ),
              ),
            ),
          ),
          SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(24, 24, 24, 32),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 440),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      OpsReveal(
                        child: Row(
                          children: [
                            Container(
                              width: 58,
                              height: 58,
                              decoration: BoxDecoration(
                                gradient: LinearGradient(
                                  colors: [
                                    AppTheme.primaryBright,
                                    AppTheme.primaryPressed,
                                  ],
                                  begin: Alignment.topLeft,
                                  end: Alignment.bottomRight,
                                ),
                                borderRadius: BorderRadius.circular(20),
                                boxShadow: [
                                  BoxShadow(
                                    color: AppTheme.primaryBright.withValues(
                                      alpha: 0.2,
                                    ),
                                    blurRadius: 22,
                                    offset: const Offset(0, 10),
                                  ),
                                ],
                              ),
                              child: const Icon(
                                Icons.monitor_heart_rounded,
                                color: Colors.white,
                                size: 30,
                              ),
                            ),
                            const SizedBox(width: 14),
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'KPI OPS',
                                  style: TextStyle(
                                    fontSize: 18,
                                    fontWeight: FontWeight.w900,
                                    letterSpacing: 1.6,
                                  ),
                                ),
                                SizedBox(height: 2),
                                Text(
                                  'Performance command center',
                                  style: TextStyle(
                                    fontSize: 12,
                                    color: AppTheme.textMuted,
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 42),
                      OpsReveal(
                        delay: reducedMotion
                            ? Duration.zero
                            : const Duration(milliseconds: 70),
                        child: Text(
                          'Masuk ke ruang kerja Anda.',
                          style: Theme.of(context).textTheme.headlineMedium
                              ?.copyWith(fontSize: 30, height: 1.08),
                        ),
                      ),
                      const SizedBox(height: 10),
                      OpsReveal(
                        delay: reducedMotion
                            ? Duration.zero
                            : const Duration(milliseconds: 120),
                        child: Text(
                          'Pantau pekerjaan, progres, dan hasil KPI dari satu tempat.',
                          style: TextStyle(
                            fontSize: 14,
                            color: AppTheme.textMuted,
                            height: 1.5,
                          ),
                        ),
                      ),
                      const SizedBox(height: 28),
                      OpsReveal(
                        delay: reducedMotion
                            ? Duration.zero
                            : const Duration(milliseconds: 170),
                        child: OpsCard(
                          emphasized: true,
                          padding: const EdgeInsets.all(20),
                          child: Column(
                            children: [
                              TextField(
                                controller: _emailController,
                                keyboardType: TextInputType.emailAddress,
                                textInputAction: TextInputAction.next,
                                decoration: const InputDecoration(
                                  labelText: 'Alamat email',
                                  prefixIcon: Icon(
                                    Icons.alternate_email_rounded,
                                  ),
                                ),
                              ),
                              const SizedBox(height: 14),
                              TextField(
                                controller: _passwordController,
                                obscureText: _obscurePassword,
                                textInputAction: TextInputAction.done,
                                onSubmitted: (_) =>
                                    auth.isLoading ? null : _login(),
                                decoration: InputDecoration(
                                  labelText: 'Kata sandi',
                                  prefixIcon: const Icon(Icons.key_rounded),
                                  suffixIcon: IconButton(
                                    icon: Icon(
                                      _obscurePassword
                                          ? Icons.visibility_off_rounded
                                          : Icons.visibility_rounded,
                                    ),
                                    tooltip: _obscurePassword
                                        ? 'Tampilkan kata sandi'
                                        : 'Sembunyikan kata sandi',
                                    onPressed: () => setState(
                                      () =>
                                          _obscurePassword = !_obscurePassword,
                                    ),
                                  ),
                                ),
                              ),
                              const SizedBox(height: 20),
                              SizedBox(
                                width: double.infinity,
                                child: ElevatedButton.icon(
                                  onPressed: auth.isLoading ? null : _login,
                                  icon: auth.isLoading
                                      ? const SizedBox(
                                          height: 19,
                                          width: 19,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2.3,
                                            color: Colors.white,
                                          ),
                                        )
                                      : const Icon(Icons.arrow_forward_rounded),
                                  label: Text(
                                    auth.isLoading
                                        ? 'Memeriksa akses...'
                                        : 'Masuk ke ruang kerja',
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      if (!kReleaseMode) ...[
                        const SizedBox(height: 20),
                        OpsReveal(
                          delay: reducedMotion
                              ? Duration.zero
                              : const Duration(milliseconds: 230),
                          child: Row(
                            children: [
                              Container(
                                width: 6,
                                height: 6,
                                decoration: BoxDecoration(
                                  color: AppTheme.primaryBright,
                                  shape: BoxShape.circle,
                                ),
                              ),
                              const SizedBox(width: 8),
                              Text(
                                'Mode demo aktif',
                                style: TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w700,
                                  color: AppTheme.primaryBright,
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(child: Divider(color: AppTheme.border)),
                            ],
                          ),
                        ),
                        const SizedBox(height: 12),
                        OpsReveal(
                          delay: reducedMotion
                              ? Duration.zero
                              : const Duration(milliseconds: 280),
                          child: Text(
                            'Pilih akses cepat untuk mencoba workflow per role.',
                            style: TextStyle(
                              fontSize: 12,
                              color: AppTheme.textMuted,
                            ),
                          ),
                        ),
                        const SizedBox(height: 12),
                        OpsReveal(
                          delay: reducedMotion
                              ? Duration.zero
                              : const Duration(milliseconds: 330),
                          child: Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              _demoChip(
                                'Supervisor',
                                'supervisor@toko.com',
                                AppTheme.statusUnderReview,
                                Icons.rate_review_rounded,
                              ),
                              _demoChip(
                                'Manager',
                                'manager@toko.com',
                                AppTheme.statusSubmitted,
                                Icons.verified_user_rounded,
                              ),
                              _demoChip(
                                'Kasir',
                                'kasir@toko.com',
                                AppTheme.statusVerified,
                                Icons.upload_file_rounded,
                              ),
                              _demoChip(
                                'CS',
                                'cs@toko.com',
                                AppTheme.statusRevision,
                                Icons.support_agent_rounded,
                              ),
                              _demoChip(
                                'Admin',
                                'admin_staff@toko.com',
                                AppTheme.textMuted,
                                Icons.admin_panel_settings_rounded,
                              ),
                              _demoChip(
                                'Gudang',
                                'gudang@toko.com',
                                AppTheme.primaryBright,
                                Icons.inventory_2_rounded,
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 26),
                      ],
                      Center(
                        child: Text(
                          'Data demo lokal · KPI OPS v1.0',
                          style: TextStyle(
                            fontSize: 11,
                            color: AppTheme.textMuted,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _demoChip(String label, String email, Color color, IconData icon) {
    return ActionChip(
      avatar: Icon(icon, size: 16, color: color),
      label: Text(label),
      backgroundColor: AppTheme.surfaceElevated,
      side: BorderSide(color: color.withValues(alpha: 0.3)),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      onPressed: () => _quickFill(email),
    );
  }
}
