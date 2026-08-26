import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';
import 'approval_detail_screen.dart';

class ManagerApprovalScreen extends StatefulWidget {
  const ManagerApprovalScreen({super.key});

  @override
  State<ManagerApprovalScreen> createState() => _ManagerApprovalScreenState();
}

class _ManagerApprovalScreenState extends State<ManagerApprovalScreen> {
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
      final res = await ApiService.get('/manager/queue');
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

  Future<void> _approve(String kpiId, String empName) async {
    final noteController = TextEditingController();
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Approve KPI: $empName'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('Dengan menyetujui, penilaian KPI ini akan dikunci (Locked) dan diterbitkan ke karyawan.'),
            const SizedBox(height: 12),
            TextField(
              controller: noteController,
              decoration: const InputDecoration(labelText: 'Catatan Approval (Opsional)'),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(minimumSize: const Size(100, 40)),
            child: const Text('Ya, Approve & Kunci'),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    try {
      final res = await ApiService.post('/manager/approval/$kpiId/approve', {
        'note': noteController.text.trim(),
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'KPI berhasil disetujui!'), backgroundColor: AppTheme.primary),
        );
        _loadQueue();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: AppTheme.statusDanger),
        );
      }
    }
  }

  Future<void> _returnToSpv(String kpiId, String empName) async {
    final reasonController = TextEditingController();
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Kembalikan KPI: $empName'),
        content: TextField(
          controller: reasonController,
          maxLines: 3,
          decoration: const InputDecoration(
            labelText: 'Alasan Pengembalian (Wajib)',
            hintText: 'Jelaskan mengapa KPI perlu direview ulang...',
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.statusDanger,
              minimumSize: const Size(100, 40),
            ),
            child: const Text('Kembalikan'),
          ),
        ],
      ),
    );

    if (confirm != true || reasonController.text.trim().isEmpty) return;

    try {
      final res = await ApiService.post('/manager/approval/$kpiId/return', {
        'reason': reasonController.text.trim(),
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'KPI dikembalikan ke Supervisor.'), backgroundColor: AppTheme.statusRevision),
        );
        _loadQueue();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: AppTheme.statusDanger),
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
                  'Antrean Approval Manager',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppTheme.textInk),
                ),
                Text(
                  '${_queue.length} Menunggu Persetujuan',
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
              ],
            ),
            const SizedBox(height: 14),

            if (_queue.isEmpty)
              const KpiEmptyState(
                icon: Icons.verified_rounded,
                title: 'Approval sudah beres',
                message: 'Semua pengajuan KPI telah disetujui.',
              )
            else
              ..._queue.map((kpi) {
                final emp = kpi['employee'];
                final finalScore = kpi['final_score'];

                return Card(
                  margin: const EdgeInsets.only(bottom: 12),
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
                              label: '${kpi['rating_label'] ?? 'Baik'} (${finalScore ?? '-'})',
                              color: AppTheme.statusApproved,
                              icon: Icons.verified_rounded,
                            ),
                          ],
                        ),
                        const SizedBox(height: 4),
                        Text(
                          '${emp['position']} • ${emp['branch']}',
                          style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                        ),
                        const SizedBox(height: 12),
                        SizedBox(
                          width: double.infinity,
                          child: OutlinedButton.icon(
                            icon: const Icon(Icons.visibility_outlined, size: 16),
                            label: const Text('Lihat Detail & Eviden'),
                            style: OutlinedButton.styleFrom(minimumSize: const Size(0, 36)),
                            onPressed: () async {
                              final changed = await Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (_) => ApprovalDetailScreen(kpiId: kpi['id'].toString()),
                                ),
                              );
                              if (changed == true) _loadQueue();
                            },
                          ),
                        ),
                        const SizedBox(height: 12),
                        Row(
                          children: [
                            Expanded(
                              child: ElevatedButton.icon(
                                icon: const Icon(Icons.check_circle_rounded, size: 18),
                                label: const Text('Approve & Lock'),
                                style: ElevatedButton.styleFrom(
                                  minimumSize: const Size(0, 42),
                                ),
                                onPressed: () => _approve(kpi['id'], emp['name']),
                              ),
                            ),
                            const SizedBox(width: 8),
                            OutlinedButton.icon(
                              icon: const Icon(Icons.undo_rounded, size: 16),
                              label: const Text('Return'),
                              style: OutlinedButton.styleFrom(
                                foregroundColor: AppTheme.statusDanger,
                                minimumSize: const Size(80, 42),
                              ),
                              onPressed: () => _returnToSpv(kpi['id'], emp['name']),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                );
              }),
          ],
        ),
      ),
    );
  }
}
