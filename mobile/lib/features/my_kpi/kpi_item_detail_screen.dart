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
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadItem();
  }

  Future<void> _loadItem() async {
    if (mounted) setState(() => _isLoading = true);
    try {
      final res = await ApiService.get('/my-kpi/items/${widget.itemId}');
      final data = Map<String, dynamic>.from(res['data'] as Map);
      if (!mounted) return;
      _item = data;
      setState(() => _isLoading = false);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _isLoading = false;
        _errorMessage = _message(e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) return const Scaffold(body: OpsScreenLoading(rows: 4));
    if (_item == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Detail indikator')),
        body: KpiEmptyState(
          icon: Icons.error_outline_rounded,
          title: 'Detail tidak tersedia',
          message: _errorMessage ?? 'Data indikator tidak dapat dimuat.',
          actionLabel: 'Coba lagi',
          onAction: _loadItem,
        ),
      );
    }

    final item = _item!;
    final rubric = item['rubric'];
    final latestReview = item['latest_review'];
    final sourceType = item['source_type']?.toString();
    final actual = item['actual_decimal'];
    final achievement = _number(item['achievement_percentage']);
    final weightedScore = _number(item['weighted_score']);

    return Scaffold(
      appBar: AppBar(
        title: Text(item['code']?.toString() ?? 'Detail indikator'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                KpiStatusPill(
                  label: _formatStatus(item['status']?.toString()),
                  color: _statusColor(item['status']?.toString()),
                  icon: _statusIcon(item['status']?.toString()),
                ),
                Text(
                  'Bobot: ${item['weight']}%',
                  style: TextStyle(
                    color: AppTheme.textMuted,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              item['name']?.toString() ?? '-',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 5),
            Text(
              'Sumber: ${_formatSource(sourceType)}',
              style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
            ),
            const SizedBox(height: 20),
            OpsCard(
              color: AppTheme.parchment,
              child: Row(
                children: [
                  Icon(Icons.track_changes_rounded, color: AppTheme.textInk),
                  const SizedBox(width: 12),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Target Sasaran',
                        style: TextStyle(
                          fontSize: 12,
                          color: AppTheme.textMuted,
                        ),
                      ),
                      Text(
                        '${item['target_value']} ${item['target_unit']}',
                        style: TextStyle(
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
            _buildResultCard(
              item,
              actual,
              achievement,
              weightedScore,
              sourceType,
            ),
            if (latestReview is Map && latestReview['reason'] != null) ...[
              const SizedBox(height: 16),
              OpsCard(
                color: AppTheme.statusRevision.withValues(alpha: 0.12),
                borderColor: AppTheme.statusRevision.withValues(alpha: 0.5),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(
                          Icons.feedback_rounded,
                          color: AppTheme.statusRevision,
                          size: 20,
                        ),
                        const SizedBox(width: 6),
                        Text(
                          'Catatan revisi Supervisor',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            color: AppTheme.statusRevision,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(latestReview['reason'].toString()),
                  ],
                ),
              ),
            ],
            if (rubric is Map && rubric['criteria'] is List)
              _buildRubric(rubric['criteria'] as List<dynamic>),
            _buildEvidenceSection(),
          ],
        ),
      ),
    );
  }

  Widget _buildResultCard(
    Map<String, dynamic> item,
    dynamic actual,
    double? achievement,
    double? weightedScore,
    String? sourceType,
  ) {
    final calculationMeta = item['actual_json'];

    return OpsCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Nilai aktual',
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
            if (achievement != null || weightedScore != null) ...[
              const SizedBox(height: 8),
              Wrap(
                spacing: 12,
                runSpacing: 4,
                children: [
                  if (achievement != null)
                    Text(
                      'Pencapaian: ${achievement.toStringAsFixed(1)}%',
                      style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 13,
                      ),
                    ),
                  if (weightedScore != null)
                    Text(
                      'Skor: ${weightedScore.toStringAsFixed(2)}',
                      style: TextStyle(fontSize: 13, color: AppTheme.textMuted),
                    ),
                ],
              ),
            ],
          ] else
            Text(
              _emptyActualLabel(sourceType),
              style: TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.bold,
                color: AppTheme.textMuted,
              ),
            ),
          const SizedBox(height: 10),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                Icons.info_outline_rounded,
                size: 16,
                color: AppTheme.textMuted,
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  _sourceExplanation(sourceType),
                  style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
                ),
              ),
            ],
          ),
          if (item['calculation_note'] != null) ...[
            const SizedBox(height: 8),
            Text(
              item['calculation_note'].toString(),
              style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
            ),
          ],
          if (calculationMeta is Map && calculationMeta.isNotEmpty) ...[
            const SizedBox(height: 12),
            Text(
              'Dasar perhitungan',
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.bold,
                color: AppTheme.textMuted,
              ),
            ),
            const SizedBox(height: 4),
            ...calculationMeta.entries.map(
              (entry) => Padding(
                padding: const EdgeInsets.only(bottom: 3),
                child: Text(
                  '${entry.key}: ${entry.value}',
                  style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildEvidenceSection() {
    final evidences = (_item?['evidences'] as List<dynamic>?) ?? [];
    return Padding(
      padding: const EdgeInsets.only(top: 16),
      child: OpsCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    'Evidence',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                Text(
                  itemEvidenceLabel(),
                  style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
                ),
              ],
            ),
            const SizedBox(height: 5),
            Text(
              'Evidence dikelola oleh sistem atau reviewer yang berwenang.',
              style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
            ),
            if (evidences.isEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(
                  'Belum ada evidence tercatat.',
                  style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
                ),
              ),
            ...evidences.map((entry) {
              final evidence = Map<String, dynamic>.from(entry as Map);
              final status =
                  evidence['scan_status']?.toString() ?? 'needs_migration';
              final color = status == 'clean'
                  ? AppTheme.statusApproved
                  : status == 'rejected'
                  ? AppTheme.statusDanger
                  : AppTheme.statusRevision;
              return ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  status == 'clean'
                      ? Icons.verified_rounded
                      : Icons.hourglass_top_rounded,
                  color: color,
                ),
                title: Text(evidence['file_name']?.toString() ?? 'Evidence'),
                subtitle: Text(
                  _scanStatus(status),
                  style: TextStyle(color: color, fontSize: 12),
                ),
              );
            }),
          ],
        ),
      ),
    );
  }

  Widget _buildRubric(List<dynamic> criteria) {
    return Padding(
      padding: const EdgeInsets.only(top: 24),
      child: OpsCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Kriteria rubrik observasi Supervisor',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 10),
            ...criteria.map((entry) {
              final criterion = Map<String, dynamic>.from(entry as Map);
              return Padding(
                padding: const EdgeInsets.only(bottom: 8),
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
                        criterion['criterion_text']?.toString() ?? '-',
                        style: TextStyle(fontSize: 13, color: AppTheme.textInk),
                      ),
                    ),
                  ],
                ),
              );
            }),
          ],
        ),
      ),
    );
  }

  String itemEvidenceLabel() =>
      (_item?['evidence_required'] == true) ? 'Wajib' : 'Opsional';

  double? _number(dynamic value) => value is num
      ? value.toDouble()
      : double.tryParse(value?.toString() ?? '');

  String _message(Object error) => error is ApiException
      ? error.message
      : error.toString().replaceAll('Exception: ', '');

  String _emptyActualLabel(String? source) {
    switch (source) {
      case 'supervisor':
        return 'Menunggu penilaian Supervisor';
      case 'cross_role':
        return 'Menunggu penilaian reviewer';
      default:
        return 'Belum ada data dari sistem';
    }
  }

  String _sourceExplanation(String? source) {
    switch (source) {
      case 'employee':
        return 'Nilai ditentukan Supervisor/Manager atau sistem; karyawan tidak dapat mengubahnya.';
      case 'supervisor':
        return 'Nilai ditentukan Supervisor melalui observasi dan checklist — tidak diisi oleh karyawan.';
      case 'cross_role':
        return 'Nilai ditentukan oleh reviewer yang berwenang melalui proses review.';
      case 'import':
        return 'Dihitung otomatis oleh sistem dari laporan yang diimport.';
      default:
        return 'Dihitung otomatis oleh sistem dari aktivitas yang tercatat.';
    }
  }

  String _formatSource(String? source) {
    switch (source) {
      case 'employee':
        return 'Karyawan (legacy)';
      case 'supervisor':
        return 'Supervisor';
      case 'cross_role':
        return 'Reviewer';
      case 'import':
        return 'Import sistem';
      case 'system':
        return 'Sistem';
      default:
        return source ?? '-';
    }
  }

  String _formatStatus(String? status) =>
      kpiStatusPresentation(status ?? 'draft')['label']?.toString() ?? 'Draft';

  Color _statusColor(String? status) =>
      kpiStatusPresentation(status ?? 'draft')['color'] as Color;

  IconData _statusIcon(String? status) =>
      kpiStatusPresentation(status ?? 'draft')['icon'] as IconData;

  String _scanStatus(String status) {
    switch (status) {
      case 'clean':
        return 'Lolos pemeriksaan';
      case 'rejected':
        return 'Ditolak scanner';
      case 'needs_migration':
        return 'Perlu pemeriksaan ulang';
      default:
        return 'Menunggu pemeriksaan';
    }
  }
}
