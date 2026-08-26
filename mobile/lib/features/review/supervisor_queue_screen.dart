import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';
import 'review_detail_screen.dart';

class SupervisorQueueScreen extends StatefulWidget {
  const SupervisorQueueScreen({super.key});

  @override
  State<SupervisorQueueScreen> createState() => _SupervisorQueueScreenState();
}

class _SupervisorQueueScreenState extends State<SupervisorQueueScreen> {
  bool _isLoading = true;
  List<dynamic> _queue = [];
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadQueue();
  }

  Future<void> _loadQueue() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final res = await ApiService.get('/supervisor/queue');
      setState(() {
        _queue = res['data'] ?? [];
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
    if (_isLoading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_errorMessage != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(_errorMessage!),
            const SizedBox(height: 12),
            ElevatedButton(onPressed: _loadQueue, child: const Text('Muat Ulang')),
          ],
        ),
      );
    }

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _loadQueue,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Antrean Review Tim',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppTheme.textInk),
                ),
                Text(
                  '${_queue.length} Submission',
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
              ],
            ),
            const SizedBox(height: 14),

            if (_queue.isEmpty)
              Container(
                padding: const EdgeInsets.all(32),
                alignment: Alignment.center,
                child: const Column(
                  children: [
                    Icon(Icons.check_circle_outline_rounded, color: AppTheme.primary, size: 48),
                    SizedBox(height: 12),
                    Text(
                      'Tidak ada submission yang perlu direview saat ini.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: AppTheme.textMuted),
                    ),
                  ],
                ),
              )
            else
              ..._queue.map((kpi) {
                final emp = kpi['employee'];
                final status = kpi['status'];

                return Card(
                  margin: const EdgeInsets.only(bottom: 12),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(16),
                    onTap: () async {
                      final updated = await Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => ReviewDetailScreen(kpiId: kpi['id']),
                        ),
                      );
                      if (updated == true) _loadQueue();
                    },
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                emp['name'] ?? 'Karyawan',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                              ),
                              KpiStatusPill(
                                label: _formatStatus(status),
                                color: _getStatusColor(status),
                                icon: _getStatusIcon(status),
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Text(
                            '${emp['position']} • ${emp['employee_number']}',
                            style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                          ),
                          const SizedBox(height: 12),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                'Verifikasi: ${kpi['verified_items']}/${kpi['total_items']} Indikator',
                                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                              ),
                              const Icon(Icons.arrow_forward_ios_rounded, size: 14, color: AppTheme.textMuted),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              }),
          ],
        ),
      ),
    );
  }

  String _formatStatus(String status) {
    switch (status) {
      case 'submitted': return 'Menunggu Review';
      case 'under_review': return 'Sedang Direview';
      case 'revision_required': return 'Perlu Revisi';
      case 'verified': return 'Terverifikasi';
      default: return status;
    }
  }

  IconData _getStatusIcon(String status) {
    return kpiStatusPresentation(status)['icon'] as IconData;
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'submitted': return AppTheme.statusSubmitted;
      case 'under_review': return AppTheme.statusUnderReview;
      case 'revision_required': return AppTheme.statusRevision;
      case 'verified': return AppTheme.statusVerified;
      default: return AppTheme.textMuted;
    }
  }
}
