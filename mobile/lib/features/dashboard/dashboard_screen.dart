import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';
import '../../core/auth/auth_provider.dart';
import '../../core/realtime/realtime_service.dart';
import '../imports/cashier_upload_screen.dart';
import '../operational/create_ticket_screen.dart';
import '../operational/feedback_screen.dart';
import '../operational/resource_screen.dart';
import '../operational/sparepart_screen.dart';
import '../reports/kpi_report_screen.dart';
import '../../app/widgets/kpi_ui.dart';
import '../my_kpi/my_kpi_screen.dart';
import '../notifications/notification_screen.dart';
import '../operational/tickets_list_screen.dart';
import '../profile/profile_screen.dart';
import '../review/daily_assessment_screen.dart';
import '../team_tasks/team_tasks_screen.dart';

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
  Timer? _refreshTimer;
  bool _requestInFlight = false;
  StreamSubscription<void>? _realtimeSubscription;

  @override
  void initState() {
    super.initState();
    unawaited(_loadDashboard());
    _refreshTimer = Timer.periodic(
      const Duration(seconds: 30),
      (_) => unawaited(_loadDashboard(showLoading: false)),
    );
    _realtimeSubscription = RealtimeService.instance.kpiUpdates.listen(
      (_) => unawaited(_loadDashboard(showLoading: false)),
    );
  }

  Future<void> _loadDashboard({bool showLoading = true}) async {
    if (_requestInFlight) return;
    _requestInFlight = true;

    if (showLoading && mounted) {
      setState(() {
        _isLoading = true;
        _errorMessage = null;
      });
    }

    try {
      final res = await ApiService.get('/dashboard');
      if (mounted) {
        setState(() {
          _dashboardData = res['data'];
          _isLoading = false;
          _errorMessage = null;
        });
      }
    } catch (e) {
      if (mounted && (showLoading || _dashboardData == null)) {
        setState(() {
          _errorMessage = e.toString().replaceAll('Exception: ', '');
          _isLoading = false;
        });
      }
    } finally {
      _requestInFlight = false;
    }
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    _realtimeSubscription?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final employee = auth.employee;

    final hasMyKpi = auth.hasCapability('kpi.self.view');
    final List<Widget> tabs = [
      _buildHomeTab(auth, employee),
      SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 120),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Tugas', style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 16),
            _buildQuickActions(auth),
          ],
        ),
      ),
      if (hasMyKpi) const MyKpiScreen(),
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
      const NavigationDestination(
        icon: Icon(Icons.handyman_outlined),
        selectedIcon: Icon(Icons.handyman_rounded),
        label: 'Tugas',
      ),
      if (hasMyKpi)
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
                gradient: LinearGradient(
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
            Column(
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

  void _pushScreen(Widget screen) {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
  }

  Widget _buildQuickActions(AuthProvider auth) {
    final actions = <Widget>[];
    final hasMyKpi = auth.hasCapability('kpi.self.view');

    if (auth.isCs) {
      actions.add(
        KpiQuickAction(
          icon: Icons.add_task_rounded,
          label: 'Buat tiket',
          onTap: () => _pushScreen(const CreateTicketScreen()),
        ),
      );
    }
    if (auth.canViewTickets) {
      actions.add(
        KpiQuickAction(
          icon: Icons.build_circle_rounded,
          label: 'Tiket servis',
          onTap: () => _pushScreen(const TicketsListScreen()),
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
      actions.add(
        KpiQuickAction(
          icon: Icons.fact_check_rounded,
          label: 'Stock opname',
          color: AppTheme.statusSubmitted,
          onTap: () =>
              _pushScreen(const ResourceScreen(resource: 'stock-opnames')),
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
    if (auth.isSupervisor && !auth.isManager) {
      actions.add(
        KpiQuickAction(
          icon: Icons.assignment_turned_in_rounded,
          label: 'Penilaian Tim',
          color: AppTheme.statusUnderReview,
          onTap: () => _pushScreen(const TeamTasksScreen(manager: false)),
        ),
      );
    }
    if (auth.isManager) {
      actions.add(
        KpiQuickAction(
          icon: Icons.verified_user_rounded,
          label: 'Penilaian Tim',
          color: AppTheme.statusApproved,
          onTap: () => _pushScreen(const TeamTasksScreen(manager: true)),
        ),
      );
      actions.add(
        KpiQuickAction(
          icon: Icons.rule_rounded,
          label: 'Tinjauan Opsional',
          color: AppTheme.statusSubmitted,
          onTap: () => _pushScreen(const DailyAssessmentScreen(manager: true)),
        ),
      );
      if (auth.hasCapability('attendance.manage')) {
        actions.add(
          KpiQuickAction(
            icon: Icons.fact_check_rounded,
            label: 'Absensi',
            color: AppTheme.statusSubmitted,
            onTap: () =>
                _pushScreen(const ResourceScreen(resource: 'attendances')),
          ),
        );
      }
    }
    if (auth.hasCapability('reports.view')) {
      actions.add(
        KpiQuickAction(
          icon: Icons.assessment_rounded,
          label: 'Hasil KPI',
          onTap: () => _pushScreen(const KpiReportScreen()),
        ),
      );
    }
    if (auth.hasCapability('coaching.manage')) {
      actions.add(
        KpiQuickAction(
          icon: Icons.school_outlined,
          label: 'Coaching',
          onTap: () => _pushScreen(
            const ResourceScreen(resource: 'coaching-logs', title: 'Coaching'),
          ),
        ),
      );
    }
    if (auth.isGudang) {
      actions.add(
        KpiQuickAction(
          icon: Icons.inventory_outlined,
          label: 'Daftar stok',
          onTap: () =>
              _pushScreen(const ResourceScreen(resource: 'spareparts')),
        ),
      );
    }
    if (auth.hasCapability('feedback.view')) {
      actions.add(
        KpiQuickAction(
          icon: Icons.forum_rounded,
          label: 'Feedback',
          color: AppTheme.statusApproved,
          onTap: () => _pushScreen(const FeedbackScreen()),
        ),
      );
    }
    if (auth.hasCapability('complaints.create') ||
        auth.hasCapability('complaints.validate') ||
        auth.hasCapability('complaints.manage')) {
      actions.add(
        KpiQuickAction(
          icon: Icons.report_problem_outlined,
          label: 'Komplain',
          color: AppTheme.statusDanger,
          onTap: () =>
              _pushScreen(const ResourceScreen(resource: 'complaints')),
        ),
      );
    }
    if (auth.isAdmin) {
      actions.add(
        KpiQuickAction(
          icon: Icons.fact_check_outlined,
          label: 'Work-log admin',
          color: AppTheme.statusSubmitted,
          onTap: () =>
              _pushScreen(const ResourceScreen(resource: 'admin-work-logs')),
        ),
      );
    }

    if (actions.isEmpty) {
      actions.add(
        KpiQuickAction(
          icon: Icons.assignment_turned_in_rounded,
          label: 'Lihat KPI saya',
          onTap: () => setState(() => _currentIndex = hasMyKpi ? 2 : 0),
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
              Icon(
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
                        style: TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                          color: AppTheme.textInk,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${employee?['position'] ?? 'Staff'} • ${employee?['branch'] ?? 'Cabang Pusat'}',
                        style: TextStyle(
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
                          KpiStatusPill(
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
                        style: TextStyle(
                          color: AppTheme.textInk,
                          fontWeight: FontWeight.w800,
                          fontSize: 22,
                          letterSpacing: -0.4,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
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
                        style: TextStyle(
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

            // Quick actions mengikuti role dan pola shortcut pada referensi.
            KpiSectionHeader(title: 'Akses cepat'),
            const SizedBox(height: 12),
            _buildQuickActions(auth),

            const SizedBox(height: 24),

            // My KPI Progress Section
            if (myKpi != null) ...[
              Text(
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
                              style: TextStyle(
                                fontSize: 12,
                                color: AppTheme.textMuted,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            Text(
                              '${myKpi['filled_items']} / ${myKpi['total_items']} indikator',
                              style: TextStyle(
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
              Text(
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
              Text(
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
                            child: _metricCard(
                              metric.$1,
                              metric.$2,
                              metric.$3,
                              expand: false,
                            ),
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

  Widget _metricCard(
    String label,
    String value,
    Color color, {
    bool expand = true,
  }) {
    final card = Container(
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
            style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
          ),
        ],
      ),
    );
    return expand ? Expanded(child: card) : card;
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
