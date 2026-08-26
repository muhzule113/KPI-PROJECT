import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';
import '../../core/auth/auth_provider.dart';
import '../approval/manager_approval_screen.dart';
import '../imports/cashier_upload_screen.dart';
import '../my_kpi/my_kpi_screen.dart';
import '../notifications/notification_screen.dart';
import '../operational/tickets_list_screen.dart';
import '../operational/sparepart_screen.dart';
import '../profile/profile_screen.dart';
import '../review/supervisor_queue_screen.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  int _currentIndex = 0;
  bool _isLoading = true;
  Map<String, dynamic>? _dashboardData;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadDashboard();
  }

  Future<void> _loadDashboard() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final res = await ApiService.get('/dashboard');
      setState(() {
        _dashboardData = res['data'];
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _errorMessage = e.toString().replaceAll('Exception: ', '');
        _isLoading = false;
      });
    }
  }

  @override
    Widget build(BuildContext context) {
      final auth = context.watch<AuthProvider>();
      final employee = auth.employee;

      // Bottom nav utama — max 5 item (rule bottom-nav-limit).
      // Item sekunder (Review/Approval/Laporan Kasir) dipindah ke overflow menu di AppBar.
      final List<Widget> tabs = [
        _buildHomeTab(auth, employee),
        if (auth.isTeknisi || auth.isCs || auth.isGudang) const TicketsListScreen(),
        if (auth.isGudang) const SparepartScreen(),
        const MyKpiScreen(),
        const ProfileScreen(),
      ];

      final List<BottomNavigationBarItem> navItems = [
        const BottomNavigationBarItem(icon: Icon(Icons.dashboard_rounded), label: 'Beranda'),
        if (auth.isTeknisi || auth.isCs || auth.isGudang)
          const BottomNavigationBarItem(icon: Icon(Icons.build_circle_rounded), label: 'Tiket Servis'),
        if (auth.isGudang)
          const BottomNavigationBarItem(icon: Icon(Icons.inventory_2_rounded), label: 'Inventory'),
        const BottomNavigationBarItem(icon: Icon(Icons.assignment_turned_in_rounded), label: 'KPI Saya'),
        const BottomNavigationBarItem(icon: Icon(Icons.person_rounded), label: 'Profil'),
      ];

      // Pastikan index aktif valid terhadap daftar yang berubah per role
      if (_currentIndex >= tabs.length) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) setState(() => _currentIndex = 0);
        });
      }

      return Scaffold(
        appBar: AppBar(
          title: Row(
            children: [
              Container(
                padding: const EdgeInsets.all(6),
                decoration: BoxDecoration(
                  color: AppTheme.primary.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Icon(Icons.assessment_rounded, color: AppTheme.primary, size: 20),
              ),
              const SizedBox(width: 8),
              const Text('Sistem KPI Toko HP'),
            ],
          ),
          actions: [
            if (auth.isSupervisor || auth.isManager || auth.isKasir)
              PopupMenuButton<String>(
                icon: const Icon(Icons.more_vert_rounded),
                tooltip: 'Menu lainnya',
                onSelected: _openSecondaryScreen,
                itemBuilder: (context) => [
                  if (auth.isSupervisor)
                    PopupMenuItem(value: 'supervisor', child: _menuItem(Icons.rate_review_rounded, 'Review Tim')),
                  if (auth.isManager)
                    PopupMenuItem(value: 'manager', child: _menuItem(Icons.verified_user_rounded, 'Approval')),
                  if (auth.isKasir)
                    PopupMenuItem(value: 'cashier', child: _menuItem(Icons.upload_file_rounded, 'Laporan Kasir')),
                ],
              ),
            IconButton(
              icon: const Icon(Icons.notifications_none_rounded),
              tooltip: 'Notifikasi',
              onPressed: () {
                Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const NotificationScreen()),
                );
              },
            ),
          ],
        ),
        body: _currentIndex < tabs.length ? tabs[_currentIndex] : tabs[0],
        bottomNavigationBar: BottomNavigationBar(
          currentIndex: _currentIndex >= navItems.length ? 0 : _currentIndex,
          onTap: (index) => setState(() => _currentIndex = index),
          type: BottomNavigationBarType.fixed,
          selectedItemColor: AppTheme.primary,
          unselectedItemColor: AppTheme.textMuted,
          backgroundColor: Colors.white,
          elevation: 8,
          selectedLabelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
          unselectedLabelStyle: const TextStyle(fontSize: 12),
          items: navItems,
        ),
      );
    }

    void _openSecondaryScreen(String value) {
      switch (value) {
        case 'supervisor':
          _pushScreen(const SupervisorQueueScreen());
          break;
        case 'manager':
          _pushScreen(const ManagerApprovalScreen());
          break;
        case 'cashier':
          _pushScreen(const CashierUploadScreen());
          break;
      }
    }

    void _pushScreen(Widget screen) {
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    }

    Widget _menuItem(IconData icon, String label) {
      return Row(
        children: [
          Icon(icon, size: 20, color: AppTheme.textInk),
          const SizedBox(width: 10),
          Text(label, style: const TextStyle(fontSize: 14)),
        ],
      );
    }

  Widget _buildHomeTab(AuthProvider auth, Map<String, dynamic>? employee) {
    if (_isLoading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_errorMessage != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.error_outline_rounded, color: AppTheme.statusDanger, size: 48),
              const SizedBox(height: 12),
              Text(_errorMessage!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              ElevatedButton(onPressed: _loadDashboard, child: const Text('Coba Lagi')),
            ],
          ),
        ),
      );
    }

    final activePeriod = _dashboardData?['active_period'];
    final myKpi = _dashboardData?['my_kpi'];
    final spvQueue = _dashboardData?['supervisor_queue'];
    final execOverview = _dashboardData?['executive_overview'];

    return RefreshIndicator(
      onRefresh: _loadDashboard,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Greeting Banner
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(2),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(color: AppTheme.primary.withValues(alpha: 0.4), width: 2),
                  ),
                  child: CircleAvatar(
                    radius: 22,
                    backgroundColor: AppTheme.primary.withValues(alpha: 0.12),
                    child: Text(
                      (employee?['name'] ?? 'K').toString().isNotEmpty
                          ? (employee?['name'] ?? 'K').toString()[0].toUpperCase()
                          : 'K',
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppTheme.primary),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Halo, ${employee?['name'] ?? 'Karyawan'}',
                        style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: AppTheme.textInk),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${employee?['position'] ?? 'Staff'} • ${employee?['branch'] ?? 'Cabang Pusat'}',
                        style: const TextStyle(fontSize: 13, color: AppTheme.textMuted),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),

            // Active Period Card
            if (activePeriod != null)
              Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [Color(0xFF1E293B), Color(0xFF0F172A)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(16),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.12),
                      blurRadius: 10,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                              decoration: BoxDecoration(
                                color: AppTheme.primaryLime,
                                borderRadius: BorderRadius.circular(6),
                              ),
                              child: const Text(
                                'PERIODE AKTIF',
                                style: TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w800,
                                  color: Color(0xFF14140F),
                                ),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Text(
                              activePeriod['name'],
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                                fontSize: 16,
                              ),
                            ),
                          ],
                        ),
                        const Icon(Icons.calendar_month_rounded, color: Colors.white70),
                      ],
                    ),
                    const SizedBox(height: 16),
                    const Text(
                      'Batas Waktu Pengisian:',
                      style: TextStyle(color: Colors.white60, fontSize: 12),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      activePeriod['submission_deadline'].toString().split('T')[0],
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 18,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),

            const SizedBox(height: 24),

            // My KPI Progress Section
            if (myKpi != null) ...[
              const Text(
                'Progress KPI Anda',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
              ),
              const SizedBox(height: 12),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(18),
                  child: Column(
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${myKpi['filled_items']} dari ${myKpi['total_items']} Indikator Terisi',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                'Status: ${_formatStatus(myKpi['status'])}',
                                style: TextStyle(
                                  color: _getStatusColor(myKpi['status']),
                                  fontWeight: FontWeight.w600,
                                  fontSize: 13,
                                ),
                              ),
                            ],
                          ),
                          Text(
                            '${(myKpi['progress_percentage'] as num).toInt()}%',
                            style: const TextStyle(
                              fontSize: 24,
                              fontWeight: FontWeight.w800,
                              color: AppTheme.primary,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(8),
                        child: Stack(
                          children: [
                            Container(
                              height: 10,
                              color: AppTheme.border,
                            ),
                            AnimatedContainer(
                              duration: MediaQuery.disableAnimationsOf(context)
                                  ? Duration.zero
                                  : const Duration(milliseconds: 250),
                              curve: Curves.easeOut,
                              height: 10,
                              width: MediaQuery.sizeOf(context).width * ((myKpi['progress_percentage'] as num) / 100.0),
                              decoration: BoxDecoration(
                                gradient: const LinearGradient(
                                  colors: [AppTheme.primary, Color(0xFF059669)],
                                  begin: Alignment.centerLeft,
                                  end: Alignment.centerRight,
                                ),
                                borderRadius: BorderRadius.circular(8),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 8),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            '${(myKpi['progress_percentage'] as num).toInt()}% terisi',
                            style: const TextStyle(fontSize: 12, color: AppTheme.textMuted, fontWeight: FontWeight.w600),
                          ),
                          Text(
                            '${myKpi['filled_items']} / ${myKpi['total_items']} indikator',
                            style: const TextStyle(fontSize: 12, color: AppTheme.textMuted),
                          ),
                        ],
                      ),
                      if (myKpi['final_score'] != null) ...[
                        const SizedBox(height: 16),
                        const Divider(),
                        const SizedBox(height: 8),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text('Skor Akhir:', style: TextStyle(fontWeight: FontWeight.w600)),
                            Text(
                              '${myKpi['final_score']} (${myKpi['rating_label']})',
                              style: const TextStyle(
                                color: AppTheme.primary,
                                fontWeight: FontWeight.bold,
                                fontSize: 16,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ],

            // Supervisor Queue Summary
            if (spvQueue != null) ...[
              const SizedBox(height: 24),
              const Text(
                'Antrean Review Tim Supervisor',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  _metricCard('Perlu Review', '${spvQueue['submitted_count']}', AppTheme.statusSubmitted),
                  const SizedBox(width: 12),
                  _metricCard('Dalam Revisi', '${spvQueue['revision_count']}', AppTheme.statusRevision),
                  const SizedBox(width: 12),
                  _metricCard('Terverifikasi', '${spvQueue['verified_count']}', AppTheme.statusApproved),
                ],
              ),
            ],

            // Executive Summary
            if (execOverview != null) ...[
              const SizedBox(height: 24),
              const Text(
                'Ringkasan Eksekutif Manager',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  _metricCard('Kelengkapan', '${execOverview['completion_rate']}%', AppTheme.primary),
                  const SizedBox(width: 12),
                  _metricCard('Menunggu Approval', '${execOverview['pending_approval_count']}', AppTheme.statusRevision),
                  const SizedBox(width: 12),
                  _metricCard('Rata-rata Skor', '${execOverview['average_score']}', AppTheme.statusSubmitted),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _metricCard(String label, String value, Color color) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AppTheme.border),
        ),
        child: Column(
          children: [
            Text(
              value,
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: color),
            ),
            const SizedBox(height: 4),
            Text(
              label,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12, color: AppTheme.textMuted),
            ),
          ],
        ),
      ),
    );
  }

  String _formatStatus(String status) {
    switch (status) {
      case 'draft': return 'Draft (Menunggu Data)';
      case 'submitted': return 'Menunggu Review';
      case 'under_review': return 'Sedang Direview';
      case 'revision_required': return 'Perlu Revisi';
      case 'verified': return 'Terverifikasi';
      case 'pending_approval': return 'Menunggu Approval';
      case 'approved': return 'Disetujui Final';
      case 'locked': return 'Terkunci (Final)';
      default: return status;
    }
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'draft': return AppTheme.statusDraft;
      case 'submitted': return AppTheme.statusSubmitted;
      case 'under_review': return AppTheme.statusUnderReview;
      case 'revision_required': return AppTheme.statusRevision;
      case 'verified': return AppTheme.statusVerified;
      case 'approved':
      case 'locked': return AppTheme.statusApproved;
      default: return AppTheme.textMuted;
    }
  }
}
