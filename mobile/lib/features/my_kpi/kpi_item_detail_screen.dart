import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class KpiItemDetailScreen extends StatefulWidget {
  final String itemId;

  const KpiItemDetailScreen({super.key, required this.itemId});

  @override
  State<KpiItemDetailScreen> createState() => _KpiItemDetailScreenState();
}

class _KpiItemDetailScreenState extends State<KpiItemDetailScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _item;

  @override
  void initState() {
    super.initState();
    _loadItem();
  }

  Future<void> _loadItem() async {
    setState(() => _isLoading = true);
    try {
      final res = await ApiService.get('/my-kpi/items/${widget.itemId}');
      setState(() {
        _item = res['data'];
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

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Scaffold(body: OpsScreenLoading(rows: 4));
    }

    final item = _item!;
    final rubric = item['rubric'];
    final latestReview = item['latest_review'];
    final actual = item['actual_decimal'];
    final achievement = item['achievement_percentage'];
    final weightedScore = item['weighted_score'];
    final sourceType = item['source_type'];

    return Scaffold(
      appBar: AppBar(title: Text(item['code'] ?? 'Detail Indikator')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Title & Badges
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 4,
                  ),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    'Bobot: ${item['weight']}%',
                    style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      color: AppTheme.primary,
                      fontSize: 13,
                    ),
                  ),
                ),
                Text(
                  'Sumber: ${_formatSource(sourceType)}',
                  style: const TextStyle(
                    color: AppTheme.textMuted,
                    fontSize: 12,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              item['name'],
              style: const TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: AppTheme.textInk,
              ),
            ),
            const SizedBox(height: 20),

            // Target card
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppTheme.parchment,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppTheme.border),
              ),
              child: Row(
                children: [
                  const Icon(
                    Icons.track_changes_rounded,
                    color: AppTheme.textInk,
                  ),
                  const SizedBox(width: 12),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Target Sasaran',
                        style: TextStyle(
                          fontSize: 12,
                          color: AppTheme.textMuted,
                        ),
                      ),
                      Text(
                        '${item['target_value']} ${item['target_unit']}',
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                          color: AppTheme.textInk,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),

            const SizedBox(height: 16),

            // Nilai Aktual card — read-only, sumber nilai otomatis/supervisor
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppTheme.surface,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppTheme.border),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Nilai Aktual',
                    style: TextStyle(fontSize: 13, color: AppTheme.textMuted),
                  ),
                  const SizedBox(height: 6),
                  if (actual != null) ...[
                    Text(
                      '$actual ${item['target_unit']}',
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                        color: AppTheme.primary,
                      ),
                    ),
                    if (achievement != null) ...[
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          Text(
                            'Pencapaian: ${achievement.toStringAsFixed(1)}%',
                            style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 13,
                            ),
                          ),
                          if (weightedScore != null) ...[
                            const SizedBox(width: 12),
                            Text(
                              'Skor: ${weightedScore.toStringAsFixed(2)}',
                              style: const TextStyle(
                                fontSize: 13,
                                color: AppTheme.textMuted,
                              ),
                            ),
                          ],
                        ],
                      ),
                    ],
                  ] else ...[
                    Text(
                      _emptyActualLabel(sourceType),
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.bold,
                        color: AppTheme.textMuted,
                      ),
                    ),
                  ],
                  const SizedBox(height: 10),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(
                        Icons.info_outline_rounded,
                        size: 16,
                        color: AppTheme.textMuted,
                      ),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(
                          _sourceExplanation(sourceType),
                          style: const TextStyle(
                            fontSize: 12,
                            color: AppTheme.textMuted,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),

            if (latestReview != null && latestReview['reason'] != null) ...[
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: AppTheme.statusRevision.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AppTheme.statusRevision),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Row(
                      children: [
                        Icon(
                          Icons.feedback_rounded,
                          color: AppTheme.statusRevision,
                          size: 20,
                        ),
                        SizedBox(width: 6),
                        Text(
                          'Catatan Revisi Supervisor:',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            color: AppTheme.statusRevision,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(latestReview['reason']),
                  ],
                ),
              ),
            ],

            // Rubric Criteria Preview — apa yang dinilai Supervisor
            if (rubric != null && rubric['criteria'] != null) ...[
              const SizedBox(height: 24),
              const Text(
                'Kriteria Rubrik Observasi Supervisor:',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
              ),
              const SizedBox(height: 8),
              ...((rubric['criteria'] as List<dynamic>).map((c) {
                return Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(
                        Icons.check_circle_outline,
                        size: 16,
                        color: AppTheme.primary,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          c['criterion_text'],
                          style: const TextStyle(
                            fontSize: 13,
                            color: AppTheme.textInk,
                          ),
                        ),
                      ),
                    ],
                  ),
                );
              })),
            ],
          ],
        ),
      ),
    );
  }

  String _emptyActualLabel(String? source) {
    switch (source) {
      case 'supervisor':
        return 'Menunggu penilaian Supervisor';
      case 'cross_role':
        return 'Menunggu penilaian rekan kerja';
      default:
        return 'Belum ada data';
    }
  }

  String _sourceExplanation(String? source) {
    switch (source) {
      case 'supervisor':
        return 'Nilai ditentukan oleh Supervisor melalui observasi dan checklist — tidak diisi oleh karyawan.';
      case 'cross_role':
        return 'Nilai dinilai oleh rekan kerja terkait melalui proses review.';
      case 'import':
        return 'Dihitung otomatis oleh sistem dari laporan kasir yang diimport.';
      default:
        return 'Dihitung otomatis oleh sistem dari aktivitas Anda di aplikasi (mis. tiket servis).';
    }
  }

  String _formatSource(String? source) {
    switch (source) {
      case 'employee':
        return 'Karyawan';
      case 'supervisor':
        return 'Supervisor';
      case 'cross_role':
        return 'Cross-Role';
      case 'import':
        return 'Import Kasir';
      case 'system':
        return 'Sistem';
      default:
        return source ?? '-';
    }
  }
}
