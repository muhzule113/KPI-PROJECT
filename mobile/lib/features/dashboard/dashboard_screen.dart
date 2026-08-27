import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';
import '../../core/auth/auth_provider.dart';
import '../approval/manager_approval_screen.dart';
import '../imports/cashier_upload_screen.dart';
import '../operational/create_ticket_screen.dart';
import '../operational/sparepart_screen.dart';
import '../../app/widgets/kpi_ui.dart';
import '../my_kpi/my_kpi_screen.dart';
import '../notifications/notification_screen.dart';
import '../operational/tickets_list_screen.dart';
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
    final hasOperations = auth.isTeknisi || auth.isCs || auth.isGudang;
    final List<Widget> tabs = [
      _buildHomeTab(auth, employee),
      if (hasOperations) const TicketsListScreen(),
      const MyKpiScreen(),
      const ProfileScreen(),
    ];

    // Pastikan index aktif valid terhadap daftar yang berubah per role
    if (_currentIndex >= tabs.length) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) setState(() => _currentIndex = 0);
      });
    }

    final destinations = <NavigationDestination>[
      const NavigationDestination(
        icon: Icon(Icons.dashboard_outlined),
        selectedIcon: Icon(Icons.dashboard_rounded),
        label: 'Beranda',
      ),
      if (hasOperations)
        const NavigationDestination(
          icon: Icon(Icons.handyman_outlined),
          selectedIcon: Icon(Icons.handyman_rounded),
          label: 'Operasional',
        ),
      const NavigationDestination(
        icon: Icon(Icons.insights_outlined),
        selectedIcon: Icon(Icons.insights_rounded),
        label: 'KPI Saya',
      ),
      const NavigationDestination(
        icon: Icon(Icons.person_outline_rounded),
        selectedIcon: Icon(Icons.person_rounded),
        label: 'Profil',
      ),
    ];

    return Scaffold(
      appBar: AppBar(
        titleSpacing: 20,
        title: Row(
          children: [
            Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [AppTheme.primaryBright, AppTheme.primary],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(12),
                boxShadow: [
                  BoxShadow(
                    color: AppTheme.primaryBright.withValues(alpha: 0.18),
                    blurRadius: 12,
                    offset: const Offset(0, 5),
                  ),
                ],
              ),
              child: const Icon(
                Icons.monitor_heart_rounded,
                color: Colors.white,
                size: 19,
              ),
            ),
            const SizedBox(width: 10),
            const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'KPI OPS',
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 1.1,
                  ),
                ),
                Text(
                  'Toko & Servis HP',
                  style: TextStyle(
                    fontSize: 10,
                    color: AppTheme.textMuted,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ],
            ),
          ],
        ),
        actions: [
          if (auth.isSupervisor || auth.isManager || auth.isKasir)
            PopupMenuButton<String>(
              icon: const Icon(Icons.more_horiz_rounded),
              tooltip: 'Menu lainnya',
              onSelected: _openSecondaryScreen,
              itemBuilder: (context) => [
                if (auth.isSupervisor)
                  PopupMenuItem(
                    value: 'supervisor',
                    child: _menuItem(Icons.rate_review_rounded, 'Review Tim'),
                  ),
                if (auth.isManager)
                  PopupMenuItem(
                    value: 'manager',
                    child: _menuItem(Icons.verified_user_rounded, 'Approval'),
                  ),
                if (auth.isKasir)
                  PopupMenuItem(
                    value: 'cashier',
                    child: _menuItem(
                      Icons.upload_file_rounded,
                      'Laporan Kasir',
                    ),
                  ),
              ],
            ),
          IconButton(
            icon: const Icon(Icons.notifications_none_rounded),
            tooltip: 'Notifikasi',
            onPressed: () => _pushScreen(const NotificationScreen()),
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: AnimatedSwitcher(
        duration: AppTheme.motion(context, AppTheme.motionStandard),
        switchInCurve: AppTheme.motionEnter,
        switchOutCurve: Curves.easeIn,
        child: KeyedSubtree(
          key: ValueKey(_currentIndex),
          child: _currentIndex < tabs.length ? tabs[_currentIndex] : tabs[0],
        ),
      ),
      bottomNavigationBar: OpsBottomNavigationBar(
        currentIndex: _currentIndex >= destinations.length ? 0 : _currentIndex,
        onTap: (index) => setState(() => _currentIndex = index),
        destinations: destinations,
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

  Widget _buildQuickActions(AuthProvider auth) {
    final actions = <Widget>[];

    if (auth.isCs) {
      actions.add(
        KpiQuickAction(
          icon: Icons.add_task_rounded,
          label: 'Buat tiket',
          onTap: () => _pushScreen(const CreateTicketScreen()),
        ),
      );
    }
    if (auth.isTeknisi || auth.isCs || auth.isGudang) {
      actions.add(
        KpiQuickAction(
          icon: Icons.build_circle_rounded,
          label: 'Tiket servis',
          onTap: () => setState(() => _currentIndex = 1),
        ),
      );
    }
    if (auth.isGudang) {
      actions.add(
        KpiQuickAction(
          icon: Icons.inventory_2_rounded,
          label: 'Inventory',
          color: AppTheme.statusVerified,
          onTap: () => _pushScreen(const SparepartScreen()),
        ),
      );
    }
    if (auth.isKasir) {
      actions.add(
        KpiQuickAction(
          icon: Icons.upload_file_rounded,
          label: 'Laporan kasir',
          color: AppTheme.statusSubmitted,
          onTap: () => _pushScreen(const CashierUploadScreen()),
        ),
      );
    }
    if (auth.isSupervisor) {
      actions.add(
        KpiQuickAction(
          icon: Icons.rate_review_rounded,
          label: 'Review tim',
          color: AppTheme.statusUnderReview,
          onTap: () => _pushScreen(const SupervisorQueueScreen()),
        ),
      );
    }
    if (auth.isManager) {
      actions.add(
        KpiQuickAction(
          icon: Icons.verified_user_rounded,
          label: 'Approval',
          color: AppTheme.statusApproved,
          onTap: () => _pushScreen(const ManagerApprovalScreen()),
        ),
      );
    }

    if (actions.isEmpty) {
      actions.add(
        KpiQuickAction(
          icon: Icons.assignment_turned_in_rounded,
          label: 'Lihat KPI saya',
          onTap: () => setState(() => _currentIndex = 2),
        ),
      );
    }

    return Wrap(
      spacing: 10,
      runSpacing: 10,
      children: actions.asMap().entries.map((entry) {
        return SizedBox(
          width: (MediaQuery.sizeOf(context).width - 50) / 2,
          child: OpsReveal(
            delay: Duration(milliseconds: 140 + (entry.key * 45)),
            child: entry.value,
          ),
        );
      }).toList(),
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
              const Icon(
                Icons.error_outline_rounded,
                color: AppTheme.statusDanger,
                size: 48,
              ),
              const SizedBox(height: 12),
              Text(_errorMessage!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              ElevatedButton(
                onPressed: _loadDashboard,
                child: const Text('Coba Lagi'),
              ),
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
        // Extra clearance for the floating bottom navigation on the shell.
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 120),
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
                    border: Border.all(
                      color: AppTheme.primary.withValues(alpha: 0.4),
                      width: 2,
                    ),
                  ),
                  child: CircleAvatar(
                    radius: 22,
                    backgroundColor: AppTheme.primary.withValues(alpha: 0.12),
                    child: Text(
                      (employee?['name'] ?? 'K').toString().isNotEmpty
                          ? (employee?['name'] ?? 'K')
                                .toString()[0]
                                .toUpperCase()
                          : 'K',
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: AppTheme.primary,
                      ),
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
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                          color: AppTheme.textInk,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${employee?['position'] ?? 'Staff'} • ${employee?['branch'] ?? 'Cabang Pusat'}',
                        style: const TextStyle(
                          fontSize: 13,
                          color: AppTheme.textMuted,
                        ),
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
              OpsReveal(
                delay: const Duration(milliseconds: 60),
                child: OpsHeroCard(
                  accent: AppTheme.primaryBright,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const KpiStatusPill(
                            label: 'PERIODE AKTIF',
                            color: AppTheme.primaryBright,
                            icon: Icons.radio_button_checked_rounded,
                          ),
                          Icon(
                            Icons.calendar_today_rounded,
                            color: AppTheme.primaryBright,
                            size: 20,
                          ),
                        ],
                      ),
                      const SizedBox(height: 20),
                      Text(
                        activePeriod['name'],
                        style: const TextStyle(
                          color: AppTheme.textInk,
                          fontWeight: FontWeight.w800,
                          fontSize: 22,
                          letterSpacing: -0.4,
                        ),
                      ),
                      const SizedBox(height: 6),
                      const Text(
                        'Batas waktu pengisian',
                        style: TextStyle(
                          color: AppTheme.textMuted,
                          fontSize: 12,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        activePeriod['submission_deadline'].toString().split(
                          'T',
                        )[0],
                        style: const TextStyle(
                          color: AppTheme.primaryBright,
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                ),
              ),

            const SizedBox(height: 24),

            // Quick actions — role-aware, mengikuti pola shortcut pada referensi.
            KpiSectionHeader(title: 'Akses cepat'),
            const SizedBox(height: 12),
            _buildQuickActions(auth),

            const SizedBox(height: 24),

            // My KPI Progress Section
            if (myKpi != null) ...[
              const Text(
                'Progress KPI Anda',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: AppTheme.textInk,
                ),
              ),
              const SizedBox(height: 12),
              OpsReveal(
                delay: const Duration(milliseconds: 150),
                child: OpsCard(
                  emphasized: true,
                  padding: EdgeInsets.zero,
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
                                  style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 15,
                                  ),
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
                        KpiProgressBar(
                          value: ((myKpi['progress_percentage'] as num) / 100.0)
                              .clamp(0.0, 1.0),
                        ),
                        const SizedBox(height: 8),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(
                              '${(myKpi['progress_percentage'] as num).toInt()}% terisi',
                              style: const TextStyle(
                                fontSize: 12,
                                color: AppTheme.textMuted,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            Text(
                              '${myKpi['filled_items']} / ${myKpi['total_items']} indikator',
                              style: const TextStyle(
                                fontSize: 12,
                                color: AppTheme.textMuted,
                              ),
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
                              const Text(
                                'Skor Akhir:',
                                style: TextStyle(fontWeight: FontWeight.w600),
                              ),
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
              ),
            ],

            // Supervisor Queue Summary
            if (spvQueue != null) ...[
              const SizedBox(height: 24),
              const Text(
                'Antrean Review Tim Supervisor',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: AppTheme.textInk,
                ),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  _metricCard(
                    'Perlu Review',
                    '${spvQueue['submitted_count']}',
                    AppTheme.statusSubmitted,
                  ),
                  const SizedBox(width: 12),
                  _metricCard(
                    'Dalam Revisi',
                    '${spvQueue['revision_count']}',
                    AppTheme.statusRevision,
                  ),
                  const SizedBox(width: 12),
                  _metricCard(
                    'Terverifikasi',
                    '${spvQueue['verified_count']}',
                    AppTheme.statusApproved,
                  ),
                ],
              ),
            ],

            // Executive Summary
            if (execOverview != null) ...[
              const SizedBox(height: 24),
              const Text(
                'Ringkasan Eksekutif Manager',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: AppTheme.textInk,
                ),
              ),
              const SizedBox(height: 12),
              LayoutBuilder(
                builder: (context, constraints) {
                  final gap = 12.0;
                  final columns = constraints.maxWidth < 390 ? 2 : 3;
                  final width =
                      (constraints.maxWidth - (gap * (columns - 1))) / columns;
                  final metrics = [
                    (
                      'Kelengkapan',
                      '${execOverview['completion_rate']}%',
                      AppTheme.primary,
                    ),
                    (
                      'Menunggu Approval',
                      '${execOverview['pending_approval_count']}',
                      AppTheme.statusRevision,
                    ),
                    (
                      'Rata-rata Skor',
                      '${execOverview['average_score']}',
                      AppTheme.statusSubmitted,
                    ),
                  ];
                  return Wrap(
                    spacing: gap,
                    runSpacing: gap,
                    children: metrics
                        .map(
                          (metric) => SizedBox(
                            width: width,
                            child: _metricCard(metric.$1, metric.$2, metric.$3),
                          ),
                        )
                        .toList(),
                  );
                },
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
          color: AppTheme.surface,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AppTheme.border),
        ),
        child: Column(
          children: [
            Text(
              value,
              style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w800,
                color: color,
              ),
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
      case 'draft':
        return 'Draft (Menunggu Data)';
      case 'submitted':
        return 'Menunggu Review';
      case 'under_review':
        return 'Sedang Direview';
      case 'revision_required':
        return 'Perlu Revisi';
      case 'verified':
        return 'Terverifikasi';
      case 'pending_approval':
        return 'Menunggu Approval';
      case 'approved':
        return 'Disetujui Final';
      case 'locked':
        return 'Terkunci (Final)';
      default:
        return status;
    }
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'draft':
        return AppTheme.statusDraft;
      case 'submitted':
        return AppTheme.statusSubmitted;
      case 'under_review':
        return AppTheme.statusUnderReview;
      case 'revision_required':
        return AppTheme.statusRevision;
      case 'verified':
        return AppTheme.statusVerified;
      case 'approved':
      case 'locked':
        return AppTheme.statusApproved;
      default:
        return AppTheme.textMuted;
    }
  }
}
