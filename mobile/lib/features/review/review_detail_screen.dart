import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';

class ReviewDetailScreen extends StatefulWidget {
  final String kpiId;

  const ReviewDetailScreen({super.key, required this.kpiId});

  @override
  State<ReviewDetailScreen> createState() => _ReviewDetailScreenState();
}

class _ReviewDetailScreenState extends State<ReviewDetailScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _detail;

  @override
  void initState() {
    super.initState();
    _loadDetail();
  }

  Future<void> _loadDetail() async {
    setState(() => _isLoading = true);
    try {
      final res = await ApiService.get('/supervisor/review/${widget.kpiId}');
      setState(() {
        _detail = res['data'];
        _isLoading = false;
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
        );
        Navigator.pop(context);
      }
    }
  }

  Future<void> _verifyItem(String itemId, String decision) async {
    String? reason;
    if (decision == 'revision_required') {
      final reasonController = TextEditingController();
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Minta Revisi Indikator'),
          content: TextField(
            controller: reasonController,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'Alasan Revisi (Wajib)',
              hintText: 'Jelaskan data atau bukti apa yang belum sesuai...',
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx, true),
              style: ElevatedButton.styleFrom(
                backgroundColor: AppTheme.statusRevision,
                minimumSize: const Size(100, 40),
              ),
              child: const Text('Minta Revisi'),
            ),
          ],
        ),
      );

      if (confirmed != true || reasonController.text.trim().isEmpty) return;
      reason = reasonController.text.trim();
    }

    try {
      await ApiService.post('/supervisor/review/${widget.kpiId}/items/$itemId/verify', {
        'decision': decision,
        'reason': reason,
        'note': decision == 'valid' ? 'Data diverifikasi valid.' : null,
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(decision == 'valid' ? 'Indikator diverifikasi valid.' : 'Permintaan revisi dicatat.'),
            backgroundColor: decision == 'valid' ? AppTheme.primary : AppTheme.statusRevision,
          ),
        );
        _loadDetail();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
        );
      }
    }
  }

  Future<void> _openRubricChecklist(Map<String, dynamic> item) async {
    final rubric = item['rubric'];
    final criteria = (rubric?['criteria'] as List<dynamic>?) ?? [];

    final Map<int, bool> checked = {};
    for (int i = 0; i < criteria.length; i++) {
      checked[i] = true; // default fulfilled
    }

    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return Padding(
              padding: EdgeInsets.only(
                left: 20,
                right: 20,
                top: 20,
                bottom: MediaQuery.of(context).viewInsets.bottom + 20,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        'Checklist Rubrik: ${item['code']}',
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                      IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(ctx, false)),
                    ],
                  ),
                  const Text(
                    'Centang setiap kriteria yang dipenuhi oleh karyawan berdasarkan observasi Anda.',
                    style: TextStyle(fontSize: 13, color: AppTheme.textMuted),
                  ),
                  const SizedBox(height: 16),
                  ...criteria.asMap().entries.map((entry) {
                    final idx = entry.key;
                    final c = entry.value;
                    return CheckboxListTile(
                      value: checked[idx] ?? false,
                      onChanged: (val) => setModalState(() => checked[idx] = val ?? false),
                      title: Text(c['criterion_text'], style: const TextStyle(fontSize: 14)),
                      subtitle: Text('${c['points']} Poin', style: const TextStyle(fontSize: 12, color: AppTheme.primary)),
                      activeColor: AppTheme.primary,
                      contentPadding: EdgeInsets.zero,
                    );
                  }),
                  const SizedBox(height: 16),
                  ElevatedButton(
                    onPressed: () => Navigator.pop(ctx, true),
                    child: const Text('Simpan & Hitung Skor Rubrik'),
                  ),
                ],
              ),
            );
          },
        );
      },
    );

    if (confirmed != true) return;

    final answers = criteria.asMap().entries.map((entry) {
      final idx = entry.key;
      final c = entry.value;
      return {
        'criterion_id': c['id'] ?? (idx + 1),
        'criterion_text': c['criterion_text'],
        'points': (c['points'] as num).toDouble(),
        'is_fulfilled': checked[idx] ?? false,
      };
    }).toList();

    try {
      await ApiService.post('/supervisor/review/${widget.kpiId}/items/${item['id']}/rubric', {
        'answers': answers,
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Penilaian rubrik checklist berhasil disimpan!'), backgroundColor: AppTheme.primary),
        );
        _loadDetail();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
        );
      }
    }
  }

  Future<void> _forwardToManager() async {
    try {
      final res = await ApiService.post('/supervisor/review/${widget.kpiId}/forward', {
        'notes': 'Semua indikator telah diverifikasi oleh Supervisor.',
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'KPI berhasil diteruskan ke Manager!'), backgroundColor: AppTheme.primary),
        );
        Navigator.pop(context, true);
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
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final detail = _detail!;
    final emp = detail['employee'];
    final items = (detail['items'] as List<dynamic>?) ?? [];

    return Scaffold(
      appBar: AppBar(
        title: Text('Review: ${emp['name']}'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Employee info card
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppTheme.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  emp['name'] ?? '',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
                ),
                const SizedBox(height: 4),
                Text(
                  '${emp['position']} • ${emp['branch']}',
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
                const SizedBox(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('Skor Sementara: ${detail['final_score'] ?? '-'}', style: const TextStyle(fontWeight: FontWeight.bold)),
                    Text('Status: ${detail['status']}', style: const TextStyle(color: AppTheme.primary, fontWeight: FontWeight.bold)),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          const Text(
            'Verifikasi Indikator & Rubrik Observasi:',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: AppTheme.textInk),
          ),
          const SizedBox(height: 12),

          ...items.map((item) {
            final isRubric = item['formula'] == 'rubric';
            final isVerified = item['status'] == 'verified';

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
                          '${item['code']} • Bobot ${item['weight']}%',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppTheme.primary),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: isVerified ? AppTheme.primary.withValues(alpha: 0.12) : AppTheme.statusRevision.withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            isVerified ? 'Terverifikasi' : 'Belum Diverifikasi',
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.bold,
                              color: isVerified ? AppTheme.primary : AppTheme.statusRevision,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(item['name'], style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                    const SizedBox(height: 10),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text('Target: ${item['target_value']} ${item['target_unit']}', style: const TextStyle(fontSize: 13, color: AppTheme.textMuted)),
                        Text('Aktual: ${item['actual_decimal'] ?? '-'}', style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
                      ],
                    ),
                    const SizedBox(height: 14),

                    // Action buttons
                    if (isRubric)
                      OutlinedButton.icon(
                        icon: const Icon(Icons.checklist_rounded, size: 18),
                        label: const Text('Isi Checklist Rubrik SOP / Observasi'),
                        onPressed: () => _openRubricChecklist(item),
                      )
                    else
                      Row(
                        children: [
                          Expanded(
                            child: ElevatedButton.icon(
                              icon: const Icon(Icons.check_rounded, size: 16),
                              label: const Text('Valid'),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: isVerified ? AppTheme.primary : Colors.grey[200],
                                foregroundColor: isVerified ? Colors.white : AppTheme.textInk,
                                minimumSize: const Size(0, 38),
                              ),
                              onPressed: () => _verifyItem(item['id'], 'valid'),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: OutlinedButton.icon(
                              icon: const Icon(Icons.edit_note_rounded, size: 16),
                              label: const Text('Minta Revisi'),
                              style: OutlinedButton.styleFrom(
                                foregroundColor: AppTheme.statusRevision,
                                minimumSize: const Size(0, 38),
                              ),
                              onPressed: () => _verifyItem(item['id'], 'revision_required'),
                            ),
                          ),
                        ],
                      ),
                  ],
                ),
              ),
            );
          }),

          const SizedBox(height: 20),
          ElevatedButton(
            onPressed: _forwardToManager,
            child: const Text('Forward KPI ke Manager untuk Approval'),
          ),
          const SizedBox(height: 30),
        ],
      ),
    );
  }
}
