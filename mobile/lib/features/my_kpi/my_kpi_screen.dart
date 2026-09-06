import 'dart:async';

import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';
import '../../core/realtime/realtime_service.dart';
import 'kpi_item_detail_screen.dart';
import 'kpi_history_screen.dart';
import 'daily_kpi_screen.dart';

class MyKpiScreen extends StatefulWidget {
  final int? periodId;
  const MyKpiScreen({super.key, this.periodId});

  @override
  State<MyKpiScreen> createState() => _MyKpiScreenState();
}

class _MyKpiScreenState extends State<MyKpiScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _kpiData;
  String? _errorMessage;
  Timer? _refreshTimer;
  bool _requestInFlight = false;
  StreamSubscription<void>? _realtimeSubscription;

  @override
  void initState() {
    super.initState();
    unawaited(_loadMyKpi());
    _refreshTimer = Timer.periodic(
      const Duration(seconds: 30),
      (_) => unawaited(_loadMyKpi(showLoading: false)),
    );
    _realtimeSubscription = RealtimeService.instance.kpiUpdates.listen(
      (_) => unawaited(_loadMyKpi(showLoading: false)),
    );
  }

  Future<void> _loadMyKpi({bool showLoading = true}) async {
    if (_requestInFlight) return;
    _requestInFlight = true;

    if (showLoading && mounted) {
      setState(() {
        _isLoading = true;
        _errorMessage = null;
      });
    }

    try {
      final res = await ApiService.get('/my-kpi/active${widget.periodId == null ? '' : '?period_id=${widget.periodId}'}');
      if (mounted) {
        setState(() {
          _kpiData = res['data'];
          _isLoading = false;
          _errorMessage = null;
        });
      }
    } catch (e) {
      if (mounted && (showLoading || _kpiData == null)) {
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
    if (_isLoading) {
      return const OpsScreenLoading(rows: 4);
    }

    if (_errorMessage != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                Icons.info_outline_rounded,
                color: AppTheme.textMuted,
                size: 48,
              ),
              const SizedBox(height: 12),
              Text(_errorMessage!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              ElevatedButton(
                onPressed: _loadMyKpi,
                child: const Text('Muat Ulang'),
              ),
            ],
          ),
        ),
      );
    }

    final items = (_kpiData?['items'] as List<dynamic>?) ?? [];
    final status = _kpiData?['status'] ?? 'draft';

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _loadMyKpi,
        child: ListView(
          // Keep the last KPI card above the floating navigation surface.
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 120),
          children: [
            // Status Header
            OpsReveal(
              child: OpsHeroCard(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'PERFORMA SAYA',
                            style: TextStyle(
                              color: AppTheme.primaryBright,
                              fontSize: 11,
                              fontWeight: FontWeight.w800,
                              letterSpacing: 1.2,
                            ),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            _kpiData?['period']?['name'] ?? 'Periode Aktif',
                            style: TextStyle(
                              fontWeight: FontWeight.w800,
                              fontSize: 20,
                              color: AppTheme.textInk,
                            ),
                          ),
                          const SizedBox(height: 5),
                          Text(
                            'Supervisor: ${_kpiData?['supervisor'] ?? 'Atasan'}',
                            style: TextStyle(
                              color: AppTheme.textMuted,
                              fontSize: 13,
                            ),
                          ),
                          const SizedBox(height: 14),
                          KpiStatusPill(
                            label: _formatStatus(status),
                            color: _getStatusColor(status),
                            icon: _getStatusIcon(status),
                          ),
                        ],
                      ),
                    ),
                    Column(
                      children: [
                        IconButton(
                          icon: Icon(
                            Icons.history_rounded,
                            color: AppTheme.primaryBright,
                          ),
                          tooltip: 'Riwayat KPI',
                          onPressed: () async {
                            await Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => const KpiHistoryScreen(),
                              ),
                            );
                          },
                        ),
                        IconButton(
                          icon: Icon(
                            Icons.today_rounded,
                            color: AppTheme.primaryBright,
                          ),
                          tooltip: 'KPI harian',
                          onPressed: () async {
                            await Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => const DailyKpiScreen(),
                              ),
                            );
                          },
                        ),
                        IconButton(
                          icon: Icon(
                            Icons.refresh_rounded,
                            color: AppTheme.primaryBright,
                          ),
                          tooltip: 'Muat ulang KPI',
                          onPressed: _loadMyKpi,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),

            if (status == 'draft' || status == 'revision_required') ...[
              const SizedBox(height: 12),
              OpsReveal(
                delay: const Duration(milliseconds: 55),
                child: Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: AppTheme.primary.withValues(alpha: 0.3),
                    ),
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(
                        Icons.info_outline_rounded,
                        color: AppTheme.primary,
                        size: 20,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Nilai KPI diisi otomatis oleh sistem atau melalui review Supervisor/Manager. '
                          'Karyawan hanya dapat melihat hasil dan catatan review.',
                          style: TextStyle(
                            fontSize: 12.5,
                            color: AppTheme.textInk,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
            if (status == 'submitted' ||
                status == 'under_review' ||
                status == 'verified' ||
                status == 'pending_approval') ...[
              const SizedBox(height: 12),
              Text(
                'Nilai yang disiapkan otomatis sedang diproses dalam alur review dan approval.',
                style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
              ),
            ],
            if (_kpiData?['score_visible'] == true) ...[
              const SizedBox(height: 12),
              Text(
                'Skor: ${_kpiData?['final_score'] ?? '—'} · ${_kpiData?['rating_label'] ?? 'Belum dapat dihitung'}',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ] else ...[
              const SizedBox(height: 12),
              const Text('Skor dan predikat tersedia setelah periode dipublikasikan.'),
            ],
            if (status == 'approved' || status == 'locked') ...[
              const SizedBox(height: 12),
              Text(
                'KPI final bersifat immutable. Perubahan hanya melalui alur koreksi resmi.',
                style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
              ),
            ],
            const SizedBox(height: 20),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Daftar Indikator KPI',
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: AppTheme.textInk,
                  ),
                ),
                Text(
                  '${items.length} Indikator',
                  style: TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
              ],
            ),
            const SizedBox(height: 12),

            // Indicator list
            ...items.asMap().entries.map((entry) {
              final item = entry.value;
              final isFilled =
                  item['actual_decimal'] != null || item['actual_json'] != null;
              final isRevision = item['status'] == 'revision_required';

              return OpsReveal(
                delay: Duration(milliseconds: 80 + (entry.key * 35)),
                child: OpsCard(
                  padding: EdgeInsets.zero,
                  onTap: () async {
                    final updated = await Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => KpiItemDetailScreen(itemId: item['id']),
                      ),
                    );
                    if (updated == true) _loadMyKpi();
                  },
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Container(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 8,
                                vertical: 4,
                              ),
                              decoration: BoxDecoration(
                                color: AppTheme.primary.withValues(alpha: 0.1),
                                borderRadius: BorderRadius.circular(6),
                              ),
                              child: Text(
                                item['code'],
                                style: const TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.bold,
                                  color: AppTheme.primary,
                                ),
                              ),
                            ),
                            Text(
                              'Bobot: ${item['weight']}%',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: AppTheme.textMuted,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 10),
                        Text(
                          item['name'],
                          style: TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w700,
                            color: AppTheme.textInk,
                          ),
                        ),
                        const SizedBox(height: 12),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Target',
                                  style: TextStyle(
                                    fontSize: 12,
                                    color: AppTheme.textMuted,
                                  ),
                                ),
                                Text(
                                  '${item['target_value']} ${item['target_unit']}',
                                  style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 13,
                                  ),
                                ),
                              ],
                            ),
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                Text(
                                  'Nilai Aktual',
                                  style: TextStyle(
                                    fontSize: 12,
                                    color: AppTheme.textMuted,
                                  ),
                                ),
                                Text(
                                  _actualLabel(item),
                                  style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 13,
                                    color: isFilled
                                        ? AppTheme.primary
                                        : AppTheme.textMuted,
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                        if (isFilled && _isPercentageMetric(item)) ...[
                          const SizedBox(height: 12),
                          KpiProgressBar(value: _progressRatio(item)),
                        ],
                        if (isRevision) ...[
                          const SizedBox(height: 10),
                          Container(
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: AppTheme.statusRevision.withValues(
                                alpha: 0.15,
                              ),
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Row(
                              children: [
                                Icon(
                                  Icons.warning_amber_rounded,
                                  color: AppTheme.statusRevision,
                                  size: 18,
                                ),
                                SizedBox(width: 6),
                                Text(
                                  'Perlu Perbaikan / Revisi Data',
                                  style: TextStyle(
                                    fontSize: 12,
                                    fontWeight: FontWeight.bold,
                                    color: AppTheme.statusRevision,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
              );
            }),

            const SizedBox(height: 20),
            Center(
              child: Text(
                'Hasil akhir akan diumumkan setelah periode ditutup dan disetujui.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
              ),
            ),
            const SizedBox(height: 30),
          ],
        ),
      ),
    );
  }

  /// Hanya tampilkan progress bar untuk metrik rasio/persentase (punya target terukur 0-100).
  bool _isPercentageMetric(Map<String, dynamic> item) {
    final unit = (item['target_unit'] ?? '').toString().toLowerCase();
    final metric = (item['metric_type'] ?? '').toString().toLowerCase();
    // Persentase / rasio: tampilkan. Hitungan absolut (unit: servis, kali, dll): jangan.
    return unit.contains('%') ||
        metric.contains('percentage') ||
        metric.contains('ratio');
  }

  /// Rasio pencapaian aktual vs target, di-clamp 0..1 (untuk progress bar).
  double _progressRatio(Map<String, dynamic> item) {
    final target =
        double.tryParse((item['target_value'] ?? '').toString()) ?? 0;
    final actual = double.tryParse((item['actual_decimal'] ?? '').toString());
    if (actual == null || target <= 0) return 0;
    return (actual / target).clamp(0.0, 1.0);
  }

  String _actualLabel(Map<String, dynamic> item) {
    final isFilled =
        item['actual_decimal'] != null || item['actual_json'] != null;
    if (isFilled) {
      return '${item['actual_decimal']} ${item['target_unit']}';
    }
    switch (item['source_type']) {
      case 'supervisor':
        return 'Menunggu penilaian Supervisor';
      case 'cross_role':
        return 'Dinilai rekan kerja';
      case 'employee':
        return 'Menunggu penilaian Supervisor';
      default:
        return 'Otomatis dari sistem';
    }
  }

  String _formatStatus(String status) {
    switch (status) {
      case 'draft':
        return 'Draft';
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
        return 'Disetujui';
      case 'locked':
        return 'Terkunci';
      default:
        return status;
    }
  }

  IconData _getStatusIcon(String status) {
    return kpiStatusPresentation(status)['icon'] as IconData;
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
