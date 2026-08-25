import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';
import 'kpi_item_detail_screen.dart';

class MyKpiScreen extends StatefulWidget {
  const MyKpiScreen({super.key});

  @override
  State<MyKpiScreen> createState() => _MyKpiScreenState();
}

class _MyKpiScreenState extends State<MyKpiScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _kpiData;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadMyKpi();
  }

  Future<void> _loadMyKpi() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final res = await ApiService.get('/my-kpi/active');
      setState(() {
        _kpiData = res['data'];
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _errorMessage = e.toString().replaceAll('Exception: ', '');
        _isLoading = false;
      });
    }
  }

  Future<void> _submitKpi() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Konfirmasi Submisi KPI'),
        content: const Text(
          'Setelah disubmit, data aktual Anda akan dikunci dan diteruskan ke Supervisor untuk direview. Lanjutkan?',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(minimumSize: const Size(100, 40)),
            child: const Text('Ya, Submit'),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    try {
      final res = await ApiService.post('/my-kpi/submit');
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'KPI berhasil disubmit!'),
            backgroundColor: AppTheme.primary,
          ),
        );
        _loadMyKpi();
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

  @override
  Widget build(BuildContext context) {
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
              const Icon(Icons.info_outline_rounded, color: AppTheme.textMuted, size: 48),
              const SizedBox(height: 12),
              Text(_errorMessage!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              ElevatedButton(onPressed: _loadMyKpi, child: const Text('Muat Ulang')),
            ],
          ),
        ),
      );
    }

    final items = (_kpiData?['items'] as List<dynamic>?) ?? [];
    final status = _kpiData?['status'] ?? 'draft';
    final isEditable = status == 'draft' || status == 'revision_required';

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _loadMyKpi,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            // Status Header
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: AppTheme.border),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _kpiData?['period']?['name'] ?? 'Periode Aktif',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Supervisor: ${_kpiData?['supervisor'] ?? 'Atasan'}',
                        style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                      ),
                    ],
                  ),
                  Row(
                    children: [
                      IconButton(
                        icon: const Icon(Icons.sync_rounded, color: AppTheme.primary),
                        tooltip: 'Tarik Data dari Tiket Servis',
                        onPressed: () async {
                          try {
                            final res = await ApiService.post('/operational/sync-kpi');
                            if (context.mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  content: Text(res['message'] ?? 'Data operasional berhasil disinkronkan!'),
                                  backgroundColor: AppTheme.primary,
                                ),
                              );
                              _loadMyKpi();
                            }
                          } catch (e) {
                            if (context.mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
                              );
                            }
                          }
                        },
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        decoration: BoxDecoration(
                          color: _getStatusColor(status).withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          _formatStatus(status),
                          style: TextStyle(
                            color: _getStatusColor(status),
                            fontWeight: FontWeight.bold,
                            fontSize: 12,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),

            const SizedBox(height: 20),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Daftar Indikator KPI',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
                ),
                Text(
                  '${items.length} Indikator',
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
              ],
            ),
            const SizedBox(height: 12),

            // Indicator list
            ...items.map((item) {
              final isFilled = item['actual_decimal'] != null || item['actual_json'] != null;
              final isRevision = item['status'] == 'revision_required';

              return Card(
                margin: const EdgeInsets.only(bottom: 12),
                child: InkWell(
                  borderRadius: BorderRadius.circular(16),
                  onTap: () async {
                    final updated = await Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => KpiItemDetailScreen(
                          itemId: item['id'],
                          isEditable: isEditable && (status != 'revision_required' || isRevision),
                        ),
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
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
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
                              style: const TextStyle(
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
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: AppTheme.textInk),
                        ),
                        const SizedBox(height: 12),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text('Target', style: TextStyle(fontSize: 11, color: AppTheme.textMuted)),
                                Text(
                                  '${item['target_value']} ${item['target_unit']}',
                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                ),
                              ],
                            ),
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                const Text('Aktual Terisi', style: TextStyle(fontSize: 11, color: AppTheme.textMuted)),
                                Text(
                                  isFilled ? '${item['actual_decimal']} ${item['target_unit']}' : 'Belum diisi',
                                  style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 13,
                                    color: isFilled ? AppTheme.primary : AppTheme.textMuted,
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                        if (isRevision) ...[
                          const SizedBox(height: 10),
                          Container(
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: AppTheme.statusRevision.withValues(alpha: 0.15),
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: const Row(
                              children: [
                                Icon(Icons.warning_amber_rounded, color: AppTheme.statusRevision, size: 18),
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
            if (isEditable)
              ElevatedButton(
                onPressed: _submitKpi,
                child: const Text('Kirim KPI ke Supervisor (Submit)'),
              ),
            const SizedBox(height: 30),
          ],
        ),
      ),
    );
  }

  String _formatStatus(String status) {
    switch (status) {
      case 'draft': return 'Draft';
      case 'submitted': return 'Menunggu Review';
      case 'under_review': return 'Sedang Direview';
      case 'revision_required': return 'Perlu Revisi';
      case 'verified': return 'Terverifikasi';
      case 'pending_approval': return 'Menunggu Approval';
      case 'approved': return 'Disetujui';
      case 'locked': return 'Terkunci';
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
