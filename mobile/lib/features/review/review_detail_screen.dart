import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
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
          SnackBar(
            content: Text(e.toString()),
            backgroundColor: AppTheme.statusDanger,
          ),
        );
        Navigator.pop(context);
      }
    }
  }

  Future<void> _verifyItem(String itemId, String decision) async {
    String? reason;
    if (decision == 'revision_required') {
      final revisionReason = await showOpsTextInputSheet(
        context: context,
        eyebrow: 'Review indikator',
        title: 'Minta revisi indikator',
        subtitle:
            'Catat data atau bukti yang belum sesuai untuk ditindaklanjuti.',
        label: 'Alasan revisi (wajib)',
        hintText: 'Jelaskan data atau bukti apa yang belum sesuai...',
        actionLabel: 'Minta revisi',
        actionColor: AppTheme.statusRevision,
        maxLines: 4,
      );
      if (revisionReason == null || revisionReason.trim().isEmpty) return;
      reason = revisionReason.trim();
    }

    try {
      await ApiService.post(
        '/supervisor/review/${widget.kpiId}/items/$itemId/verify',
        {
          'decision': decision,
          'reason': reason,
          'note': decision == 'valid' ? 'Data diverifikasi valid.' : null,
        },
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              decision == 'valid'
                  ? 'Indikator diverifikasi valid.'
                  : 'Permintaan revisi dicatat.',
            ),
            backgroundColor: decision == 'valid'
                ? AppTheme.primary
                : AppTheme.statusRevision,
          ),
        );
        _loadDetail();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.toString()),
            backgroundColor: AppTheme.statusDanger,
          ),
        );
      }
    }
  }

  Future<void> _openRubricChecklist(Map<String, dynamic> item) async {
    final rubric = item['rubric'];
    final criteria = (rubric?['criteria'] as List<dynamic>?) ?? [];

    final Map<int, bool> checked = {};
    for (int i = 0; i < criteria.length; i++) {
      checked[i] = false;
    }

    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return OpsFormSheet(
              eyebrow: 'Penilaian supervisor',
              title: 'Checklist rubrik: ${item['code']}',
              subtitle:
                  'Centang setiap kriteria yang dipenuhi berdasarkan observasi Anda.',
              footer: ElevatedButton.icon(
                onPressed: () => Navigator.pop(ctx, true),
                icon: const Icon(Icons.calculate_rounded),
                label: const Text('Simpan & hitung skor'),
              ),
              child: Column(
                children: criteria.asMap().entries.map((entry) {
                  final idx = entry.key;
                  final c = entry.value;
                  return CheckboxListTile(
                    value: checked[idx] ?? false,
                    onChanged: (val) =>
                        setModalState(() => checked[idx] = val ?? false),
                    title: Text(
                      c['criterion_text'],
                      style: const TextStyle(fontSize: 14),
                    ),
                    subtitle: Text(
                      '${c['points']} Poin',
                      style: TextStyle(
                        fontSize: 12,
                        color: AppTheme.primaryBright,
                      ),
                    ),
                    activeColor: AppTheme.primary,
                    contentPadding: EdgeInsets.zero,
                  );
                }).toList(),
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
      await ApiService.post(
        '/supervisor/review/${widget.kpiId}/items/${item['id']}/rubric',
        {'answers': answers},
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Penilaian rubrik checklist berhasil disimpan!'),
            backgroundColor: AppTheme.primary,
          ),
        );
        _loadDetail();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.toString()),
            backgroundColor: AppTheme.statusDanger,
          ),
        );
      }
    }
  }

  Future<void> _forwardToManager() async {
    try {
      final res = await ApiService.post(
        '/supervisor/review/${widget.kpiId}/forward',
        {'notes': 'Semua indikator telah diverifikasi oleh Supervisor.'},
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              res['message'] ?? 'KPI berhasil diteruskan ke Manager!',
            ),
            backgroundColor: AppTheme.primary,
          ),
        );
        Navigator.pop(context, true);
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
      return const Scaffold(body: OpsScreenLoading(rows: 5));
    }

    final detail = _detail!;
    final emp = detail['employee'];
    final items = (detail['items'] as List<dynamic>?) ?? [];

    return Scaffold(
      appBar: AppBar(title: Text('Review: ${emp['name']}')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Employee info card
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppTheme.surface,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppTheme.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  emp['name'] ?? '',
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 18,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  '${emp['position']} • ${emp['branch']}',
                  style: TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
                const SizedBox(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'Skor Sementara: ${detail['final_score'] ?? '-'}',
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                    Text(
                      'Status: ${detail['status']}',
                      style: const TextStyle(
                        color: AppTheme.primary,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          Text(
            'Verifikasi Indikator & Rubrik Observasi:',
            style: TextStyle(
              fontWeight: FontWeight.bold,
              fontSize: 15,
              color: AppTheme.textInk,
            ),
          ),
          const SizedBox(height: 12),

          ...items.map((item) {
            final isRubric = item['formula'] == 'rubric';
            final isVerified =
                ['verified', 'assessed'].contains(item['status']) ||
                !(detail['available_actions'] as List? ?? const []).contains(
                  'review',
                );
            final evidences = (item['evidences'] as List<dynamic>?) ?? [];

            return OpsReveal(
              delay: Duration(milliseconds: 60 + (items.indexOf(item) * 35)),
              child: OpsCard(
                padding: EdgeInsets.zero,
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
                            style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 12,
                              color: AppTheme.primary,
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 3,
                            ),
                            decoration: BoxDecoration(
                              color: isVerified
                                  ? AppTheme.primary.withValues(alpha: 0.12)
                                  : AppTheme.statusRevision.withValues(
                                      alpha: 0.12,
                                    ),
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Text(
                              isVerified
                                  ? 'Terverifikasi'
                                  : 'Belum Diverifikasi',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: isVerified
                                    ? AppTheme.primary
                                    : AppTheme.statusRevision,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Text(
                        item['name'],
                        style: const TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 15,
                        ),
                      ),
                      const SizedBox(height: 10),
                      Wrap(
                        spacing: 16,
                        runSpacing: 4,
                        children: [
                          Text(
                            'Target: ${item['target_value']} ${item['target_unit']}',
                            style: TextStyle(
                              fontSize: 13,
                              color: AppTheme.textMuted,
                            ),
                          ),
                          Text(
                            'Aktual: ${item['actual_decimal'] ?? '-'}',
                            style: const TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      Text(
                        'Evidence',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: AppTheme.textMuted,
                        ),
                      ),
                      const SizedBox(height: 4),
                      if (evidences.isEmpty)
                        Text(
                          'Tidak ada evidence tercatat.',
                          style: TextStyle(
                            fontSize: 12,
                            color: AppTheme.textMuted,
                          ),
                        )
                      else
                        ...evidences.map((entry) {
                          final evidence = Map<String, dynamic>.from(
                            entry as Map,
                          );
                          return ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(
                              Icons.attach_file_rounded,
                              size: 18,
                              color: AppTheme.primary,
                            ),
                            title: Text(
                              evidence['file_name']?.toString() ?? 'Evidence',
                              style: const TextStyle(fontSize: 13),
                            ),
                            subtitle: Text(
                              evidence['file_size']?.toString() ?? '-',
                              style: TextStyle(
                                fontSize: 11,
                                color: AppTheme.textMuted,
                              ),
                            ),
                          );
                        }),
                      const SizedBox(height: 14),

                      // Action buttons
                      if (isRubric)
                        OutlinedButton.icon(
                          icon: const Icon(Icons.checklist_rounded, size: 18),
                          label: const Text(
                            'Isi Checklist Rubrik SOP / Observasi',
                          ),
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
                                  backgroundColor: isVerified
                                      ? AppTheme.primary
                                      : Colors.grey[200],
                                  foregroundColor: isVerified
                                      ? Colors.white
                                      : AppTheme.textInk,
                                  minimumSize: const Size(0, 38),
                                ),
                                onPressed: () =>
                                    _verifyItem(item['id'], 'valid'),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: OutlinedButton.icon(
                                icon: const Icon(
                                  Icons.edit_note_rounded,
                                  size: 16,
                                ),
                                label: const Text('Minta Revisi'),
                                style: OutlinedButton.styleFrom(
                                  foregroundColor: AppTheme.statusRevision,
                                  minimumSize: const Size(0, 38),
                                ),
                                onPressed: () => _verifyItem(
                                  item['id'],
                                  'revision_required',
                                ),
                              ),
                            ),
                          ],
                        ),
                    ],
                  ),
                ),
              ),
            );
          }),

          const SizedBox(height: 20),
          ElevatedButton(
            onPressed:
                (detail['available_actions'] as List? ?? const []).contains(
                  'forward',
                )
                ? _forwardToManager
                : null,
            child: const Text('Kirim rekap ke Manager'),
          ),
          const SizedBox(height: 30),
        ],
      ),
    );
  }
}
