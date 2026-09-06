import 'dart:convert';

import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class ApprovalDetailScreen extends StatefulWidget {
  final String kpiId;

  const ApprovalDetailScreen({super.key, required this.kpiId});

  @override
  State<ApprovalDetailScreen> createState() => _ApprovalDetailScreenState();
}

class _ApprovalDetailScreenState extends State<ApprovalDetailScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _data;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      final res = await ApiService.get('/manager/approval/${widget.kpiId}');
      setState(() {
        _data = res['data'];
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _errorMessage = e.toString().replaceAll('Exception: ', '');
        _isLoading = false;
      });
    }
  }

  Future<void> _approve() async {
    final note = await showOpsTextInputSheet(
      context: context,
      eyebrow: 'Persetujuan KPI',
      title: 'Approve KPI',
      subtitle:
          'Sahkan hasil penilaian. Admin KPI menerbitkan skor karyawan setelah seluruh KPI disahkan.',
      label: 'Catatan approval (opsional)',
      hintText: 'Tambahkan catatan jika diperlukan',
      actionLabel: 'Sahkan KPI',
      maxLines: 3,
    );
    if (note == null) return;

    try {
      final res = await ApiService.post(
        '/manager/approval/${widget.kpiId}/approve',
        {'note': note.trim()},
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'KPI berhasil disetujui!'),
          backgroundColor: AppTheme.primary,
        ),
      );
      Navigator.pop(context, true);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString().replaceAll('Exception: ', '')),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    }
  }

  Future<void> _returnToSpv() async {
    final reason = await showOpsTextInputSheet(
      context: context,
      eyebrow: 'Perlu ditinjau ulang',
      title: 'Kembalikan ke supervisor',
      subtitle: 'Jelaskan bagian KPI yang perlu diperiksa dan diperbaiki.',
      label: 'Alasan pengembalian (wajib)',
      hintText: 'Jelaskan mengapa KPI perlu direview ulang...',
      actionLabel: 'Kembalikan KPI',
      actionColor: AppTheme.statusDanger,
      maxLines: 4,
    );
    if (reason == null || reason.trim().isEmpty) return;

    try {
      final res = await ApiService.post(
        '/manager/approval/${widget.kpiId}/return',
        {'reason': reason.trim()},
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'KPI dikembalikan ke Supervisor.'),
          backgroundColor: AppTheme.statusRevision,
        ),
      );
      Navigator.pop(context, true);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString().replaceAll('Exception: ', '')),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    }
  }

  Future<void> _assessNumeric(
    Map<String, dynamic> item, {
    bool correction = false,
  }) async {
    final note = await showOpsTextInputSheet(
      context: context,
      eyebrow: 'Penilaian Manager',
      title: '${correction ? 'Koreksi' : 'Konfirmasi'} ${item['code']}',
      subtitle:
          'Nilai aktual dihitung dari sumber resmi atau review Supervisor.',
      label: correction
          ? 'Alasan koreksi (wajib)'
          : 'Catatan Manager (opsional)',
      hintText: 'Tambahkan alasan bila ada masalah pada data.',
      helperText:
          'Aktual: ${item['actual_decimal'] ?? '-'} ${item['target_unit'] ?? ''} · Target: ${item['target_value'] ?? '-'} ${item['target_unit'] ?? ''}',
      actionLabel: correction ? 'Tandai perlu koreksi' : 'Konfirmasi hasil',
      maxLines: 3,
    );
    if (note == null) return;

    try {
      final res = await ApiService.post(
        '/manager/approval/${widget.kpiId}/items/${item['id']}/assess',
        {
          'decision': correction ? 'needs_correction' : 'valid',
          'note': note.trim(),
        },
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            res['message'] ?? 'Penilaian Manager berhasil disimpan.',
          ),
          backgroundColor: AppTheme.primary,
        ),
      );
      await _load();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString().replaceAll('Exception: ', '')),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Detail Approval KPI')),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_isLoading) {
      return const OpsScreenLoading(rows: 5);
    }
    if (_errorMessage != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(_errorMessage!),
            const SizedBox(height: 12),
            ElevatedButton(onPressed: _load, child: const Text('Muat Ulang')),
          ],
        ),
      );
    }

    final data = _data!;
    final emp = data['employee'] ?? {};
    final items = (data['items'] as List<dynamic>?) ?? [];
    final explanation = data['calculation_explanation'];

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Employee & period
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
                  emp['name'] ?? 'Karyawan',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 18,
                    color: AppTheme.textInk,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  '${emp['employee_number'] ?? ''} • ${emp['position'] ?? '-'} • ${emp['branch'] ?? '-'}',
                  style: TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
                const SizedBox(height: 8),
                Text(
                  'Periode: ${data['period'] ?? '-'}',
                  style: const TextStyle(fontSize: 13),
                ),
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 6,
                  ),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    '${data['rating_label'] ?? '-'} (${data['final_score'] ?? '-'}) • ${_formatStatus(data['status'])}',
                    style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 12,
                      color: AppTheme.primary,
                    ),
                  ),
                ),
              ],
            ),
          ),

          if (explanation != null) ...[
            const SizedBox(height: 16),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppTheme.parchment,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppTheme.border),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Penjelasan Perhitungan',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    _stringify(explanation),
                    style: TextStyle(fontSize: 12, color: AppTheme.textInk),
                  ),
                ],
              ),
            ),
          ],

          const SizedBox(height: 20),
          Text(
            'Breakdown Indikator',
            style: TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.bold,
              color: AppTheme.textInk,
            ),
          ),
          const SizedBox(height: 12),
          ...items.map((item) {
            final evidences = (item['evidences'] as List<dynamic>?) ?? [];
            final canAssess =
                data['status'] == 'pending_approval' &&
                (data['can_assess'] != false) &&
                item['status'] != 'locked';
            return OpsCard(
              padding: EdgeInsets.zero,
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 3,
                          ),
                          decoration: BoxDecoration(
                            color: AppTheme.primary.withValues(alpha: 0.1),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            item['code'] ?? '',
                            style: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                              color: AppTheme.primary,
                            ),
                          ),
                        ),
                        Text(
                          'Bobot ${item['weight']}%',
                          style: TextStyle(
                            fontSize: 12,
                            color: AppTheme.textMuted,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(
                      item['name'] ?? '',
                      style: TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: AppTheme.textInk,
                      ),
                    ),
                    const SizedBox(height: 10),
                    LayoutBuilder(
                      builder: (context, constraints) {
                        const gap = 12.0;
                        final width = (constraints.maxWidth - gap) / 2;
                        final stats = [
                          _itemStat(
                            'Target',
                            '${item['target_value']} ${item['target_unit']}',
                          ),
                          _itemStat(
                            'Aktual',
                            '${item['actual_decimal']} ${item['target_unit']}',
                          ),
                          _itemStat(
                            'Pencapaian',
                            '${item['achievement_percentage']}%',
                          ),
                          _itemStat('Skor', '${item['weighted_score']}'),
                        ];
                        return Wrap(
                          spacing: gap,
                          runSpacing: gap,
                          children: stats
                              .map(
                                (stat) => SizedBox(width: width, child: stat),
                              )
                              .toList(),
                        );
                      },
                    ),
                    if (evidences.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      ...evidences.map(
                        (e) => Row(
                          children: [
                            Icon(
                              Icons.attach_file_rounded,
                              size: 14,
                              color: AppTheme.textMuted,
                            ),
                            const SizedBox(width: 4),
                            Expanded(
                              child: Text(
                                e['file_name'] ?? '',
                                style: TextStyle(
                                  fontSize: 12,
                                  color: AppTheme.textMuted,
                                ),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                    if (canAssess) ...[
                      const SizedBox(height: 12),
                      SizedBox(
                        width: double.infinity,
                        child: OutlinedButton.icon(
                          icon: const Icon(Icons.edit_note_rounded, size: 18),
                          label: const Text('Konfirmasi hasil'),
                          onPressed: () => _assessNumeric(item),
                        ),
                      ),
                      TextButton.icon(
                        icon: const Icon(Icons.undo_rounded),
                        label: const Text('Tandai indikator perlu koreksi'),
                        onPressed: () => _assessNumeric(item, correction: true),
                      ),
                    ],
                  ],
                ),
              ),
            );
          }),

          const SizedBox(height: 24),
          Row(
            children: [
              Expanded(
                child: ElevatedButton.icon(
                  icon: const Icon(Icons.check_circle_rounded, size: 18),
                  label: const Text('Sahkan KPI'),
                  style: ElevatedButton.styleFrom(
                    minimumSize: const Size(0, 44),
                  ),
                  onPressed: data['can_approve'] == true ? _approve : null,
                ),
              ),
              const SizedBox(width: 8),
              OutlinedButton.icon(
                icon: const Icon(Icons.undo_rounded, size: 16),
                label: const Text('Return'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppTheme.statusDanger,
                  minimumSize: const Size(80, 44),
                ),
                onPressed:
                    (data['available_actions'] as List? ?? const []).contains(
                      'return',
                    )
                    ? _returnToSpv
                    : null,
              ),
            ],
          ),
          const SizedBox(height: 30),
        ],
      ),
    );
  }

  Widget _itemStat(String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: TextStyle(fontSize: 12, color: AppTheme.textMuted)),
        Text(
          value,
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
        ),
      ],
    );
  }

  String _stringify(dynamic v) {
    if (v is String) return v;
    if (v is Map || v is List) {
      return const JsonEncoder.withIndent('  ').convert(v);
    }
    return v.toString();
  }

  String _formatStatus(String? status) {
    switch (status) {
      case 'pending_approval':
        return 'Menunggu Approval';
      case 'approved':
        return 'Disetujui';
      case 'locked':
        return 'Terkunci';
      default:
        return status ?? '-';
    }
  }
}
